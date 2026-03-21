<?php
declare(strict_types=1);

namespace Foundry\Explain\Renderers;

use Foundry\Explain\ExplainSupport;
use Foundry\Explain\ExplanationPlan;

final class MarkdownExplanationRenderer implements ExplanationRendererInterface
{
    public function render(ExplanationPlan $plan): string
    {
        $payload = $plan->toArray();
        $lines = [
            '## ' . trim((string) ($payload['subject']['label'] ?? $payload['subject']['id'] ?? '')),
            '',
            '**Type:** ' . trim((string) ($payload['subject']['kind'] ?? '')),
        ];

        $this->appendSummary($lines, $payload);
        $this->appendExecutionFlow($lines, $payload);
        $this->appendPrimarySections($lines, $payload);
        $this->appendRelationshipSection($lines, 'Dependencies', $this->dependencyLabels($payload));
        $this->appendRelationshipSection($lines, 'Used By', $this->dependentLabels($payload));
        $this->appendRelationshipSection($lines, 'Emits', $this->emittedItems($payload));
        $this->appendRelationshipSection($lines, 'Triggers', $this->triggeredItems($payload));
        $this->appendExpandedRelationships($lines, $payload);
        $this->appendDiagnostics($lines, $payload);
        $this->appendSuggestedFixes($lines, $payload);
        $this->appendCommands($lines, $payload);
        $this->appendDocs($lines, $payload);

        return implode(PHP_EOL, $lines);
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
        $lines[] = '### Summary';
        $lines[] = $summary;
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
        $lines[] = '### ' . ($this->deep($payload) ? 'Execution Flow (Detailed)' : 'Execution Flow');

        if ($this->deep($payload)) {
            foreach ($this->detailedFlowItems($flow) as $item) {
                $lines[] = '- ' . $item;
            }

            return;
        }

        foreach ($this->flowSteps($flow) as $step) {
            $lines[] = '- ' . $step;
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

            [$title, $rows] = match ($id) {
                'contracts' => ['Responsibilities', $this->responsibilitiesItems($items)],
                'route' => ['Route', $this->routeItems($items)],
                'workflow' => ['Logic', $this->workflowItems($items)],
                'event' => ['Event', $this->eventItems($items)],
                'extension' => ['Provides', $this->extensionItems($items)],
                'command' => ['Command', $this->commandItems($items)],
                'job' => ['Job', $this->jobItems($items)],
                'schema' => ['Schema Interaction', $this->schemaItems($items)],
                'pipeline_stage' => ['Pipeline Stage', $this->pipelineStageItems($items)],
                'impact' => ['Impact', $this->impactItems($items)],
                'subject' => ['', []],
                default => [(string) ($section['title'] ?? 'Details'), $this->genericItems($items)],
            };

            if ($title === '' || $rows === []) {
                continue;
            }

            $lines[] = '';
            $lines[] = '### ' . $title;
            foreach ($rows as $row) {
                $lines[] = '- ' . $row;
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
     * @param array<int,string> $items
     */
    private function appendRelationshipSection(array &$lines, string $title, array $items): void
    {
        if ($items === []) {
            return;
        }

        $lines[] = '';
        $lines[] = '### ' . $title;
        foreach ($items as $item) {
            $lines[] = '- ' . $item;
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

        $inbound = $this->dependentLabels($payload, includeInternal: true);
        $outbound = $this->dependencyLabels($payload, includeInternal: true);
        $neighborLabels = $this->rowLabels((array) ($payload['relationships']['neighbors'] ?? []));
        $lateral = [];
        foreach ($neighborLabels as $label) {
            if (!in_array($label, $inbound, true) && !in_array($label, $outbound, true)) {
                $lateral[] = $label;
            }
        }

        if ($inbound === [] && $outbound === [] && $lateral === []) {
            return;
        }

        $lines[] = '';
        $lines[] = '### Graph Relationships';
        foreach ($inbound as $label) {
            $lines[] = '- inbound: ' . $label;
        }
        foreach ($outbound as $label) {
            $lines[] = '- outbound: ' . $label;
        }
        foreach ($lateral as $label) {
            $lines[] = '- lateral: ' . $label;
        }
    }

    /**
     * @param array<int,string> $lines
     * @param array<string,mixed> $payload
     */
    private function appendDiagnostics(array &$lines, array $payload): void
    {
        $lines[] = '';
        $lines[] = '### Diagnostics';

        $items = array_values(array_filter((array) ($payload['diagnostics']['items'] ?? []), 'is_array'));
        if ($items === []) {
            $lines[] = 'No issues detected.';

            return;
        }

        foreach ($items as $row) {
            $severity = strtoupper(trim((string) ($row['severity'] ?? 'info')));
            $message = trim((string) ($row['message'] ?? $row['code'] ?? ''));
            $lines[] = '- ' . $severity . ': ' . $message;
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
        $lines[] = '### Suggested Fixes';
        foreach ($fixes as $fix) {
            $lines[] = '- ' . $fix;
        }
    }

    /**
     * @param array<int,string> $lines
     * @param array<string,mixed> $payload
     */
    private function appendCommands(array &$lines, array $payload): void
    {
        $commands = array_values(array_map('strval', (array) ($payload['related_commands'] ?? [])));
        if ($commands === []) {
            return;
        }

        $lines[] = '';
        $lines[] = '### Related Commands';
        foreach ($commands as $command) {
            $lines[] = '- `' . $command . '`';
        }
    }

    /**
     * @param array<int,string> $lines
     * @param array<string,mixed> $payload
     */
    private function appendDocs(array &$lines, array $payload): void
    {
        $docs = array_values(array_filter((array) ($payload['related_docs'] ?? []), 'is_array'));
        if ($docs === []) {
            return;
        }

        $lines[] = '';
        $lines[] = '### Related Docs';
        foreach ($docs as $row) {
            $path = trim((string) ($row['path'] ?? ''));
            $title = trim((string) ($row['title'] ?? $row['id'] ?? $path));
            $lines[] = '- ' . ($path !== '' ? '[' . $title . '](' . $path . ')' : $title);
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
        if (trim((string) ($flow['route'] ?? '')) !== '') {
            $steps[] = 'request';
        }

        foreach ((array) ($flow['guards'] ?? []) as $guard) {
            if (is_array($guard)) {
                $steps[] = trim((string) ($guard['type'] ?? $guard['id'] ?? 'guard')) . ' guard';
            }
        }

        $feature = trim((string) (($flow['pipeline']['feature'] ?? null) ?: ''));
        if ($feature !== '') {
            $steps[] = $feature . ' feature action';
        }

        foreach ((array) ($flow['events'] ?? []) as $event) {
            $name = trim((string) (is_array($event) ? ($event['name'] ?? '') : ''));
            if ($name !== '') {
                $steps[] = $name . ' event';
            }
        }

        foreach ((array) ($flow['workflows'] ?? []) as $workflow) {
            if (is_array($workflow)) {
                $steps[] = trim((string) ($workflow['resource'] ?? $workflow['label'] ?? 'workflow')) . ' workflow';
            }
        }

        foreach ((array) ($flow['jobs'] ?? []) as $job) {
            $name = trim((string) (is_array($job) ? ($job['name'] ?? '') : ''));
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
    private function detailedFlowItems(array $flow): array
    {
        $items = [];
        $route = trim((string) ($flow['route'] ?? ''));
        if ($route !== '') {
            $items[] = 'Stage 1: request (' . $route . ')';
        }

        $stageIndex = $route !== '' ? 2 : 1;
        foreach ((array) ($flow['guards'] ?? []) as $guard) {
            if (!is_array($guard)) {
                continue;
            }

            $items[] = 'Stage ' . $stageIndex++ . ': ' . trim((string) ($guard['type'] ?? $guard['id'] ?? 'guard')) . ' guard';
        }

        $feature = trim((string) (($flow['pipeline']['feature'] ?? null) ?: ''));
        if ($feature !== '') {
            $items[] = 'Stage ' . $stageIndex++ . ': feature execution (' . $feature . ')';
        }

        foreach ((array) ($flow['events'] ?? []) as $event) {
            $name = trim((string) (is_array($event) ? ($event['name'] ?? '') : ''));
            if ($name !== '') {
                $items[] = 'event emission: ' . $name;
            }
        }

        foreach ((array) ($flow['workflows'] ?? []) as $workflow) {
            if (!is_array($workflow)) {
                continue;
            }

            $name = trim((string) ($workflow['resource'] ?? $workflow['label'] ?? ''));
            if ($name !== '') {
                $items[] = 'workflow trigger: ' . $name;
            }
        }

        foreach ((array) ($flow['jobs'] ?? []) as $job) {
            $name = trim((string) (is_array($job) ? ($job['name'] ?? '') : ''));
            if ($name !== '') {
                $items[] = 'job dispatch: ' . $name;
            }
        }

        return ExplainSupport::orderedUniqueStrings($items);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,string>
     */
    private function dependencyLabels(array $payload, bool $includeInternal = false): array
    {
        $labels = [];
        foreach ((array) ($payload['relationships']['depends_on'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $edgeType = (string) ($row['edge_type'] ?? '');
            if (!$includeInternal && $this->isOutcomeEdge($edgeType)) {
                continue;
            }

            $labels[] = $this->rowLabel($row);
        }

        return ExplainSupport::orderedUniqueStrings($labels);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,string>
     */
    private function dependentLabels(array $payload, bool $includeInternal = false): array
    {
        $labels = [];
        foreach ((array) ($payload['relationships']['depended_on_by'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $edgeType = (string) ($row['edge_type'] ?? '');
            if (!$includeInternal && $this->isInternalEdge($edgeType)) {
                continue;
            }

            $labels[] = $this->rowLabel($row);
        }

        return ExplainSupport::orderedUniqueStrings($labels);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,string>
     */
    private function emittedItems(array $payload): array
    {
        $items = [];
        foreach ((array) ($payload['execution_flow']['events'] ?? []) as $event) {
            $name = trim((string) (is_array($event) ? ($event['name'] ?? '') : ''));
            if ($name !== '') {
                $items[] = 'event: ' . $name;
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
                        $items[] = 'event: ' . $name;
                    }
                }
            }
        }

        return ExplainSupport::orderedUniqueStrings($items);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,string>
     */
    private function triggeredItems(array $payload): array
    {
        $items = [];
        foreach ((array) ($payload['execution_flow']['workflows'] ?? []) as $workflow) {
            if (!is_array($workflow)) {
                continue;
            }
            $name = trim((string) ($workflow['resource'] ?? $workflow['label'] ?? ''));
            if ($name !== '') {
                $items[] = 'workflow: ' . $name;
            }
        }

        foreach ((array) ($payload['execution_flow']['jobs'] ?? []) as $job) {
            $name = trim((string) (is_array($job) ? ($job['name'] ?? '') : ''));
            if ($name !== '') {
                $items[] = 'job: ' . $name;
            }
        }

        return ExplainSupport::orderedUniqueStrings($items);
    }

    /**
     * @param array<int,mixed> $rows
     * @return array<int,string>
     */
    private function rowLabels(array $rows): array
    {
        $labels = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $labels[] = $this->rowLabel($row);
            }
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

    /**
     * @param array<string,mixed> $items
     * @return array<int,string>
     */
    private function responsibilitiesItems(array $items): array
    {
        $rows = [];
        $description = trim((string) ($items['description'] ?? ''));
        if ($description !== '') {
            $rows[] = $description;
        }

        if (is_array($items['route'] ?? null)) {
            $method = strtoupper(trim((string) ($items['route']['method'] ?? '')));
            $path = trim((string) ($items['route']['path'] ?? ''));
            $route = trim($method . ' ' . $path);
            if ($route !== '') {
                $rows[] = 'route: ' . $route;
            }
        }

        $permissions = ExplainSupport::orderedUniqueStrings(array_values(array_map('strval', (array) ($items['permissions'] ?? []))));
        if ($permissions !== []) {
            $rows[] = 'permissions: ' . implode(', ', $permissions);
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $items
     * @return array<int,string>
     */
    private function routeItems(array $items): array
    {
        $rows = [];
        foreach (['signature', 'feature'] as $key) {
            $value = trim((string) ($items[$key] ?? ''));
            if ($value !== '') {
                $rows[] = $key . ': ' . $value;
            }
        }

        foreach ((array) ($items['schemas'] ?? []) as $role => $path) {
            if (is_scalar($path) && trim((string) $path) !== '') {
                $rows[] = (string) $role . ': ' . trim((string) $path);
            }
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $items
     * @return array<int,string>
     */
    private function workflowItems(array $items): array
    {
        $rows = [];
        $states = ExplainSupport::orderedUniqueStrings(array_values(array_map('strval', (array) ($items['states'] ?? []))));
        if ($states !== []) {
            $rows[] = 'states: ' . implode(', ', $states);
        }

        $transitions = ExplainSupport::orderedUniqueStrings(array_values(array_map(
            static fn (string|int $name): string => (string) $name,
            array_keys((array) ($items['transitions'] ?? [])),
        )));
        if ($transitions !== []) {
            $rows[] = 'transitions: ' . implode(', ', $transitions);
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $items
     * @return array<int,string>
     */
    private function eventItems(array $items): array
    {
        $rows = [];
        $emitters = ExplainSupport::orderedUniqueStrings(array_values(array_map('strval', (array) ($items['emitters'] ?? []))));
        if ($emitters !== []) {
            $rows[] = 'emitters: ' . implode(', ', $emitters);
        }
        $subscribers = ExplainSupport::orderedUniqueStrings(array_values(array_map('strval', (array) ($items['subscribers'] ?? []))));
        if ($subscribers !== []) {
            $rows[] = 'subscribers: ' . implode(', ', $subscribers);
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $items
     * @return array<int,string>
     */
    private function extensionItems(array $items): array
    {
        $rows = [];
        foreach (['version', 'description'] as $key) {
            $value = trim((string) ($items[$key] ?? ''));
            if ($value !== '') {
                $rows[] = $key . ': ' . $value;
            }
        }

        $packs = ExplainSupport::orderedUniqueStrings(array_values(array_map('strval', (array) ($items['packs'] ?? []))));
        if ($packs !== []) {
            $rows[] = 'packs: ' . implode(', ', $packs);
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $items
     * @return array<int,string>
     */
    private function commandItems(array $items): array
    {
        $rows = [];
        foreach (['usage', 'stability', 'availability', 'classification'] as $key) {
            $value = trim((string) ($items[$key] ?? ''));
            if ($value !== '') {
                $rows[] = $key . ': ' . $value;
            }
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $items
     * @return array<int,string>
     */
    private function jobItems(array $items): array
    {
        $rows = [];
        $features = ExplainSupport::orderedUniqueStrings(array_values(array_map('strval', (array) ($items['features'] ?? []))));
        if ($features !== []) {
            $rows[] = 'features: ' . implode(', ', $features);
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $items
     * @return array<int,string>
     */
    private function schemaItems(array $items): array
    {
        $rows = [];
        foreach (['path', 'role', 'feature'] as $key) {
            $value = trim((string) ($items[$key] ?? ''));
            if ($value !== '') {
                $rows[] = $key . ': ' . $value;
            }
        }

        $properties = ExplainSupport::orderedUniqueStrings(array_values(array_map(
            static fn (string|int $name): string => (string) $name,
            array_keys((array) (($items['document']['properties'] ?? []) ?: [])),
        )));
        if ($properties !== []) {
            $rows[] = 'fields: ' . implode(', ', $properties);
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $items
     * @return array<int,string>
     */
    private function pipelineStageItems(array $items): array
    {
        $order = ExplainSupport::orderedUniqueStrings(array_values(array_map('strval', (array) ($items['order'] ?? []))));

        return $order !== [] ? ['order: ' . implode(' -> ', $order)] : [];
    }

    /**
     * @param array<string,mixed> $items
     * @return array<int,string>
     */
    private function impactItems(array $items): array
    {
        $rows = [];
        $risk = trim((string) ($items['risk'] ?? ''));
        if ($risk !== '') {
            $rows[] = 'risk: ' . $risk;
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $items
     * @return array<int,string>
     */
    private function genericItems(array $items): array
    {
        $rows = [];
        foreach ($items as $key => $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                $rows[] = (string) $key . ': ' . trim((string) $value);
            }
        }

        return $rows;
    }
}
