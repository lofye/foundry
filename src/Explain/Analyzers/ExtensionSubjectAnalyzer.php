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
        $capabilities = $this->flattenProvides($extension['provides'] ?? []);
        $packs = array_values(array_filter(array_map('strval', (array) ($extension['packs'] ?? []))));
        $dependencies = array_values(array_filter(array_map('strval', (array) ($extension['dependencies'] ?? []))));
        $responsibilities = ['Register compiler capabilities for the application graph'];
        foreach ($capabilities as $capability) {
            $responsibilities[] = 'Provide capability: ' . $capability;
        }
        foreach ($packs as $pack) {
            $responsibilities[] = 'Ship pack: ' . $pack;
        }

        return new SubjectAnalysisResult(
            responsibilities: $responsibilities,
            summaryInputs: [
                'name' => $extension['name'] ?? $subject->label,
                'description' => $extension['description'] ?? null,
                'provides' => $capabilities,
                'packs' => $packs,
                'dependencies' => $dependencies,
            ],
            sections: [
                \Foundry\Explain\ExplainSupport::section(
                    'extension_capabilities',
                    'Extension Capabilities',
                    array_merge(
                        array_map(static fn (string $capability): string => 'capability: ' . $capability, $capabilities),
                        array_map(static fn (string $pack): string => 'pack: ' . $pack, $packs),
                        array_map(static fn (string $dependency): string => 'dependency: ' . $dependency, $dependencies),
                    ),
                    'string_list',
                ),
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
