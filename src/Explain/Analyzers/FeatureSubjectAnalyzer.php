<?php

declare(strict_types=1);

namespace Foundry\Explain\Analyzers;

use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;
use Foundry\Explain\ExplainSupport;

final class FeatureSubjectAnalyzer implements SubjectAnalyzerInterface
{
    public function supports(ExplainSubject $subject): bool
    {
        return $subject->kind === 'feature';
    }

    public function analyze(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): SubjectAnalysisResult
    {
        $feature = (string) ($subject->metadata['feature'] ?? $subject->label);
        $pipeline = $context->pipeline();
        $events = $context->events();
        $workflows = $context->workflows();

        $responsibilities = [];
        $description = trim((string) ($subject->metadata['description'] ?? ''));
        if ($description !== '') {
            $responsibilities[] = ucfirst(rtrim($description, '.'));
        }
        if (trim((string) ($pipeline['route_signature'] ?? '')) !== '') {
            $responsibilities[] = 'Serve ' . (string) $pipeline['route_signature'];
        }
        if ((array) ($events['emitted'] ?? []) !== []) {
            $responsibilities[] = 'Emit lifecycle events';
        }
        if ((array) ($workflows['items'] ?? []) !== []) {
            $responsibilities[] = 'Trigger downstream workflows';
        }
        if ((array) ($pipeline['jobs'] ?? []) !== []) {
            $responsibilities[] = 'Dispatch background jobs';
        }

        return new SubjectAnalysisResult(
            responsibilities: ExplainSupport::orderedUniqueStrings($responsibilities),
            summaryInputs: [
                'feature' => $feature,
                'description' => $description,
                'route_signature' => $pipeline['route_signature'] ?? null,
                'emits' => array_keys((array) ($events['emitted'] ?? [])),
                'workflows' => array_values(array_map(
                    static fn(array $workflow): string => (string) ($workflow['resource'] ?? $workflow['label'] ?? 'workflow'),
                    array_values(array_filter((array) ($workflows['items'] ?? []), 'is_array')),
                )),
                'jobs' => array_values(array_map(
                    static fn(array $job): string => (string) ($job['name'] ?? $job['label'] ?? ''),
                    array_values(array_filter((array) ($pipeline['jobs'] ?? []), 'is_array')),
                )),
            ],
        );
    }
}
