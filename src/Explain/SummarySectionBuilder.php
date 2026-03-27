<?php
declare(strict_types=1);

namespace Foundry\Explain;

final class SummarySectionBuilder
{
    /**
     * @param array<string,mixed> $summaryInputs
     * @param array<string,mixed> $sections
     * @return array<string,mixed>
     */
    public function build(
        ExplainSubject $subject,
        ExplainOptions $options,
        array $summaryInputs,
        array $sections,
    ): array {
        $text = match ($subject->kind) {
            'feature' => $this->featureSummary($subject, $summaryInputs, $sections),
            'route' => $this->routeSummary($subject, $summaryInputs, $sections),
            'workflow' => $this->workflowSummary($subject, $summaryInputs, $sections),
            'event' => $this->eventSummary($subject, $summaryInputs, $sections),
            'command' => $this->commandSummary($subject, $summaryInputs),
            'pipeline_stage' => $this->pipelineStageSummary($subject, $summaryInputs),
            'schema' => $this->schemaSummary($subject, $summaryInputs),
            'extension' => $this->extensionSummary($subject, $summaryInputs),
            'job' => $this->jobSummary($subject, $summaryInputs),
            default => sprintf('%s is a %s in the compiled application graph.', $subject->label, $subject->kind),
        };

        return [
            'text' => $text,
            'deterministic' => true,
            'deep' => $options->deep,
        ];
    }

    /**
     * @param array<string,mixed> $summaryInputs
     * @param array<string,mixed> $sections
     */
    private function featureSummary(ExplainSubject $subject, array $summaryInputs, array $sections): string
    {
        $feature = (string) ($summaryInputs['feature'] ?? $subject->label);
        $description = trim((string) ($summaryInputs['description'] ?? ''));
        $route = trim((string) ($summaryInputs['route_signature'] ?? ''));
        $triggers = $this->sectionLabels($sections['triggers']['items'] ?? []);

        $parts = [$description !== '' ? ucfirst(rtrim($description, '.')) . '.' : sprintf('%s is a feature in the compiled application graph.', $feature)];
        if ($route !== '') {
            $parts[] = 'It serves ' . $route . '.';
        }
        if ($triggers !== []) {
            $parts[] = 'It triggers ' . implode(', ', array_slice($triggers, 0, 3)) . '.';
        }

        return implode(' ', $parts);
    }

    /**
     * @param array<string,mixed> $summaryInputs
     * @param array<string,mixed> $sections
     */
    private function routeSummary(ExplainSubject $subject, array $summaryInputs, array $sections): string
    {
        $signature = trim((string) ($summaryInputs['signature'] ?? $subject->label));
        $feature = trim((string) ($summaryInputs['feature'] ?? ''));
        $emits = $this->sectionLabels($sections['emits']['items'] ?? []);
        $triggers = $this->sectionLabels($sections['triggers']['items'] ?? []);

        $parts = [sprintf('%s handles requests through the compiled application graph.', $signature)];
        if ($feature !== '') {
            $parts[] = 'It dispatches the ' . $feature . ' feature action.';
        }
        if ($emits !== []) {
            $parts[] = 'It emits ' . implode(', ', array_slice($emits, 0, 3)) . '.';
        }
        if ($triggers !== []) {
            $parts[] = 'It triggers ' . implode(', ', array_slice($triggers, 0, 3)) . '.';
        }

        return implode(' ', $parts);
    }

    /**
     * @param array<string,mixed> $summaryInputs
     * @param array<string,mixed> $sections
     */
    private function workflowSummary(ExplainSubject $subject, array $summaryInputs, array $sections): string
    {
        $resource = trim((string) ($summaryInputs['resource'] ?? $subject->label));
        $triggers = $this->sectionLabels($sections['triggers']['items'] ?? []);
        $emits = $this->sectionLabels($sections['emits']['items'] ?? []);

        $parts = [sprintf('%s is a workflow for the %s resource.', $resource, $resource)];
        if ($emits !== []) {
            $parts[] = 'It emits ' . implode(', ', array_slice($emits, 0, 3)) . '.';
        }
        if ($triggers !== []) {
            $parts[] = 'It triggers ' . implode(', ', array_slice($triggers, 0, 3)) . '.';
        }

        return implode(' ', $parts);
    }

    /**
     * @param array<string,mixed> $summaryInputs
     * @param array<string,mixed> $sections
     */
    private function eventSummary(ExplainSubject $subject, array $summaryInputs, array $sections): string
    {
        $name = trim((string) ($summaryInputs['name'] ?? $subject->label));
        $usedBy = $this->sectionLabels($sections['dependents']['items'] ?? []);

        $parts = [sprintf('%s is an event contract compiled into the application graph.', $name)];
        if ($usedBy !== []) {
            $parts[] = 'It is used by ' . implode(', ', array_slice($usedBy, 0, 3)) . '.';
        }

        return implode(' ', $parts);
    }

    /**
     * @param array<string,mixed> $summaryInputs
     */
    private function commandSummary(ExplainSubject $subject, array $summaryInputs): string
    {
        $signature = trim((string) ($summaryInputs['signature'] ?? $subject->label));
        $summary = trim((string) ($summaryInputs['summary'] ?? ''));
        $classification = trim((string) ($summaryInputs['classification'] ?? ''));
        $aliases = ExplainSupport::uniqueStrings(array_values(array_map('strval', (array) ($summaryInputs['aliases'] ?? []))));

        $parts = [$summary !== ''
            ? sprintf('%s is a CLI command in the Foundry command surface. %s', $signature, $summary)
            : sprintf('%s is a CLI command in the Foundry command surface.', $signature)];
        if ($classification !== '') {
            $parts[] = 'It belongs to the ' . $classification . ' command category.';
        }
        if ($aliases !== []) {
            $parts[] = 'It is also reachable as ' . implode(', ', array_slice($aliases, 0, 3)) . '.';
        }

        return implode(' ', $parts);
    }

    /**
     * @param array<string,mixed> $summaryInputs
     */
    private function pipelineStageSummary(ExplainSubject $subject, array $summaryInputs): string
    {
        $name = trim((string) ($summaryInputs['name'] ?? $subject->label));

        return sprintf('%s is a pipeline stage in the canonical execution sequence.', $name);
    }

    /**
     * @param array<string,mixed> $summaryInputs
     */
    private function schemaSummary(ExplainSubject $subject, array $summaryInputs): string
    {
        $path = trim((string) ($summaryInputs['path'] ?? $subject->label));
        $role = trim((string) ($summaryInputs['role'] ?? 'schema'));
        $feature = trim((string) ($summaryInputs['feature'] ?? ''));
        $fields = array_values(array_filter((array) ($summaryInputs['fields'] ?? []), 'is_array'));

        $parts = [sprintf('%s is a %s schema in the compiled application graph.', $path, $role)];
        if ($feature !== '') {
            $parts[] = 'It belongs to the ' . $feature . ' feature.';
        }
        if ($fields !== []) {
            $parts[] = 'It exposes ' . count($fields) . ' compiled fields.';
        }

        return implode(' ', $parts);
    }

    /**
     * @param array<string,mixed> $summaryInputs
     */
    private function extensionSummary(ExplainSubject $subject, array $summaryInputs): string
    {
        $name = trim((string) ($summaryInputs['name'] ?? $subject->label));
        $description = trim((string) ($summaryInputs['description'] ?? ''));
        $provides = ExplainSupport::uniqueStrings(array_values(array_map('strval', (array) ($summaryInputs['provides'] ?? []))));
        $packs = ExplainSupport::uniqueStrings(array_values(array_map('strval', (array) ($summaryInputs['packs'] ?? []))));

        $parts = [$description !== ''
            ? sprintf('%s is a registered compiler extension. %s', $name, $description)
            : sprintf('%s is a registered compiler extension.', $name)];
        if ($provides !== []) {
            $parts[] = 'It provides ' . implode(', ', array_slice($provides, 0, 3)) . '.';
        }
        if ($packs !== []) {
            $parts[] = 'It ships ' . implode(', ', array_slice($packs, 0, 3)) . '.';
        }

        return implode(' ', $parts);
    }

    /**
     * @param array<string,mixed> $summaryInputs
     */
    private function jobSummary(ExplainSubject $subject, array $summaryInputs): string
    {
        $name = trim((string) ($summaryInputs['name'] ?? $subject->label));
        $features = ExplainSupport::uniqueStrings(array_values(array_map('strval', (array) ($summaryInputs['features'] ?? []))));
        $queues = ExplainSupport::uniqueStrings(array_values(array_map('strval', (array) ($summaryInputs['queues'] ?? []))));

        $parts = [sprintf('%s is a background job definition in the compiled application graph.', $name)];
        if ($features !== []) {
            $parts[] = 'It serves ' . implode(', ', array_slice($features, 0, 3)) . '.';
        }
        if ($queues !== []) {
            $parts[] = 'It runs on ' . implode(', ', array_slice($queues, 0, 3)) . '.';
        }

        return implode(' ', $parts);
    }

    /**
     * @param mixed $items
     * @return array<int,string>
     */
    private function sectionLabels(mixed $items): array
    {
        $labels = [];
        foreach ((array) $items as $row) {
            if (!is_array($row)) {
                continue;
            }

            $labels[] = trim((string) ($row['label'] ?? $row['name'] ?? $row['id'] ?? ''));
        }

        return ExplainSupport::uniqueStrings($labels);
    }
}
