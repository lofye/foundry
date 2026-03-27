<?php

declare(strict_types=1);

namespace Foundry\Explain\Analyzers;

use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;
use Foundry\Explain\ExplainSupport;

final class TriggerAnalyzer implements SectionAnalyzerInterface
{
    public function supports(ExplainSubject $subject): bool
    {
        return in_array($subject->kind, ['feature', 'route', 'workflow', 'event'], true);
    }

    public function sectionId(): string
    {
        return 'triggers';
    }

    public function analyze(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): array
    {
        $rows = [];

        foreach (array_values(array_filter((array) ($context->workflows()['items'] ?? []), 'is_array')) as $workflow) {
            $rows[] = [
                'id' => (string) ($workflow['id'] ?? 'workflow:' . ($workflow['resource'] ?? $workflow['label'] ?? 'workflow')),
                'kind' => 'workflow',
                'label' => (string) ($workflow['resource'] ?? $workflow['label'] ?? 'workflow'),
            ];
        }

        foreach (array_values(array_filter((array) ($context->pipeline()['jobs'] ?? []), 'is_array')) as $job) {
            $rows[] = [
                'id' => (string) ($job['id'] ?? 'job:' . ($job['name'] ?? $job['label'] ?? 'job')),
                'kind' => 'job',
                'label' => (string) ($job['name'] ?? $job['label'] ?? 'job'),
            ];
        }

        foreach (array_values(array_filter((array) ($context->graphNeighborhood()['dependents'] ?? []), 'is_array')) as $row) {
            if (in_array((string) ($row['kind'] ?? ''), ['workflow', 'job', 'notification'], true)) {
                $rows[] = $row;
            }
        }

        return ['items' => ExplainSupport::uniqueRows($rows)];
    }
}
