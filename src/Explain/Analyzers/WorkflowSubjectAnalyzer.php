<?php

declare(strict_types=1);

namespace Foundry\Explain\Analyzers;

use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;

final class WorkflowSubjectAnalyzer implements SubjectAnalyzerInterface
{
    public function supports(ExplainSubject $subject): bool
    {
        return $subject->kind === 'workflow';
    }

    public function analyze(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): SubjectAnalysisResult
    {
        $resource = (string) ($subject->metadata['resource'] ?? $subject->label);
        $transitions = is_array($subject->metadata['transitions'] ?? null) ? $subject->metadata['transitions'] : [];
        $responsibilities = ['Process the ' . $resource . ' workflow transitions'];
        if ($transitions !== []) {
            $responsibilities[] = 'Evaluate transition conditions and emit follow-up events';
        }

        return new SubjectAnalysisResult(
            responsibilities: $responsibilities,
            summaryInputs: [
                'resource' => $resource,
                'states' => $subject->metadata['states'] ?? [],
                'transitions' => $transitions,
            ],
        );
    }
}
