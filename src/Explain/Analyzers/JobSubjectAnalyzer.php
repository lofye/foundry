<?php
declare(strict_types=1);

namespace Foundry\Explain\Analyzers;

use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;

final class JobSubjectAnalyzer implements SubjectAnalyzerInterface
{
    public function supports(ExplainSubject $subject): bool
    {
        return $subject->kind === 'job';
    }

    public function analyze(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): SubjectAnalysisResult
    {
        $definitions = is_array($subject->metadata['definitions'] ?? null) ? $subject->metadata['definitions'] : [];
        $features = array_values(array_filter(array_map('strval', (array) ($subject->metadata['features'] ?? []))));
        $queues = [];
        foreach ($definitions as $row) {
            if (!is_array($row)) {
                continue;
            }

            $queue = trim((string) ($row['queue'] ?? ''));
            if ($queue !== '') {
                $queues[] = $queue;
            }
        }
        $queues = array_values(array_unique($queues));

        $responsibilities = [
            'Run background work outside the immediate request pipeline',
        ];
        foreach ($features as $feature) {
            $responsibilities[] = 'Handle queued work for feature: ' . $feature;
        }
        foreach ($queues as $queue) {
            $responsibilities[] = 'Dispatch through queue: ' . $queue;
        }

        return new SubjectAnalysisResult(
            responsibilities: $responsibilities,
            summaryInputs: [
                'name' => $subject->metadata['name'] ?? $subject->label,
                'features' => $features,
                'definitions' => $definitions,
                'queues' => $queues,
            ],
            sections: [
                \Foundry\Explain\ExplainSupport::section(
                    'job_definition',
                    'Job Definition',
                    array_filter([
                        'features' => $features,
                        'queues' => $queues,
                    ], static fn (mixed $value): bool => $value !== []),
                ),
            ],
        );
    }
}
