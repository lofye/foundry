<?php
declare(strict_types=1);

namespace Foundry\Explain\Analyzers;

use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;
use Foundry\Explain\ExplainSupport;

final class DependentAnalyzer implements SectionAnalyzerInterface
{
    public function supports(ExplainSubject $subject): bool
    {
        return true;
    }

    public function sectionId(): string
    {
        return 'dependents';
    }

    public function analyze(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): array
    {
        $rows = [];
        $neighborhood = $context->graphNeighborhood();

        foreach ((array) ($neighborhood['dependents'] ?? []) as $row) {
            if (is_array($row) && $this->includeDependent($subject, $row)) {
                $rows[] = $row;
            }
        }

        if ($subject->kind === 'event') {
            foreach ((array) ($context->events()['subscribers'] ?? []) as $subscriber) {
                $rows[] = [
                    'id' => 'feature:' . (string) $subscriber,
                    'kind' => 'feature',
                    'label' => (string) $subscriber,
                ];
            }
        }

        if ($subject->kind === 'schema') {
            $feature = trim((string) ($subject->metadata['feature'] ?? ''));
            if ($feature !== '') {
                $rows[] = [
                    'id' => 'feature:' . $feature,
                    'kind' => 'feature',
                    'label' => $feature,
                ];
            }
        }

        return ['items' => ExplainSupport::uniqueRows($rows)];
    }

    /**
     * @param array<string,mixed> $row
     */
    private function includeDependent(ExplainSubject $subject, array $row): bool
    {
        $kind = (string) ($row['kind'] ?? '');

        return match ($subject->kind) {
            'feature' => in_array($kind, ['workflow', 'route', 'command', 'job'], true),
            'route' => in_array($kind, ['command'], true),
            'workflow' => in_array($kind, ['job', 'event', 'feature'], true),
            'event' => in_array($kind, ['workflow', 'feature', 'job'], true),
            'pipeline_stage' => in_array($kind, ['guard', 'pipeline_stage'], true),
            'schema' => in_array($kind, ['feature', 'route'], true),
            'extension' => in_array($kind, ['feature', 'workflow', 'command'], true),
            default => in_array($kind, ['feature', 'route', 'workflow', 'job'], true),
        };
    }
}
