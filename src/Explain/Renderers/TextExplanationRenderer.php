<?php
declare(strict_types=1);

namespace Foundry\Explain\Renderers;

use Foundry\Explain\ExplanationPlan;

final class TextExplanationRenderer implements ExplanationRendererInterface
{
    /**
     * @var array<int,string>
     */
    private const CANONICAL_SECTION_IDS = [
        'subject',
        'summary',
        'responsibilities',
        'execution_flow',
        'dependencies',
        'dependents',
        'emits',
        'triggers',
        'permissions',
        'schema_interaction',
        'graph_relationships',
        'related_commands',
        'related_docs',
        'diagnostics',
        'suggested_fixes',
    ];

    public function render(ExplanationPlan $plan): string
    {
        $payload = $plan->toArray();
        $lines = [];

        foreach ($this->sectionOrder($payload) as $sectionId) {
            $this->appendSection($lines, $payload, $sectionId);
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param array<int,string> $lines
     * @param array<string,mixed> $payload
     */
    private function appendSection(array &$lines, array $payload, string $sectionId): void
    {
        switch ($sectionId) {
            case 'subject':
                $this->appendSubject($lines, $payload);
                return;
            case 'summary':
                $this->appendSummary($lines, $payload);
                return;
            case 'responsibilities':
                $this->appendStringItems($lines, 'Responsibilities', (array) ($payload['responsibilities']['items'] ?? []));
                return;
            case 'execution_flow':
                $this->appendExecutionFlow($lines, $payload);
                return;
            case 'dependencies':
                $this->appendRows($lines, 'Depends On', (array) ($payload['relationships']['dependsOn']['items'] ?? []));
                return;
            case 'dependents':
                $this->appendRows($lines, 'Used By', (array) ($payload['relationships']['usedBy']['items'] ?? []));
                return;
            case 'emits':
                $this->appendRows($lines, 'Emits', (array) ($payload['emits']['items'] ?? []));
                return;
            case 'triggers':
                $this->appendRows($lines, 'Triggers', (array) ($payload['triggers']['items'] ?? []));
                return;
            case 'permissions':
                $this->appendPermissions($lines, $payload);
                return;
            case 'schema_interaction':
                $this->appendSchemaInteraction($lines, $payload);
                return;
            case 'graph_relationships':
                $this->appendGraphRelationships($lines, $payload);
                return;
            case 'related_commands':
                $this->appendStringItems($lines, 'Related Commands', (array) ($payload['relatedCommands'] ?? []));
                return;
            case 'related_docs':
                $this->appendDocs($lines, $payload);
                return;
            case 'diagnostics':
                $this->appendDiagnostics($lines, $payload);
                return;
            case 'suggested_fixes':
                $this->appendStringItems($lines, 'Suggested Fixes', (array) ($payload['suggestedFixes'] ?? []), bullet: true);
                return;
            default:
                $this->appendExtraSection($lines, $payload, $sectionId);
                return;
        }
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

        $this->blankLine($lines);
        $lines[] = 'Summary';
        $lines[] = '  ' . $summary;
    }

    /**
     * @param array<int,string> $lines
     * @param array<string,mixed> $payload
     */
    private function appendExecutionFlow(array &$lines, array $payload): void
    {
        $entries = array_values(array_filter((array) ($payload['executionFlow']['entries'] ?? []), 'is_array'));
        if ($entries === []) {
            return;
        }

        $this->blankLine($lines);
        $lines[] = $this->deep($payload) ? 'Execution Flow (Detailed)' : 'Execution Flow';

        if ($this->deep($payload)) {
            foreach ($entries as $index => $entry) {
                $lines[] = '  Stage ' . ($index + 1) . ': ' . $this->entryLabel($entry);
                foreach ($this->entryDetails($entry) as $detail) {
                    $lines[] = '    - ' . $detail;
                }
            }

            return;
        }

        $first = true;
        foreach ($entries as $entry) {
            $lines[] = '  ' . ($first ? $this->entryLabel($entry) : '-> ' . $this->entryLabel($entry));
            $first = false;
        }
    }

    /**
     * @param array<int,string> $lines
     * @param array<int,mixed> $items
     */
    private function appendStringItems(array &$lines, string $title, array $items, bool $bullet = false): void
    {
        $rows = array_values(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            $items,
        ), static fn (string $item): bool => $item !== ''));
        if ($rows === []) {
            return;
        }

        $this->blankLine($lines);
        $lines[] = $title;
        foreach ($rows as $row) {
            $lines[] = '  ' . ($bullet ? '- ' : '') . $row;
        }
    }

    /**
     * @param array<int,string> $lines
     * @param array<int,mixed> $rows
     */
    private function appendRows(array &$lines, string $title, array $rows): void
    {
        $items = array_values(array_filter($rows, 'is_array'));
        if ($items === []) {
            return;
        }

        $this->blankLine($lines);
        $lines[] = $title;
        foreach ($items as $row) {
            $lines[] = '  ' . $this->rowLabel($row);
        }
    }

    /**
     * @param array<int,string> $lines
     * @param array<string,mixed> $payload
     */
    private function appendPermissions(array &$lines, array $payload): void
    {
        $permissions = is_array($payload['permissions'] ?? null) ? $payload['permissions'] : [];
        $required = array_values(array_filter(array_map('strval', (array) ($permissions['required'] ?? []))));
        $enforcedBy = array_values(array_filter((array) ($permissions['enforced_by'] ?? []), 'is_array'));
        $definedIn = array_values(array_filter((array) ($permissions['defined_in'] ?? []), 'is_array'));
        $missing = array_values(array_filter(array_map('strval', (array) ($permissions['missing'] ?? []))));

        if ($required === [] && $enforcedBy === [] && $definedIn === [] && $missing === []) {
            return;
        }

        $this->blankLine($lines);
        $lines[] = 'Permissions';
        foreach ($required as $permission) {
            $lines[] = '  ' . $permission;
        }
        foreach ($definedIn as $row) {
            $permission = trim((string) ($row['permission'] ?? ''));
            $source = trim((string) ($row['source'] ?? ''));
            if ($permission !== '' && $source !== '') {
                $lines[] = '    defined in: ' . $source;
            }
        }
        foreach ($enforcedBy as $row) {
            $guard = trim((string) ($row['guard'] ?? ''));
            $stage = trim((string) ($row['stage'] ?? ''));
            if ($guard !== '') {
                $lines[] = '    enforced by: ' . $guard . ($stage !== '' ? ' @ ' . $stage : '');
            }
        }
        foreach ($missing as $permission) {
            $lines[] = '    missing: ' . $permission;
        }
    }

    /**
     * @param array<int,string> $lines
     * @param array<string,mixed> $payload
     */
    private function appendSchemaInteraction(array &$lines, array $payload): void
    {
        $interaction = is_array($payload['schemaInteraction'] ?? null) ? $payload['schemaInteraction'] : [];
        $reads = array_values(array_filter((array) ($interaction['reads'] ?? []), 'is_array'));
        $writes = array_values(array_filter((array) ($interaction['writes'] ?? []), 'is_array'));
        $fields = array_values(array_filter((array) ($interaction['fields'] ?? []), 'is_array'));
        $subject = is_array($interaction['subject'] ?? null) ? $interaction['subject'] : null;

        if ($reads === [] && $writes === [] && $fields === [] && $subject === null) {
            return;
        }

        $this->blankLine($lines);
        $lines[] = 'Schema Interaction';
        if ($subject !== null) {
            $lines[] = '  schema: ' . trim((string) ($subject['path'] ?? $subject['label'] ?? 'schema'));
        }
        foreach ($reads as $row) {
            $lines[] = '  reads: ' . $this->rowLabel($row);
        }
        foreach ($writes as $row) {
            $lines[] = '  writes: ' . $this->rowLabel($row);
        }
        foreach ($fields as $field) {
            $name = trim((string) ($field['name'] ?? ''));
            $type = trim((string) ($field['type'] ?? ''));
            if ($name !== '') {
                $lines[] = '  field: ' . $name . ($type !== '' ? ' (' . $type . ')' : '');
            }
        }
    }

    /**
     * @param array<int,string> $lines
     * @param array<string,mixed> $payload
     */
    private function appendGraphRelationships(array &$lines, array $payload): void
    {
        $relationships = is_array($payload['relationships']['graph'] ?? null) ? $payload['relationships']['graph'] : [];
        $inbound = array_values(array_filter((array) ($relationships['inbound'] ?? []), 'is_array'));
        $outbound = array_values(array_filter((array) ($relationships['outbound'] ?? []), 'is_array'));
        $lateral = array_values(array_filter((array) ($relationships['lateral'] ?? []), 'is_array'));

        if ($inbound === [] && $outbound === [] && $lateral === []) {
            return;
        }

        $this->blankLine($lines);
        $lines[] = $this->deep($payload) ? 'Graph Relationships (Expanded)' : 'Graph Relationships';

        foreach ($inbound as $row) {
            $lines[] = '  inbound: ' . $this->rowLabel($row);
        }
        foreach ($outbound as $row) {
            $lines[] = '  outbound: ' . $this->rowLabel($row);
        }
        foreach ($lateral as $row) {
            $lines[] = '  lateral: ' . $this->rowLabel($row);
        }
    }

    /**
     * @param array<int,string> $lines
     * @param array<string,mixed> $payload
     */
    private function appendDocs(array &$lines, array $payload): void
    {
        $docs = array_values(array_filter((array) ($payload['relatedDocs'] ?? []), 'is_array'));
        if ($docs === []) {
            return;
        }

        $this->blankLine($lines);
        $lines[] = 'Related Docs';
        foreach ($docs as $row) {
            $path = trim((string) ($row['path'] ?? ''));
            $title = trim((string) ($row['title'] ?? ''));
            $lines[] = '  ' . ($path !== '' ? $path : $title);
        }
    }

    /**
     * @param array<int,string> $lines
     * @param array<string,mixed> $payload
     */
    private function appendDiagnostics(array &$lines, array $payload): void
    {
        $items = array_values(array_filter((array) ($payload['diagnostics']['items'] ?? []), 'is_array'));

        $this->blankLine($lines);
        $lines[] = 'Diagnostics';
        if ($items === []) {
            $lines[] = '  OK No issues detected';

            return;
        }

        foreach ($items as $row) {
            $severity = strtoupper(trim((string) ($row['severity'] ?? 'info')));
            $message = trim((string) ($row['message'] ?? $row['code'] ?? ''));
            $lines[] = '  ' . $severity . ' ' . $message;
        }
    }

    /**
     * @param array<int,string> $lines
     * @param array<string,mixed> $payload
     */
    private function appendExtraSection(array &$lines, array $payload, string $sectionId): void
    {
        foreach (array_values(array_filter((array) ($payload['sections'] ?? []), 'is_array')) as $section) {
            if ((string) ($section['id'] ?? '') !== $sectionId) {
                continue;
            }

            $items = is_array($section['items'] ?? null) ? $section['items'] : [];
            if ($items === []) {
                return;
            }

            $this->blankLine($lines);
            $lines[] = trim((string) ($section['title'] ?? 'Details'));
            foreach ($this->sectionItemLines((string) ($section['shape'] ?? 'key_value'), $items) as $line) {
                $lines[] = '  ' . $line;
            }
            return;
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,string>
     */
    private function sectionOrder(array $payload): array
    {
        $order = array_values(array_filter(array_map(
            static fn (mixed $id): string => trim((string) $id),
            (array) ($payload['sectionOrder'] ?? []),
        ), static fn (string $id): bool => $id !== ''));

        return $order !== [] ? $order : self::CANONICAL_SECTION_IDS;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function deep(array $payload): bool
    {
        return (bool) ($payload['summary']['deep'] ?? false);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function rowLabel(array $row): string
    {
        $kind = trim((string) ($row['kind'] ?? ''));
        $label = trim((string) ($row['label'] ?? $row['name'] ?? $row['id'] ?? ''));

        if ($kind === '' || $label === '') {
            return $label !== '' ? $label : trim((string) ($row['id'] ?? ''));
        }

        return str_starts_with($label, $kind . ':') ? $label : ($kind . ':' . $label);
    }

    /**
     * @param array<string,mixed> $entry
     */
    private function entryLabel(array $entry): string
    {
        return trim((string) ($entry['label'] ?? $entry['name'] ?? $entry['kind'] ?? 'step'));
    }

    /**
     * @param array<string,mixed> $entry
     * @return array<int,string>
     */
    private function entryDetails(array $entry): array
    {
        $details = [];
        if (is_array($entry['guard'] ?? null)) {
            $guard = $entry['guard'];
            $stage = trim((string) ($guard['stage'] ?? ''));
            $permission = trim((string) ($guard['config']['permission'] ?? ''));
            if ($stage !== '') {
                $details[] = 'stage: ' . $stage;
            }
            if ($permission !== '') {
                $details[] = 'required: ' . $permission;
            }
        }

        if (is_array($entry['action'] ?? null)) {
            $action = $entry['action'];
            $feature = trim((string) ($action['feature'] ?? $action['label'] ?? ''));
            if ($feature !== '') {
                $details[] = 'feature: ' . $feature;
            }
        }

        return $details;
    }

    /**
     * @param array<string,mixed> $items
     * @return array<int,string>
     */
    private function sectionItemLines(string $shape, array $items): array
    {
        return match ($shape) {
            'string_list' => $this->stringListLines($items),
            'row_list' => $this->rowListLines($items),
            default => $this->keyValueLines($items),
        };
    }

    /**
     * @param array<string,mixed> $items
     * @return array<int,string>
     */
    private function keyValueLines(array $items): array
    {
        $lines = [];
        foreach ($items as $key => $value) {
            if (is_array($value) && array_is_list($value)) {
                $lines[] = (string) $key . ': ' . implode(', ', array_values(array_map(
                    fn (mixed $item): string => is_array($item) ? $this->rowLabel($item) : trim((string) $item),
                    $value,
                )));
                continue;
            }
            if (is_array($value)) {
                $lines[] = (string) $key . ': ' . implode(', ', array_map(
                    static fn (string $nestedKey, mixed $nestedValue): string => $nestedKey . '=' . trim((string) $nestedValue),
                    array_keys($value),
                    array_values($value),
                ));
                continue;
            }

            $lines[] = (string) $key . ': ' . (string) $value;
        }

        return $lines;
    }

    /**
     * @param array<string,mixed> $items
     * @return array<int,string>
     */
    private function stringListLines(array $items): array
    {
        return array_values(array_map(
            static fn (mixed $item): string => '- ' . trim((string) $item),
            array_values($items),
        ));
    }

    /**
     * @param array<string,mixed> $items
     * @return array<int,string>
     */
    private function rowListLines(array $items): array
    {
        $lines = [];
        foreach (array_values(array_filter($items, 'is_array')) as $row) {
            $lines[] = '- ' . $this->rowLabel($row);
        }

        return $lines;
    }

    /**
     * @param array<int,string> $lines
     */
    private function blankLine(array &$lines): void
    {
        if ($lines !== [] && end($lines) !== '') {
            $lines[] = '';
        }
    }
}
