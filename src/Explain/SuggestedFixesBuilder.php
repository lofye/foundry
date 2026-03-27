<?php
declare(strict_types=1);

namespace Foundry\Explain;

final class SuggestedFixesBuilder
{
    /**
     * @param array<string,mixed> $sections
     * @return array<int,string>
     */
    public function build(ExplainSubject $subject, array $sections): array
    {
        $fixes = [];

        foreach ((array) ($sections['diagnostics']['items'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $suggestedFix = trim((string) ($row['suggested_fix'] ?? ''));
            if ($suggestedFix !== '') {
                $fixes[] = $suggestedFix;
            }

            $message = trim((string) ($row['message'] ?? ''));
            if ($message === '') {
                continue;
            }

            $normalized = strtolower($message);
            if (str_contains($normalized, 'no subscribers:')) {
                $event = trim((string) substr($message, strrpos($message, ':') + 1));
                if ($event !== '') {
                    $fixes[] = 'Add a subscriber or workflow for event: ' . $event;
                }
            }

            if (str_starts_with($normalized, 'event has no subscribers:')) {
                $event = trim((string) substr($message, strrpos($message, ':') + 1));
                if ($event !== '') {
                    $fixes[] = 'Add a subscriber or workflow for event: ' . $event;
                }
            }
        }

        foreach ((array) ($sections['permissions']['missing'] ?? []) as $permission) {
            $permission = trim((string) $permission);
            if ($permission !== '') {
                $fixes[] = 'Add permission mapping for: ' . $permission;
            }
        }

        foreach ($this->missingRelationshipTargets($sections) as $missing) {
            $label = trim((string) ($missing['label'] ?? $missing['id'] ?? ''));
            if ($label === '') {
                continue;
            }

            $kind = trim((string) ($missing['kind'] ?? 'missing'));
            $fixes[] = match ($kind) {
                'workflow' => 'Register workflow: ' . $label,
                'job' => 'Register job: ' . $label,
                'event' => 'Register event: ' . $label,
                'schema' => 'Register schema: ' . $label,
                default => 'Register or remove reference to: ' . $label,
            };
        }

        if (
            in_array($subject->kind, ['feature', 'route'], true)
            && is_array($sections['execution_flow'] ?? null)
            && array_key_exists('action', $sections['execution_flow'])
            && !is_array($sections['execution_flow']['action'])
        ) {
            $fixes[] = 'Add a feature action binding to the execution pipeline.';
        }

        return ExplainSupport::orderedUniqueStrings($fixes);
    }

    /**
     * @param array<string,mixed> $sections
     * @return array<int,array<string,mixed>>
     */
    private function missingRelationshipTargets(array $sections): array
    {
        $rows = [];
        foreach ((array) ($sections['dependencies']['items'] ?? []) as $row) {
            if (is_array($row) && (bool) ($row['missing'] ?? false)) {
                $rows[] = $row;
            }
        }
        foreach ((array) ($sections['dependents']['items'] ?? []) as $row) {
            if (is_array($row) && (bool) ($row['missing'] ?? false)) {
                $rows[] = $row;
            }
        }
        foreach (['inbound', 'outbound', 'lateral'] as $bucket) {
            foreach ((array) ($sections['graph_relationships'][$bucket] ?? []) as $row) {
                if (is_array($row) && (bool) ($row['missing'] ?? false)) {
                    $rows[] = $row;
                }
            }
        }

        return ExplainSupport::uniqueRows($rows);
    }
}
