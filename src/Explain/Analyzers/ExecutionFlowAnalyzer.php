<?php
declare(strict_types=1);

namespace Foundry\Explain\Analyzers;

use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;

final class ExecutionFlowAnalyzer implements SectionAnalyzerInterface
{
    public function supports(ExplainSubject $subject): bool
    {
        return in_array($subject->kind, ['feature', 'route', 'pipeline_stage'], true);
    }

    public function sectionId(): string
    {
        return 'execution_flow';
    }

    public function analyze(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): array
    {
        if (!$options->includeExecutionFlow) {
            return [];
        }

        $pipeline = $context->pipeline();
        $events = $context->events();
        $workflows = $context->workflows();
        $entries = [];

        if ($subject->kind === 'route') {
            $entries[] = ['kind' => 'request', 'label' => 'request'];
        }

        foreach (array_values(array_filter((array) ($pipeline['guards'] ?? []), 'is_array')) as $guard) {
            $entries[] = $this->guardEntry($guard);
        }

        if ($subject->kind === 'pipeline_stage') {
            $name = (string) ($subject->metadata['name'] ?? $subject->label);
            $entries[] = ['kind' => 'stage', 'label' => $name, 'name' => $name];
        } elseif (is_array($pipeline['action'] ?? null)) {
            $entries[] = [
                'kind' => 'action',
                'label' => (string) (($pipeline['action']['label'] ?? 'action') . ' feature action'),
                'action' => $pipeline['action'],
            ];
        }

        foreach (array_keys((array) ($events['emitted'] ?? [])) as $eventName) {
            $entries[] = [
                'kind' => 'event',
                'label' => (string) $eventName,
                'name' => (string) $eventName,
            ];
        }

        foreach (array_values(array_filter((array) ($workflows['items'] ?? []), 'is_array')) as $workflow) {
            $entries[] = [
                'kind' => 'workflow',
                'label' => (string) ($workflow['resource'] ?? $workflow['label'] ?? 'workflow'),
                'workflow' => $workflow,
            ];
        }

        foreach (array_values(array_filter((array) ($pipeline['jobs'] ?? []), 'is_array')) as $job) {
            $entries[] = [
                'kind' => 'job',
                'label' => (string) ($job['name'] ?? $job['label'] ?? 'job'),
                'job' => $job,
            ];
        }

        if ($entries === []) {
            return [];
        }

        return [
            'entries' => $entries,
            'stages' => array_values(array_filter((array) ($pipeline['stages'] ?? []), 'is_array')),
            'guards' => array_values(array_filter((array) ($pipeline['guards'] ?? []), 'is_array')),
            'action' => is_array($pipeline['action'] ?? null) ? $pipeline['action'] : null,
            'events' => array_values(array_map(
                static fn (string $name): array => ['id' => 'event:' . $name, 'kind' => 'event', 'label' => $name, 'name' => $name],
                array_values(array_map('strval', array_keys((array) ($events['emitted'] ?? [])))),
            )),
            'workflows' => array_values(array_filter((array) ($workflows['items'] ?? []), 'is_array')),
            'jobs' => array_values(array_filter((array) ($pipeline['jobs'] ?? []), 'is_array')),
        ];
    }

    /**
     * @param array<string,mixed> $guard
     * @return array<string,mixed>
     */
    private function guardEntry(array $guard): array
    {
        $type = trim((string) ($guard['type'] ?? $guard['id'] ?? 'guard'));
        $permission = trim((string) ($guard['config']['permission'] ?? ''));
        $label = match ($type) {
            'authentication' => 'auth guard',
            'permission' => $permission !== '' ? 'permission guard (' . $permission . ')' : 'permission guard',
            default => $type . ' guard',
        };

        return [
            'kind' => 'guard',
            'label' => $label,
            'guard' => $guard,
        ];
    }
}
