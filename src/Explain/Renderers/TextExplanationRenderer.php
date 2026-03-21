<?php
declare(strict_types=1);

namespace Foundry\Explain\Renderers;

use Foundry\Explain\ExplainSupport;
use Foundry\Explain\ExplanationPlan;

final class TextExplanationRenderer implements ExplanationRendererInterface
{
    public function render(ExplanationPlan $plan): string
    {
        $payload = $plan->toArray();
        $lines = [];

        $this->appendSubject($lines, $payload);
        $this->appendSummary($lines, $payload);
        $this->appendExecutionFlow($lines, $payload);
        $this->appendPrimarySections($lines, $payload);
        $this->appendRelationships($lines, $payload);
        $this->appendEmits($lines, $payload);
        $this->appendTriggers($lines, $payload);
        $this->appendExpandedRelationships($lines, $payload);
        $this->appendDiagnostics($lines, $payload);
        $this->appendSuggestedFixes($lines, $payload);
        $this->appendRelatedCommands($lines, $payload);
        $this->appendRelatedDocs($lines, $payload);

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param array<int,string> $lines
     * @param array<string,mixed> $payload
     */
    private function appendSubject(array &$lines, array $payload): void
    {
        $lines[] = 'Subject';
        $lines[] = '  ' . trim((string) ($payload['subject']['label'] ?? $payload['subject']['id'] ?? ''));
        $lines[] = '  kind: ' . trim((string) ($payload['subject']['kind'] ?? ''));
    }

    /**
     * @param array<int,string> $lines
     * @param array<string,mixed> $payload
     */
    private function appendSummary(array &$lines, array $payload): void
    {
        $summary = trim((string) ($payload['summary']['text'] ?? ''));
        if ($summary === '') {
            return;
        }

        $lines[] = '';
        $lines[] = 'Summary';
        $lines[] = '  ' . $summary;
    }

    /**
     * @param array<int,string> $lines
     * @param array<string,mixed> $payload
     */
    private function appendExecutionFlow(array &$lines, array $payload): void
    {
        $flow = is_array($payload['execution_flow'] ?? null) ? $payload['execution_flow'] : [];
        if ($flow === []) {
            return;
        }

        $lines[] = '';
        $lines[] = $this->deep($payload) ? 'Execution Flow (Detailed)' : 'Execution Flow';

        if ($this->deep($payload)) {
            foreach ($this->detailedFlowLines($flow) as $line) {
                $lines[] = $line;
            }

            return;
        }

        $steps = $this->flowSteps($flow);
        if ($steps === []) {
            return;
        }

        $first = true;
        foreach ($steps as $step) {
            $lines[] = '  ' . ($first ? $step : '-> ' . $step);
            $first = false;
        }
    }

    /**
     * @param array<int,string> $lines
     * @param array<string,mixed> $payload
     */
    private function appendPrimarySections(array &$lines, array $payload): void
    {
        foreach ($this->orderedSections((array) ($payload['sections'] ?? [])) as $section) {
            if (!is_array($section)) {
                continue;
            }

            $id = trim((string) ($section['id'] ?? ''));
            $items = is_array($section['items'] ?? null) ? $section['items'] : [];

            $rendered = match ($id) {
                'contracts' => $this->responsibilitiesLines($items),
                'route' => $this->routeLines($items),
                'workflow' => $this->workflowLines($items),
                'event' => $this->eventLines($items),
                'extension' => $this->extensionLines($items),
                'command' => $this->commandLines($items),
                'job' => $this->jobLines($items),
                'schema' => $this->schemaLines($items),
                'pipeline_stage' => $this->pipelineStageLines($items),
                'impact' => $this->impactLines($items),
                'subject' => [],
                default => $this->genericSectionLines((string) ($section['title'] ?? 'Details'), $items),
            };

            foreach ($rendered as $row) {
                $lines[] = $row;
            }
        }
    }

    /**
     * @param array<int,mixed> $sections
     * @return array<int,array<string,mixed>>
     */
    private function orderedSections(array $sections): array
    {
        $rows = [];
        foreach ($sections as $index => $section) {
            if (!is_array($section)) {
                continue;
            }

            $section['_render_index'] = $index;
            $rows[] = $section;
        }

        usort($rows, function (array $left, array $right): int {
            $leftId = (string) ($left['id'] ?? '');
            $rightId = (string) ($right['id'] ?? '');

            return ($this->sectionPriority($leftId) <=> $this->sectionPriority($rightId))
                ?: ((int) ($left['_render_index'] ?? 0) <=> (int) ($right['_render_index'] ?? 0))
                ?: strcmp((string) ($left['title'] ?? ''), (string) ($right['title'] ?? ''));
        });

        foreach ($rows as &$row) {
            unset($row['_render_index']);
        }
        unset($row);

        return $rows;
    }

    /**
     * @param array<int,string> $lines
     * @param array<string,mixed> $payload
     */
    private function appendRelationships(array &$lines, array $payload): void
    {
        $dependsOn = $this->dependencyRows($payload);
        if ($dependsOn !== []) {
            $lines[] = '';
            $lines[] = 'Depends On';
            foreach ($dependsOn as $row) {
                $lines[] = '  ' . $this->rowLabel($row);
            }
        }

        $usedBy = $this->dependentRows($payload);
        if ($usedBy !== []) {
            $lines[] = '';
            $lines[] = 'Used By';
            foreach ($usedBy as $row) {
                $lines[] = '  ' . $this->rowLabel($row);
            }
        }
    }

    /**
     * @param array<int,string> $lines
     * @param array<string,mixed> $payload
     */
    private function appendEmits(array &$lines, array $payload): void
    {
        $emits = $this->emittedItems($payload);
        if ($emits === []) {
            return;
        }

        $lines[] = '';
        $lines[] = 'Emits';
        foreach ($emits as $emit) {
            $lines[] = '  ' . $emit;
        }
    }

    /**
     * @param array<int,string> $lines
     * @param array<string,mixed> $payload
     */
    private function appendTriggers(array &$lines, array $payload): void
    {
        $triggers = $this->triggeredItems($payload);
        if ($triggers === []) {
            return;
        }

        $lines[] = '';
        $lines[] = 'Triggers';
        foreach ($triggers as $trigger) {
            $lines[] = '  ' . $trigger;
        }
    }

    /**
     * @param array<int,string> $lines
     * @param array<string,mixed> $payload
     */
    private function appendExpandedRelationships(array &$lines, array $payload): void
    {
        if (!$this->deep($payload)) {
            return;
        }

        $inbound = $this->rowLabels((array) ($payload['relationships']['depended_on_by'] ?? []));
        $outbound = $this->rowLabels((array) ($payload['relationships']['depends_on'] ?? []));
        $neighborRows = (array) ($payload['relationships']['neighbors'] ?? []);
        $known = array_merge($inbound, $outbound);
        $lateral = [];
        foreach ($this->rowLabels($neighborRows) as $label) {
            if (!in_array($label, $known, true)) {
                $lateral[] = $label;
            }
        }

        if ($inbound === [] && $outbound === [] && $lateral === []) {
            return;
        }

        $lines[] = '';
        $lines[] = 'Graph Relationships (Expanded)';
        if ($inbound !== []) {
            $lines[] = '  inbound:';
            foreach ($inbound as $label) {
                $lines[] = '    ' . $label;
            }
        }
        if ($outbound !== []) {
            $lines[] = '  outbound:';
            foreach ($outbound as $label) {
                $lines[] = '    ' . $label;
            }
        }
        if ($lateral !== []) {
            $lines[] = '  lateral:';
            foreach ($lateral as $label) {
                $lines[] = '    ' . $label;
            }
        }
    }

    /**
     * @param array<int,string> $lines
     * @param array<string,mixed> $payload
     */
    private function appendDiagnostics(array &$lines, array $payload): void
    {
        $items = array_values(array_filter((array) ($payload['diagnostics']['items'] ?? []), 'is_array'));

        $lines[] = '';
        $lines[] = 'Diagnostics';
        if ($items === []) {
            $lines[] = '  OK No issues detected';

            return;
        }

        foreach ($items as $row) {
            $severity = strtoupper(trim((string) ($row['severity'] ?? 'info')));
            $message = trim((string) ($row['message'] ?? $row['code'] ?? ''));
            $lines[] = '  ' . $severity . ' ' . $message;

            if ($this->deep($payload)) {
                $code = trim((string) ($row['code'] ?? ''));
                if ($code !== '') {
                    $lines[] = '    code: ' . $code;
                }
                $why = trim((string) ($row['why_it_matters'] ?? ''));
                if ($why !== '') {
                    $lines[] = '    why: ' . $why;
                }
            }
        }
    }

    /**
     * @param array<int,string> $lines
     * @param array<string,mixed> $payload
     */
    private function appendSuggestedFixes(array &$lines, array $payload): void
    {
        $fixes = [];
        foreach ((array) ($payload['diagnostics']['items'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $fix = trim((string) ($row['suggested_fix'] ?? ''));
            if ($fix !== '') {
                $fixes[] = $fix;
            }
        }

        $fixes = ExplainSupport::orderedUniqueStrings($fixes);
        if ($fixes === []) {
            return;
        }

        $lines[] = '';
        $lines[] = 'Suggested Fixes';
        foreach ($fixes as $fix) {
            $lines[] = '  - ' . $fix;
        }
    }

    /**
     * @param array<int,string> $lines
     * @param array<string,mixed> $payload
     */
    private function appendRelatedCommands(array &$lines, array $payload): void
    {
        $commands = array_values(array_map('strval', (array) ($payload['related_commands'] ?? [])));
        if ($commands === []) {
            return;
        }

        $lines[] = '';
        $lines[] = 'Related Commands';
        foreach ($commands as $command) {
            $lines[] = '  ' . $command;
        }
    }

    /**
     * @param array<int,string> $lines
     * @param array<string,mixed> $payload
     */
    private function appendRelatedDocs(array &$lines, array $payload): void
    {
        $docs = (array) ($payload['related_docs'] ?? []);
        if ($docs === []) {
            return;
        }

        $lines[] = '';
        $lines[] = 'Related Docs';
        foreach ($docs as $row) {
            if (!is_array($row)) {
                continue;
            }

            $path = trim((string) ($row['path'] ?? ''));
            $title = trim((string) ($row['title'] ?? $row['id'] ?? $path));
            $lines[] = '  ' . ($path !== '' ? $path : $title);
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function deep(array $payload): bool
    {
        return (bool) (($payload['summary']['deep'] ?? false) || ($payload['metadata']['options']['deep'] ?? false));
    }

    /**
     * @param array<string,mixed> $flow
     * @return array<int,string>
     */
    private function flowSteps(array $flow): array
    {
        $steps = [];
        $route = trim((string) ($flow['route'] ?? ''));
        if ($route !== '') {
            $steps[] = 'request';
        }

        foreach ((array) ($flow['guards'] ?? []) as $guard) {
            if (!is_array($guard)) {
                continue;
            }

            $steps[] = trim((string) ($guard['type'] ?? $guard['id'] ?? 'guard')) . ' guard';
        }

        $feature = trim((string) (($flow['pipeline']['feature'] ?? null) ?: ''));
        if ($feature !== '') {
            $steps[] = $feature . ' feature action';
        }

        foreach ((array) ($flow['events'] ?? []) as $event) {
            $name = trim((string) ($event['name'] ?? ''));
            if ($name !== '') {
                $steps[] = $name . ' event';
            }
        }

        foreach ((array) ($flow['workflows'] ?? []) as $workflow) {
            if (!is_array($workflow)) {
                continue;
            }

            $steps[] = trim((string) ($workflow['resource'] ?? $workflow['label'] ?? 'workflow')) . ' workflow';
        }

        foreach ((array) ($flow['jobs'] ?? []) as $job) {
            $name = trim((string) ($job['name'] ?? ''));
            if ($name !== '') {
                $steps[] = $name . ' job';
            }
        }

        if ($steps === []) {
            $steps = array_values(array_map('strval', (array) ($flow['steps'] ?? [])));
        }

        return ExplainSupport::orderedUniqueStrings($steps);
    }

    /**
     * @param array<string,mixed> $flow
     * @return array<int,string>
     */
    private function detailedFlowLines(array $flow): array
    {
        $lines = [];
        $index = 1;

        $route = trim((string) ($flow['route'] ?? ''));
        if ($route !== '') {
            $lines[] = '  Stage ' . $index++ . ': request';
            $lines[] = '    - route: ' . $route;
        }

        foreach ((array) ($flow['guards'] ?? []) as $guard) {
            if (!is_array($guard)) {
                continue;
            }

            $lines[] = '  Stage ' . $index++ . ': ' . trim((string) ($guard['type'] ?? $guard['id'] ?? 'guard')) . ' guard';
            foreach (['stage', 'permission', 'strategy'] as $key) {
                $value = trim((string) ($guard[$key] ?? ''));
                if ($value !== '') {
                    $lines[] = '    - ' . $key . ': ' . $value;
                }
            }
        }

        $stages = array_values(array_map('strval', (array) ($flow['stages'] ?? [])));
        if ($stages !== []) {
            $lines[] = '  Stages';
            foreach ($stages as $stage) {
                $lines[] = '    - ' . $stage;
            }
        }

        $feature = trim((string) (($flow['pipeline']['feature'] ?? null) ?: ''));
        if ($feature !== '') {
            $lines[] = '  Stage ' . $index++ . ': feature execution';
            $lines[] = '    - handler: ' . $feature;
        }

        $events = array_values(array_filter(array_map(
            static fn (mixed $row): string => trim((string) (is_array($row) ? ($row['name'] ?? '') : '')),
            (array) ($flow['events'] ?? []),
        ), static fn (string $value): bool => $value !== ''));
        if ($events !== []) {
            $lines[] = '  Stage ' . $index++ . ': event emission';
            foreach ($events as $event) {
                $lines[] = '    - ' . $event;
            }
        }

        $workflows = [];
        foreach ((array) ($flow['workflows'] ?? []) as $workflow) {
            if (!is_array($workflow)) {
                continue;
            }

            $name = trim((string) ($workflow['resource'] ?? $workflow['label'] ?? ''));
            if ($name !== '') {
                $workflows[] = $name;
            }
        }
        if ($workflows !== []) {
            $lines[] = '  Stage ' . $index++ . ': workflow trigger';
            foreach (ExplainSupport::orderedUniqueStrings($workflows) as $workflow) {
                $lines[] = '    - ' . $workflow;
            }
        }

        $jobs = array_values(array_filter(array_map(
            static fn (mixed $row): string => trim((string) (is_array($row) ? ($row['name'] ?? '') : '')),
            (array) ($flow['jobs'] ?? []),
        ), static fn (string $value): bool => $value !== ''));
        if ($jobs !== []) {
            $lines[] = '  Stage ' . $index++ . ': job dispatch';
            foreach (ExplainSupport::orderedUniqueStrings($jobs) as $job) {
                $lines[] = '    - ' . $job;
            }
        }

        return $lines;
    }

    /**
     * @param array<string,mixed> $items
     * @return array<int,string>
     */
    private function responsibilitiesLines(array $items): array
    {
        $lines = [];
        $body = [];

        $description = trim((string) ($items['description'] ?? ''));
        if ($description !== '') {
            $body[] = '  ' . $description;
        }

        $route = $this->routeSummaryValue($items['route'] ?? null);
        if ($route !== null) {
            $body[] = '  route: ' . $route;
        }

        $input = $this->schemaValue($items['input_schema'] ?? null);
        if ($input !== '') {
            $body[] = '  input schema: ' . $input;
        }

        $output = $this->schemaValue($items['output_schema'] ?? null);
        if ($output !== '') {
            $body[] = '  output schema: ' . $output;
        }

        $permissions = $this->stringList((array) ($items['permissions'] ?? []));
        if ($permissions !== []) {
            $body[] = '  permissions: ' . implode(', ', $permissions);
        }

        if ($body === []) {
            return [];
        }

        $lines[] = '';
        $lines[] = 'Responsibilities';

        return array_merge($lines, $body);
    }

    /**
     * @param array<string,mixed> $items
     * @return array<int,string>
     */
    private function routeLines(array $items): array
    {
        $body = [];
        $signature = trim((string) ($items['signature'] ?? ''));
        if ($signature !== '') {
            $body[] = '  signature: ' . $signature;
        }

        $feature = trim((string) ($items['feature'] ?? ''));
        if ($feature !== '') {
            $body[] = '  feature: ' . $feature;
        }

        foreach ((array) ($items['schemas'] ?? []) as $role => $schema) {
            if (is_scalar($schema) && trim((string) $schema) !== '') {
                $body[] = '  ' . (string) $role . ': ' . trim((string) $schema);
            }
        }

        if ($body === []) {
            return [];
        }

        return array_merge(['', 'Route'], $body);
    }

    /**
     * @param array<string,mixed> $items
     * @return array<int,string>
     */
    private function workflowLines(array $items): array
    {
        $body = [];
        $states = $this->stringList((array) ($items['states'] ?? []));
        if ($states !== []) {
            $body[] = '  states: ' . implode(', ', $states);
        }

        $transitionNames = [];
        foreach ((array) ($items['transitions'] ?? []) as $name => $transition) {
            $transitionNames[] = (string) $name;
        }
        $transitionNames = $this->stringList($transitionNames);
        if ($transitionNames !== []) {
            $body[] = '  transitions: ' . implode(', ', $transitionNames);
        }

        if ($body === []) {
            return [];
        }

        return array_merge(['', 'Logic'], $body);
    }

    /**
     * @param array<string,mixed> $items
     * @return array<int,string>
     */
    private function eventLines(array $items): array
    {
        $body = [];
        $emitters = $this->stringList((array) ($items['emitters'] ?? []));
        if ($emitters !== []) {
            $body[] = '  emitters: ' . implode(', ', $emitters);
        }

        $subscribers = $this->stringList((array) ($items['subscribers'] ?? []));
        if ($subscribers !== []) {
            $body[] = '  subscribers: ' . implode(', ', $subscribers);
        }

        if ($body === []) {
            return [];
        }

        return array_merge(['', 'Event'], $body);
    }

    /**
     * @param array<string,mixed> $items
     * @return array<int,string>
     */
    private function extensionLines(array $items): array
    {
        $body = [];
        $version = trim((string) ($items['version'] ?? ''));
        if ($version !== '') {
            $body[] = '  version: ' . $version;
        }

        $description = trim((string) ($items['description'] ?? ''));
        if ($description !== '') {
            $body[] = '  ' . $description;
        }

        $packs = $this->stringList((array) ($items['packs'] ?? []));
        if ($packs !== []) {
            $body[] = '  packs: ' . implode(', ', $packs);
        }

        $capabilities = $this->stringList((array) (($items['provides']['capabilities'] ?? []) ?: []));
        if ($capabilities !== []) {
            $body[] = '  capabilities: ' . implode(', ', $capabilities);
        }

        if ($body === []) {
            return [];
        }

        return array_merge(['', 'Provides'], $body);
    }

    /**
     * @param array<string,mixed> $items
     * @return array<int,string>
     */
    private function commandLines(array $items): array
    {
        $body = [];
        foreach (['usage', 'stability', 'availability', 'classification'] as $key) {
            $value = trim((string) ($items[$key] ?? ''));
            if ($value !== '') {
                $body[] = '  ' . $key . ': ' . $value;
            }
        }

        if ($body === []) {
            return [];
        }

        return array_merge(['', 'Command'], $body);
    }

    /**
     * @param array<string,mixed> $items
     * @return array<int,string>
     */
    private function jobLines(array $items): array
    {
        $body = [];
        $features = $this->stringList((array) ($items['features'] ?? []));
        if ($features !== []) {
            $body[] = '  features: ' . implode(', ', $features);
        }

        $definitions = array_keys(array_filter((array) ($items['definitions'] ?? []), 'is_array'));
        $definitions = $this->stringList($definitions);
        if ($definitions !== []) {
            $body[] = '  definitions: ' . implode(', ', $definitions);
        }

        if ($body === []) {
            return [];
        }

        return array_merge(['', 'Job'], $body);
    }

    /**
     * @param array<string,mixed> $items
     * @return array<int,string>
     */
    private function schemaLines(array $items): array
    {
        $body = [];
        foreach (['path', 'role', 'feature'] as $key) {
            $value = trim((string) ($items[$key] ?? ''));
            if ($value !== '') {
                $body[] = '  ' . $key . ': ' . $value;
            }
        }

        $properties = array_keys(array_filter((array) (($items['document']['properties'] ?? []) ?: []), 'is_array'));
        $properties = $this->stringList($properties);
        if ($properties !== []) {
            $body[] = '  fields: ' . implode(', ', $properties);
        }

        if ($body === []) {
            return [];
        }

        return array_merge(['', 'Schema Interaction'], $body);
    }

    /**
     * @param array<string,mixed> $items
     * @return array<int,string>
     */
    private function pipelineStageLines(array $items): array
    {
        $body = [];
        $order = $this->stringList((array) ($items['order'] ?? []));
        if ($order !== []) {
            $body[] = '  order: ' . implode(' -> ', $order);
        }

        if ($body === []) {
            return [];
        }

        return array_merge(['', 'Pipeline Stage'], $body);
    }

    /**
     * @param array<string,mixed> $items
     * @return array<int,string>
     */
    private function impactLines(array $items): array
    {
        $body = [];
        $risk = trim((string) ($items['risk'] ?? ''));
        if ($risk !== '') {
            $body[] = '  risk: ' . $risk;
        }

        foreach (['affected_features', 'affected_routes', 'affected_events', 'affected_jobs', 'affected_projections'] as $key) {
            $values = $this->stringList((array) ($items[$key] ?? []));
            if ($values !== []) {
                $body[] = '  ' . str_replace('_', ' ', $key) . ': ' . implode(', ', $values);
            }
        }

        if ($body === []) {
            return [];
        }

        return array_merge(['', 'Impact'], $body);
    }

    /**
     * @param array<string,mixed> $items
     * @return array<int,string>
     */
    private function genericSectionLines(string $title, array $items): array
    {
        $body = [];
        foreach ($items as $key => $value) {
            $formatted = $this->formatValue($value);
            if ($formatted !== '') {
                $body[] = '  ' . str_replace('_', ' ', (string) $key) . ': ' . $formatted;
            }
        }

        if ($body === []) {
            return [];
        }

        return array_merge(['', $title], $body);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,array<string,mixed>>
     */
    private function dependencyRows(array $payload): array
    {
        $rows = [];
        foreach ((array) ($payload['relationships']['depends_on'] ?? []) as $row) {
            if (!is_array($row) || $this->isOutcomeEdge((string) ($row['edge_type'] ?? ''))) {
                continue;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,array<string,mixed>>
     */
    private function dependentRows(array $payload): array
    {
        $rows = [];
        foreach ((array) ($payload['relationships']['depended_on_by'] ?? []) as $row) {
            if (!is_array($row) || $this->isInternalEdge((string) ($row['edge_type'] ?? ''))) {
                continue;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,string>
     */
    private function emittedItems(array $payload): array
    {
        $values = [];
        foreach ((array) ($payload['execution_flow']['events'] ?? []) as $event) {
            $name = trim((string) (is_array($event) ? ($event['name'] ?? '') : ''));
            if ($name !== '') {
                $values[] = 'event: ' . $name;
            }
        }

        foreach ((array) ($payload['sections'] ?? []) as $section) {
            if (!is_array($section) || (string) ($section['id'] ?? '') !== 'workflow') {
                continue;
            }

            foreach ((array) ($section['items']['transitions'] ?? []) as $transition) {
                if (!is_array($transition)) {
                    continue;
                }
                foreach ((array) ($transition['emit'] ?? []) as $event) {
                    $name = trim((string) $event);
                    if ($name !== '') {
                        $values[] = 'event: ' . $name;
                    }
                }
            }
        }

        return ExplainSupport::orderedUniqueStrings($values);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,string>
     */
    private function triggeredItems(array $payload): array
    {
        $values = [];
        foreach ((array) ($payload['execution_flow']['workflows'] ?? []) as $workflow) {
            if (!is_array($workflow)) {
                continue;
            }

            $name = trim((string) ($workflow['resource'] ?? $workflow['label'] ?? ''));
            if ($name !== '') {
                $values[] = 'workflow: ' . $name;
            }
        }

        foreach ((array) ($payload['execution_flow']['jobs'] ?? []) as $job) {
            $name = trim((string) (is_array($job) ? ($job['name'] ?? '') : ''));
            if ($name !== '') {
                $values[] = 'job: ' . $name;
            }
        }

        foreach ((array) ($payload['sections'] ?? []) as $section) {
            if (!is_array($section) || (string) ($section['id'] ?? '') !== 'event') {
                continue;
            }

            foreach ((array) ($section['items']['workflows'] ?? []) as $workflow) {
                if (!is_array($workflow)) {
                    continue;
                }

                $name = trim((string) ($workflow['resource'] ?? $workflow['label'] ?? ''));
                if ($name !== '') {
                    $values[] = 'workflow: ' . $name;
                }
            }
        }

        return ExplainSupport::orderedUniqueStrings($values);
    }

    /**
     * @param array<int,mixed> $rows
     * @return array<int,string>
     */
    private function rowLabels(array $rows): array
    {
        $labels = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $labels[] = $this->rowLabel($row);
        }

        return ExplainSupport::orderedUniqueStrings($labels);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function rowLabel(array $row): string
    {
        $kind = trim((string) ($row['kind'] ?? ''));
        $label = trim((string) ($row['label'] ?? $row['id'] ?? ''));

        return $kind !== '' ? $kind . ': ' . $label : $label;
    }

    private function isOutcomeEdge(string $edgeType): bool
    {
        return in_array($edgeType, [
            'feature_to_route',
            'feature_to_event_emit',
            'feature_to_job_dispatch',
            'feature_to_input_schema',
            'feature_to_output_schema',
            'feature_to_permission',
            'feature_to_execution_plan',
            'route_to_execution_plan',
            'execution_plan_to_guard',
            'execution_plan_to_stage',
            'execution_plan_to_interceptor',
            'guard_to_pipeline_stage',
            'interceptor_to_pipeline_stage',
            'pipeline_stage_next',
            'workflow_to_event_emit',
        ], true);
    }

    private function isInternalEdge(string $edgeType): bool
    {
        return in_array($edgeType, [
            'feature_to_execution_plan',
            'route_to_execution_plan',
            'execution_plan_to_guard',
            'execution_plan_to_stage',
            'execution_plan_to_interceptor',
            'guard_to_pipeline_stage',
            'interceptor_to_pipeline_stage',
            'pipeline_stage_next',
        ], true);
    }

    private function sectionPriority(string $id): int
    {
        return match ($id) {
            'subject' => 0,
            'contracts' => 10,
            'route' => 20,
            'workflow' => 30,
            'event' => 40,
            'extension' => 50,
            'command' => 60,
            'job' => 70,
            'schema' => 80,
            'pipeline_stage' => 90,
            'impact' => 900,
            default => 800,
        };
    }

    private function routeSummaryValue(mixed $value): ?string
    {
        if (!is_array($value)) {
            return null;
        }

        $method = strtoupper(trim((string) ($value['method'] ?? '')));
        $path = trim((string) ($value['path'] ?? ''));
        $summary = trim($method . ' ' . $path);

        return $summary !== '' ? $summary : null;
    }

    private function schemaValue(mixed $value): string
    {
        if (is_scalar($value)) {
            return trim((string) $value);
        }

        if (!is_array($value)) {
            return '';
        }

        foreach (['path', 'schema', 'id', 'label', 'name'] as $key) {
            $resolved = trim((string) ($value[$key] ?? ''));
            if ($resolved !== '') {
                return $resolved;
            }
        }

        return '';
    }

    /**
     * @param array<int,mixed> $values
     * @return array<int,string>
     */
    private function stringList(array $values): array
    {
        return ExplainSupport::orderedUniqueStrings(array_values(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $values,
        )));
    }

    private function formatValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return trim((string) $value);
        }

        if (!is_array($value) || $value === []) {
            return '';
        }

        $items = [];
        foreach ($value as $key => $item) {
            if (is_scalar($item)) {
                $rendered = trim((string) $item);
            } elseif (is_array($item)) {
                $rendered = trim((string) ($item['label'] ?? $item['name'] ?? $item['resource'] ?? $item['id'] ?? ''));
            } else {
                $rendered = '';
            }

            if ($rendered === '') {
                continue;
            }

            $items[] = array_is_list($value) ? $rendered : (string) $key . '=' . $rendered;
        }

        return implode(', ', $items);
    }
}
