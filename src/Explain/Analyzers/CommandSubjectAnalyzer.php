<?php
declare(strict_types=1);

namespace Foundry\Explain\Analyzers;

use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;
use Foundry\Explain\ExplainSupport;

final class CommandSubjectAnalyzer implements SubjectAnalyzerInterface
{
    public function supports(ExplainSubject $subject): bool
    {
        return $subject->kind === 'command';
    }

    public function analyze(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): array
    {
        $command = is_array($context->get('command')) ? $context->get('command') : $subject->metadata;

        return [
            'sections' => [
                ExplainSupport::section('command', 'Command', [
                    'signature' => $command['signature'] ?? $subject->label,
                    'usage' => $command['usage'] ?? null,
                    'summary' => $command['summary'] ?? null,
                    'stability' => $command['stability'] ?? null,
                    'availability' => $command['availability'] ?? null,
                    'classification' => $command['classification'] ?? null,
                ]),
            ],
            'related_commands' => [
                $context->commandPrefix . ' help ' . $subject->label . ' --json',
            ],
        ];
    }
}
