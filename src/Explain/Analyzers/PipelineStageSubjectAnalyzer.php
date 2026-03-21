<?php
declare(strict_types=1);

namespace Foundry\Explain\Analyzers;

use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;
use Foundry\Explain\ExplainSupport;

final class PipelineStageSubjectAnalyzer implements SubjectAnalyzerInterface
{
    public function supports(ExplainSubject $subject): bool
    {
        return $subject->kind === 'pipeline_stage';
    }

    public function analyze(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): array
    {
        $definition = $context->artifacts->pipelineIndex();

        return [
            'sections' => [
                ExplainSupport::section('pipeline_stage', 'Pipeline Stage', [
                    'name' => $subject->metadata['name'] ?? $subject->label,
                    'order' => $definition['order'] ?? [],
                    'links' => $definition['links'] ?? [],
                ]),
            ],
            'related_commands' => [
                $context->commandPrefix . ' inspect pipeline --json',
                $context->commandPrefix . ' verify pipeline --json',
            ],
        ];
    }
}
