<?php
declare(strict_types=1);

namespace Foundry\Explain\Analyzers;

use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;
use Foundry\Explain\ExplainSupport;

final class WorkflowSubjectAnalyzer implements SubjectAnalyzerInterface
{
    public function supports(ExplainSubject $subject): bool
    {
        return $subject->kind === 'workflow';
    }

    public function analyze(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): array
    {
        $resource = (string) ($subject->metadata['resource'] ?? $subject->label);
        $transitions = is_array($subject->metadata['transitions'] ?? null) ? $subject->metadata['transitions'] : [];

        return [
            'sections' => [
                ExplainSupport::section('workflow', 'Workflow', [
                    'resource' => $resource,
                    'states' => $subject->metadata['states'] ?? [],
                    'transitions' => $transitions,
                ]),
            ],
            'related_commands' => [
                $context->commandPrefix . ' inspect workflow ' . $resource . ' --json',
                $context->commandPrefix . ' graph inspect --workflow=' . $resource . ' --json',
                $context->commandPrefix . ' verify workflows --json',
            ],
        ];
    }
}
