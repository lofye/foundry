<?php
declare(strict_types=1);

namespace Foundry\Explain\Analyzers;

use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;
use Foundry\Explain\ExplainSupport;

final class JobSubjectAnalyzer implements SubjectAnalyzerInterface
{
    public function supports(ExplainSubject $subject): bool
    {
        return $subject->kind === 'job';
    }

    public function analyze(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): array
    {
        $definitions = is_array($subject->metadata['definitions'] ?? null) ? $subject->metadata['definitions'] : [];

        return [
            'sections' => [
                ExplainSupport::section('job', 'Job', [
                    'name' => $subject->metadata['name'] ?? $subject->label,
                    'features' => $subject->metadata['features'] ?? [],
                    'definitions' => $definitions,
                ]),
            ],
            'related_commands' => [
                $context->commandPrefix . ' verify contracts --json',
            ],
        ];
    }
}
