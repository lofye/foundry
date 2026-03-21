<?php
declare(strict_types=1);

namespace Foundry\Explain;

final class RuleBasedSummaryBuilder
{
    /**
     * @return array<string,mixed>
     */
    public function build(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): array
    {
        $text = match ($subject->kind) {
            'feature' => $this->featureSummary($subject, $context),
            'route' => $this->routeSummary($subject, $context),
            'event' => $this->eventSummary($subject, $context),
            'workflow' => $this->workflowSummary($subject, $context),
            'command' => $this->commandSummary($subject),
            'job' => $this->jobSummary($subject),
            'schema' => $this->schemaSummary($subject),
            'extension' => $this->extensionSummary($subject),
            'pipeline_stage' => $this->pipelineStageSummary($subject),
            default => sprintf('%s is a %s in the compiled application graph.', $subject->label, $subject->kind),
        };

        return [
            'text' => $text,
            'deterministic' => true,
            'deep' => $options->deep,
        ];
    }

    private function featureSummary(ExplainSubject $subject, ExplainContext $context): string
    {
        $feature = (string) ($subject->metadata['feature'] ?? $subject->label);
        $description = trim((string) ($subject->metadata['description'] ?? ''));
        $route = trim((string) ($subject->metadata['route_signature'] ?? ''));
        if ($route === '' && is_array($subject->metadata['route'] ?? null)) {
            $method = strtoupper(trim((string) ($subject->metadata['route']['method'] ?? '')));
            $path = trim((string) ($subject->metadata['route']['path'] ?? ''));
            $route = trim($method . ' ' . $path);
        }
        $events = (array) ($context->get('events', [])['emitted'] ?? []);
        $jobs = (array) ($subject->metadata['jobs']['dispatch'] ?? []);
        $workflows = array_values(array_filter((array) ($context->get('workflows', [])['items'] ?? []), 'is_array'));

        $parts = [$description !== '' ? ucfirst(rtrim($description, '.')) . '.' : sprintf('%s is a feature in the compiled application graph.', $feature)];
        if ($route !== '') {
            $parts[] = 'It serves ' . $route . '.';
        }
        if ($events !== []) {
            $parts[] = 'It emits ' . implode(', ', array_slice(array_keys($events), 0, 3)) . '.';
        }
        if ($workflows !== []) {
            $parts[] = 'It feeds ' . implode(', ', array_slice(array_map(
                static fn (array $workflow): string => (string) ($workflow['resource'] ?? $workflow['label'] ?? 'workflow'),
                $workflows,
            ), 0, 3)) . '.';
        }
        if ($jobs !== []) {
            $parts[] = 'It dispatches ' . implode(', ', array_slice(array_map('strval', $jobs), 0, 3)) . '.';
        }

        return implode(' ', $parts);
    }

    private function routeSummary(ExplainSubject $subject, ExplainContext $context): string
    {
        $feature = trim((string) ($subject->metadata['feature'] ?? ''));
        $signature = trim((string) ($subject->metadata['signature'] ?? $subject->label));
        $events = array_keys((array) ($context->get('events', [])['emitted'] ?? []));
        $jobs = $this->jobsForFeature($feature, $context);

        $parts = [sprintf('%s handles requests through the compiled application graph.', $signature)];
        if ($feature !== '') {
            $parts[] = 'It dispatches the ' . $feature . ' feature through the resolved pipeline.';
        }
        if ($events !== []) {
            $parts[] = 'It emits ' . implode(', ', array_slice(array_values(array_map('strval', $events)), 0, 3)) . '.';
        }
        if ($jobs !== []) {
            $parts[] = 'It dispatches ' . implode(', ', array_slice($jobs, 0, 3)) . '.';
        }

        return implode(' ', $parts);
    }

    private function eventSummary(ExplainSubject $subject, ExplainContext $context): string
    {
        $name = (string) ($subject->metadata['name'] ?? $subject->label);
        $eventContext = (array) $context->get('events', []);
        $emitters = array_values(array_map('strval', (array) ($eventContext['emitters'] ?? [])));
        $subscribers = array_values(array_map('strval', (array) ($eventContext['subscribers'] ?? [])));

        $parts = [sprintf('%s is an event contract compiled into the application graph.', $name)];
        if ($emitters !== []) {
            $parts[] = 'It is emitted by ' . implode(', ', array_slice($emitters, 0, 3)) . '.';
        }
        if ($subscribers !== []) {
            $parts[] = 'It is subscribed to by ' . implode(', ', array_slice($subscribers, 0, 3)) . '.';
        }

        return implode(' ', $parts);
    }

    private function workflowSummary(ExplainSubject $subject, ExplainContext $context): string
    {
        $resource = (string) ($subject->metadata['resource'] ?? $subject->label);
        $transitions = is_array($subject->metadata['transitions'] ?? null) ? $subject->metadata['transitions'] : [];
        $emits = [];
        foreach ($transitions as $transition) {
            if (!is_array($transition)) {
                continue;
            }

            foreach ((array) ($transition['emit'] ?? []) as $event) {
                $emits[] = (string) $event;
            }
        }
        $emits = array_values(array_unique(array_filter($emits, static fn (string $value): bool => $value !== '')));

        $parts = [sprintf('%s is a workflow for the %s resource with %d compiled transitions.', $resource, $resource, count($transitions))];
        if ($emits !== []) {
            $parts[] = 'It emits ' . implode(', ', array_slice($emits, 0, 3)) . '.';
        }

        return implode(' ', $parts);
    }

    private function commandSummary(ExplainSubject $subject): string
    {
        $usage = trim((string) ($subject->metadata['usage'] ?? ''));
        $summary = trim((string) ($subject->metadata['summary'] ?? ''));

        $parts = [sprintf('%s is a CLI command in the Foundry command surface.', $subject->label)];
        if ($usage !== '') {
            $parts[] = 'Usage: ' . $usage . '.';
        }
        if ($summary !== '') {
            $parts[] = $summary;
        }

        return implode(' ', $parts);
    }

    private function jobSummary(ExplainSubject $subject): string
    {
        $name = (string) ($subject->metadata['name'] ?? $subject->label);
        $features = array_values(array_map('strval', (array) ($subject->metadata['features'] ?? [])));

        $parts = [sprintf('%s is a background job definition in the compiled application graph.', $name)];
        if ($features !== []) {
            $parts[] = 'It is referenced by ' . implode(', ', array_slice($features, 0, 3)) . '.';
        }

        return implode(' ', $parts);
    }

    private function schemaSummary(ExplainSubject $subject): string
    {
        $path = (string) ($subject->metadata['path'] ?? $subject->label);
        $role = trim((string) ($subject->metadata['role'] ?? 'schema'));

        return sprintf('%s is a %s schema in the compiled application graph.', $path, $role);
    }

    private function extensionSummary(ExplainSubject $subject): string
    {
        $description = trim((string) ($subject->metadata['description'] ?? ''));
        if ($description !== '') {
            return sprintf('%s is a registered compiler extension. %s', $subject->label, $description);
        }

        return sprintf('%s is a registered compiler extension.', $subject->label);
    }

    private function pipelineStageSummary(ExplainSubject $subject): string
    {
        return sprintf('%s is a pipeline stage in the canonical execution sequence.', $subject->label);
    }

    /**
     * @return array<int,string>
     */
    private function jobsForFeature(string $feature, ExplainContext $context): array
    {
        if ($feature === '') {
            return [];
        }

        $node = $context->graph->node('feature:' . $feature);
        if ($node === null) {
            return [];
        }

        return array_values(array_map('strval', (array) ($node->payload()['jobs']['dispatch'] ?? [])));
    }
}
