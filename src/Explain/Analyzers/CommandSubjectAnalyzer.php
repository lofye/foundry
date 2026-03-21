<?php
declare(strict_types=1);

namespace Foundry\Explain\Analyzers;

use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;

final class CommandSubjectAnalyzer implements SubjectAnalyzerInterface
{
    public function supports(ExplainSubject $subject): bool
    {
        return $subject->kind === 'command';
    }

    public function analyze(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): SubjectAnalysisResult
    {
        $command = is_array($context->commands()['subject'] ?? null) ? $context->commands()['subject'] : $subject->metadata;
        $signature = trim((string) ($command['signature'] ?? $subject->label));
        $usage = trim((string) ($command['usage'] ?? ''));
        $summary = trim((string) ($command['summary'] ?? ''));

        $responsibilities = ['Expose the ' . $signature . ' command in the Foundry CLI surface'];
        if ($summary !== '') {
            $responsibilities[] = rtrim($summary, '.');
        }

        return new SubjectAnalysisResult(
            responsibilities: $responsibilities,
            summaryInputs: [
                'signature' => $signature,
                'usage' => $usage,
                'summary' => $summary,
                'classification' => $command['classification'] ?? null,
            ],
        );
    }
}
