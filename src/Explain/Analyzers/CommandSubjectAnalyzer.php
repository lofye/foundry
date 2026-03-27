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
        $aliases = array_values(array_filter(array_map('strval', (array) ($command['aliases'] ?? []))));
        $classification = trim((string) ($command['classification'] ?? ''));

        $responsibilities = ['Expose the ' . $signature . ' command in the Foundry CLI surface'];
        if ($summary !== '') {
            $responsibilities[] = rtrim($summary, '.');
        }
        if ($usage !== '') {
            $responsibilities[] = 'Support the usage pattern: ' . $usage;
        }
        if ($classification !== '') {
            $responsibilities[] = 'Participate in the ' . $classification . ' command category';
        }

        return new SubjectAnalysisResult(
            responsibilities: $responsibilities,
            summaryInputs: [
                'signature' => $signature,
                'usage' => $usage,
                'summary' => $summary,
                'classification' => $classification,
                'aliases' => $aliases,
            ],
            sections: [
                \Foundry\Explain\ExplainSupport::section(
                    'command_surface',
                    'Command Surface',
                    array_filter([
                        'signature' => $signature,
                        'usage' => $usage,
                        'classification' => $classification,
                        'aliases' => $aliases,
                    ], static fn (mixed $value): bool => $value !== [] && $value !== ''),
                ),
            ],
        );
    }
}
