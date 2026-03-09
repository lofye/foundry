<?php
declare(strict_types=1);

namespace Foundry\Compiler\Analysis\Analyzers;

use Foundry\Compiler\Analysis\AnalyzerContext;
use Foundry\Compiler\Analysis\GraphAnalyzer;
use Foundry\Compiler\ApplicationGraph;
use Foundry\Compiler\Diagnostics\DiagnosticBag;

final class SchemaIntegrityAnalyzer implements GraphAnalyzer
{
    public function id(): string
    {
        return 'schema_integrity';
    }

    public function description(): string
    {
        return 'Checks schema contracts against query shapes using deterministic heuristics.';
    }

    /**
     * @return array<string,mixed>
     */
    public function analyze(ApplicationGraph $graph, AnalyzerContext $context, DiagnosticBag $diagnostics): array
    {
        $mismatches = [];

        foreach ($graph->nodesByType('feature') as $featureNode) {
            $payload = $featureNode->payload();
            $feature = (string) ($payload['feature'] ?? '');
            if ($feature === '' || !$context->includesFeature($feature)) {
                continue;
            }

            $outputSchema = $payload['output_schema'] ?? null;
            if (!is_array($outputSchema)) {
                continue;
            }

            $required = array_values(array_filter(array_map('strval', (array) ($outputSchema['required'] ?? []))));
            sort($required);
            if ($required === []) {
                continue;
            }

            $queries = is_array($payload['queries'] ?? null) ? $payload['queries'] : [];
            if ($queries === []) {
                continue;
            }

            $sqlCorpus = '';
            foreach ($queries as $query) {
                if (!is_array($query)) {
                    continue;
                }
                $sqlCorpus .= ' ' . strtolower((string) ($query['sql'] ?? ''));
            }

            foreach ($required as $field) {
                if ($field === '') {
                    continue;
                }

                $pattern = '/\b' . preg_quote(strtolower($field), '/') . '\b/';
                if (preg_match($pattern, $sqlCorpus) === 1) {
                    continue;
                }

                $mismatches[] = [
                    'feature' => $feature,
                    'field' => $field,
                ];

                $diagnostics->warning(
                    code: 'FDY9004_SCHEMA_QUERY_MISMATCH',
                    category: 'schemas',
                    message: sprintf(
                        'Feature %s output schema requires field %s, but discovered queries do not appear to project it.',
                        $feature,
                        $field,
                    ),
                    nodeId: $featureNode->id(),
                    suggestedFix: 'Update output schema or query SQL so required fields align.',
                    pass: 'doctor.' . $this->id(),
                );
            }
        }

        usort(
            $mismatches,
            static fn (array $a, array $b): int => strcmp(
                (string) ($a['feature'] ?? '') . ':' . (string) ($a['field'] ?? ''),
                (string) ($b['feature'] ?? '') . ':' . (string) ($b['field'] ?? ''),
            ),
        );

        return [
            'mismatches' => $mismatches,
            'count' => count($mismatches),
        ];
    }
}

