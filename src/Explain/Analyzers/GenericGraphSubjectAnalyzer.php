<?php

declare(strict_types=1);

namespace Foundry\Explain\Analyzers;

use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;
use Foundry\Explain\ExplainSupport;

final class GenericGraphSubjectAnalyzer implements SubjectAnalyzerInterface
{
    public function supports(ExplainSubject $subject): bool
    {
        return $subject->graphNodeIds !== [];
    }

    public function analyze(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): SubjectAnalysisResult
    {
        $impact = $context->impact();
        $sections = [];
        if (is_array($impact)) {
            $sections[] = ExplainSupport::section('impact', 'Impact', [
                'risk' => $impact['risk'] ?? 'low',
                'affected_features' => $impact['affected_features'] ?? [],
                'affected_routes' => $impact['affected_routes'] ?? [],
                'affected_events' => $impact['affected_events'] ?? [],
                'affected_jobs' => $impact['affected_jobs'] ?? [],
                'affected_projections' => $impact['affected_projections'] ?? [],
            ]);
        }

        return new SubjectAnalysisResult(sections: $sections);
    }
}
