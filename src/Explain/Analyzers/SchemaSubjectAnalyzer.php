<?php
declare(strict_types=1);

namespace Foundry\Explain\Analyzers;

use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;
use Foundry\Explain\ExplainSupport;

final class SchemaSubjectAnalyzer implements SubjectAnalyzerInterface
{
    public function supports(ExplainSubject $subject): bool
    {
        return $subject->kind === 'schema';
    }

    public function analyze(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): array
    {
        $schemas = is_array($context->get('schemas')) ? $context->get('schemas') : [];

        return [
            'sections' => [
                ExplainSupport::section('schema', 'Schema', $schemas),
            ],
            'related_commands' => [
                $context->commandPrefix . ' verify contracts --json',
            ],
        ];
    }
}
