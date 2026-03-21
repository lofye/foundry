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
        $route = trim((string) ($subject->metadata['route_signature'] ?? ''));
        if ($route === '' && is_array($subject->metadata['route'] ?? null)) {
            $method = strtoupper(trim((string) ($subject->metadata['route']['method'] ?? '')));
            $path = trim((string) ($subject->metadata['route']['path'] ?? ''));
            $route = trim($method . ' ' . $path);
        }
        $events = (array) ($context->get('events', [])['emitted'] ?? []);
        $jobs = (array) ($subject->metadata['jobs']['dispatch'] ?? []);

        $parts = [sprintf('%s is a feature in the compiled application graph.', $feature)];
        if ($route !== '') {
            $parts[] = 'It serves ' . $route . '.';
        }
        if ($events !== []) {
            $parts[] = 'It emits ' . implode(', ', array_slice(array_keys($events), 0, 3)) . '.';
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

        $parts = [sprintf('%s is a route in the compiled application graph.', $signature)];
        if ($feature !== '') {
            $parts[] = 'It dispatches the ' . $feature . ' feature through the resolved pipeline.';
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

        return sprintf(
            '%s is a workflow for the %s resource with %d compiled transitions.',
            $resource,
            $resource,
            count($transitions),
        );
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
}
