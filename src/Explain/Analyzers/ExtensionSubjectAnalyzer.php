<?php
declare(strict_types=1);

namespace Foundry\Explain\Analyzers;

use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;
use Foundry\Explain\ExplainSupport;

final class ExtensionSubjectAnalyzer implements SubjectAnalyzerInterface
{
    public function supports(ExplainSubject $subject): bool
    {
        return $subject->kind === 'extension';
    }

    public function analyze(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): array
    {
        $extension = is_array($context->get('extension')) ? $context->get('extension') : $subject->metadata;

        return [
            'sections' => [
                ExplainSupport::section('extension', 'Extension', [
                    'name' => $extension['name'] ?? $subject->label,
                    'version' => $extension['version'] ?? null,
                    'description' => $extension['description'] ?? null,
                    'provides' => $extension['provides'] ?? [],
                    'packs' => $extension['packs'] ?? [],
                ]),
            ],
            'related_commands' => [
                $context->commandPrefix . ' inspect extension ' . $subject->label . ' --json',
                $context->commandPrefix . ' inspect compatibility --json',
            ],
        ];
    }
}
