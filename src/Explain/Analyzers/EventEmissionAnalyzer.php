<?php

declare(strict_types=1);

namespace Foundry\Explain\Analyzers;

use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;
use Foundry\Explain\ExplainSupport;

final class EventEmissionAnalyzer implements SectionAnalyzerInterface
{
    public function supports(ExplainSubject $subject): bool
    {
        return in_array($subject->kind, ['feature', 'route', 'workflow'], true);
    }

    public function sectionId(): string
    {
        return 'emits';
    }

    public function analyze(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): array
    {
        $rows = [];
        foreach (array_keys((array) ($context->events()['emitted'] ?? [])) as $eventName) {
            $rows[] = [
                'id' => 'event:' . (string) $eventName,
                'kind' => 'event',
                'label' => (string) $eventName,
                'name' => (string) $eventName,
            ];
        }

        if ($subject->kind === 'workflow') {
            foreach (array_values(array_filter((array) ($context->workflows()['items'] ?? []), 'is_array')) as $workflow) {
                foreach (array_values(array_filter((array) ($workflow['emits'] ?? []), 'is_array')) as $event) {
                    $rows[] = $event;
                }
            }
        }

        return ['items' => ExplainSupport::uniqueRows($rows)];
    }
}
