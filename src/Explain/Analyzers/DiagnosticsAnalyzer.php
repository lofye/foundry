<?php
declare(strict_types=1);

namespace Foundry\Explain\Analyzers;

use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;

final class DiagnosticsAnalyzer implements SectionAnalyzerInterface
{
    public function supports(ExplainSubject $subject): bool
    {
        return true;
    }

    public function sectionId(): string
    {
        return 'diagnostics';
    }

    public function analyze(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): array
    {
        if (!$options->includeDiagnostics) {
            return [
                'summary' => ['error' => 0, 'warning' => 0, 'info' => 0, 'total' => 0],
                'items' => [],
            ];
        }

        return $context->diagnostics()->toArray();
    }
}
