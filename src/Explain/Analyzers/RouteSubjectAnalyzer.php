<?php

declare(strict_types=1);

namespace Foundry\Explain\Analyzers;

use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;
use Foundry\Explain\ExplainSupport;

final class RouteSubjectAnalyzer implements SubjectAnalyzerInterface
{
    public function supports(ExplainSubject $subject): bool
    {
        return $subject->kind === 'route';
    }

    public function analyze(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): SubjectAnalysisResult
    {
        $signature = ExplainSupport::normalizeRouteSignature((string) ($subject->metadata['signature'] ?? $subject->label));
        $feature = trim((string) ($subject->metadata['feature'] ?? ''));
        $pipeline = $context->pipeline();
        $events = $context->events();
        $workflows = $context->workflows();
        $jobs = array_values(array_map(
            static fn(array $job): string => (string) ($job['name'] ?? $job['label'] ?? ''),
            array_values(array_filter((array) ($pipeline['jobs'] ?? []), 'is_array')),
        ));

        return new SubjectAnalysisResult(
            responsibilities: ExplainSupport::orderedUniqueStrings(array_filter([
                'Handle ' . $signature . ' requests',
                $feature !== '' ? 'Dispatch the ' . $feature . ' feature action' : null,
            ], static fn(?string $value): bool => $value !== null)),
            summaryInputs: [
                'signature' => $signature,
                'feature' => $feature,
                'emits' => array_keys((array) ($events['emitted'] ?? [])),
                'workflows' => array_values(array_map(
                    static fn(array $workflow): string => (string) ($workflow['resource'] ?? $workflow['label'] ?? 'workflow'),
                    array_values(array_filter((array) ($workflows['items'] ?? []), 'is_array')),
                )),
                'jobs' => $jobs,
            ],
        );
    }
}
