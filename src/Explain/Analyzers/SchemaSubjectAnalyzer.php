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
        $path = (string) ($subject->metadata['path'] ?? $subject->label);
        $role = (string) ($subject->metadata['role'] ?? 'schema');
        $feature = trim((string) ($subject->metadata['feature'] ?? ''));
        $fields = array_values(array_filter((array) ($schemas['fields'] ?? []), 'is_array'));

        $responsibilities = [
            'Define a deterministic data contract for validation and serialization',
        ];
        if ($feature !== '') {
            $responsibilities[] = 'Support the ' . $feature . ' feature data boundary';
        }
        if ($fields !== []) {
            $responsibilities[] = 'Describe ' . count($fields) . ' compiled schema fields';
        }

        return new SubjectAnalysisResult(
            responsibilities: $responsibilities,
            summaryInputs: [
                'path' => $path,
                'role' => $role,
                'feature' => $feature,
                'fields' => $fields,
            ],
            sections: [
                \Foundry\Explain\ExplainSupport::section(
                    'schema_contract',
                    'Schema Contract',
                    array_filter([
                        'path' => $path,
                        'role' => $role,
                        'feature' => $feature,
                        'field_count' => $fields !== [] ? (string) count($fields) : '',
                    ], static fn (mixed $value): bool => $value !== ''),
                ),
            ],
        );
    }
}
