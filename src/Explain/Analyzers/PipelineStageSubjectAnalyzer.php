<?php
declare(strict_types=1);

namespace Foundry\Explain\Analyzers;

use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;

final class PipelineStageSubjectAnalyzer implements SubjectAnalyzerInterface
{
    public function supports(ExplainSubject $subject): bool
    {
        return $subject->kind === 'pipeline_stage';
    }

    public function analyze(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): SubjectAnalysisResult
    {
        $definition = $context->pipeline()['definition'] ?? [];
        $name = (string) ($subject->metadata['name'] ?? $subject->label);
        $order = array_values(array_map('strval', (array) ($definition['order'] ?? [])));
        $position = array_search($name, $order, true);

        return new SubjectAnalysisResult(
            responsibilities: [
                'Participate in the canonical request pipeline sequence',
                'Anchor guards and interceptors assigned to this stage',
            ],
            summaryInputs: [
                'name' => $name,
                'order' => $order,
                'position' => $position === false ? null : $position,
                'links' => $definition['links'] ?? [],
            ],
        );
    }
}
