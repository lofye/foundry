<?php
declare(strict_types=1);

namespace Foundry\Explain\Analyzers;

use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;

final class ExtensionSubjectAnalyzer implements SubjectAnalyzerInterface
{
    public function supports(ExplainSubject $subject): bool
    {
        return $subject->kind === 'extension';
    }

    public function analyze(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): SubjectAnalysisResult
    {
        $extension = is_array($context->extensions()['subject'] ?? null) ? $context->extensions()['subject'] : $subject->metadata;
        $responsibilities = ['Register compiler capabilities for the application graph'];
        foreach ($this->flattenProvides($extension['provides'] ?? []) as $capability) {
            $responsibilities[] = 'Provide capability: ' . $capability;
        }

        return new SubjectAnalysisResult(
            responsibilities: $responsibilities,
            summaryInputs: [
                'name' => $extension['name'] ?? $subject->label,
                'description' => $extension['description'] ?? null,
                'provides' => $extension['provides'] ?? [],
                'packs' => $extension['packs'] ?? [],
            ],
        );
    }

    /**
     * @return array<int,string>
     */
    private function flattenProvides(mixed $provides): array
    {
        $flattened = [];
        foreach ((array) $provides as $value) {
            if (is_array($value)) {
                foreach ($value as $nested) {
                    $capability = trim((string) $nested);
                    if ($capability !== '') {
                        $flattened[] = $capability;
                    }
                }

                continue;
            }

            $capability = trim((string) $value);
            if ($capability !== '') {
                $flattened[] = $capability;
            }
        }

        return array_values(array_unique($flattened));
    }
}
