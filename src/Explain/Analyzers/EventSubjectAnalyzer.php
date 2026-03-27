<?php

declare(strict_types=1);

namespace Foundry\Explain\Analyzers;

use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;

final class EventSubjectAnalyzer implements SubjectAnalyzerInterface
{
    public function supports(ExplainSubject $subject): bool
    {
        return $subject->kind === 'event';
    }

    public function analyze(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): SubjectAnalysisResult
    {
        $event = $context->events();
        $workflows = $context->workflows();
        $name = (string) ($subject->metadata['name'] ?? $subject->label);

        return new SubjectAnalysisResult(
            responsibilities: [
                'Carry the ' . $name . ' event contract through the compiled graph',
            ],
            summaryInputs: [
                'name' => $name,
                'emitters' => $event['emitters'] ?? [],
                'subscribers' => $event['subscribers'] ?? [],
                'workflows' => $workflows['items'] ?? [],
            ],
        );
    }
}
