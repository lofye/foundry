<?php

declare(strict_types=1);

namespace Foundry\Explain\Analyzers;

use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;

final class PackSubjectAnalyzer implements SubjectAnalyzerInterface
{
    public function supports(ExplainSubject $subject): bool
    {
        return $subject->kind === 'pack';
    }

    public function analyze(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): SubjectAnalysisResult
    {
        $pack = is_array($context->extensions()['subject'] ?? null) ? $context->extensions()['subject'] : $subject->metadata;
        $manifest = is_array($pack['pack_manifest'] ?? null) ? $pack['pack_manifest'] : [];
        $contributions = is_array($pack['declared_contributions'] ?? null) ? $pack['declared_contributions'] : [];
        $capabilities = array_values(array_filter(array_map('strval', (array) ($manifest['capabilities'] ?? []))));
        sort($capabilities);

        $responsibilities = ['Register deterministic pack contributions into the application graph'];
        foreach ($capabilities as $capability) {
            $responsibilities[] = 'Provide capability: ' . $capability;
        }

        foreach ($this->contributionSummaryItems($contributions) as $item) {
            $responsibilities[] = $item;
        }

        return new SubjectAnalysisResult(
            responsibilities: $responsibilities,
            summaryInputs: [
                'name' => $manifest['name'] ?? $subject->label,
                'description' => $manifest['description'] ?? null,
                'version' => $manifest['version'] ?? null,
                'capabilities' => $capabilities,
                'contributions' => $contributions,
            ],
            sections: array_filter([
                \Foundry\Explain\ExplainSupport::section(
                    'pack_capabilities',
                    'Pack Capabilities',
                    array_filter([
                        'capabilities' => $capabilities,
                        'entry' => (string) ($manifest['entry'] ?? ''),
                        'version' => (string) ($manifest['version'] ?? ''),
                    ], static fn(mixed $value): bool => $value !== [] && $value !== ''),
                ),
                $contributions !== []
                    ? \Foundry\Explain\ExplainSupport::section(
                        'pack_contributions',
                        'Pack Contributions',
                        array_filter($contributions, static fn(mixed $value): bool => is_array($value) && $value !== []),
                    )
                    : null,
                !empty($pack['graph_nodes']) && is_array($pack['graph_nodes'])
                    ? \Foundry\Explain\ExplainSupport::section(
                        'pack_graph_nodes',
                        'Pack Graph Nodes',
                        array_values(array_map(
                            static fn(array $node): string => (string) ($node['id'] ?? $node['label'] ?? ''),
                            array_values(array_filter($pack['graph_nodes'], 'is_array')),
                        )),
                        'string_list',
                    )
                    : null,
            ]),
        );
    }

    /**
     * @param array<string,mixed> $contributions
     * @return array<int,string>
     */
    private function contributionSummaryItems(array $contributions): array
    {
        $items = [];
        foreach ($contributions as $kind => $values) {
            if (!is_string($kind) || !is_array($values) || $values === []) {
                continue;
            }

            foreach (array_values(array_filter(array_map('strval', $values))) as $value) {
                $items[] = sprintf('Declare %s: %s', str_replace('_', ' ', $kind), $value);
            }
        }

        sort($items);

        return $items;
    }
}
