<?php
declare(strict_types=1);

namespace Foundry\Explain\Analyzers;

use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;

final class SchemaSubjectAnalyzer implements SubjectAnalyzerInterface
{
    public function supports(ExplainSubject $subject): bool
    {
        return $subject->kind === 'schema';
    }

    public function analyze(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): SubjectAnalysisResult
    {
        $schemas = $context->schemas();

        return new SubjectAnalysisResult(
            responsibilities: [
                'Define a deterministic data contract for validation and serialization',
            ],
            summaryInputs: [
                'path' => $subject->metadata['path'] ?? $subject->label,
                'role' => $subject->metadata['role'] ?? 'schema',
                'fields' => $schemas['fields'] ?? [],
            ],
        );
    }
}
