<?php

declare(strict_types=1);

namespace Foundry\Explain\Analyzers;

use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;
use Foundry\Explain\ExplainSupport;

final class GraphRelationshipsAnalyzer implements SectionAnalyzerInterface
{
    public function supports(ExplainSubject $subject): bool
    {
        return $subject->graphNodeIds !== [];
    }

    public function sectionId(): string
    {
        return 'graph_relationships';
    }

    public function analyze(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): array
    {
        if (!$options->includeNeighbors) {
            return [
                'inbound' => [],
                'outbound' => [],
                'lateral' => [],
            ];
        }

        $neighborhood = $context->graphNeighborhood();
        $inbound = ExplainSupport::uniqueRows(array_values(array_filter((array) ($neighborhood['inbound'] ?? []), 'is_array')));
        $outbound = ExplainSupport::uniqueRows(array_values(array_filter((array) ($neighborhood['outbound'] ?? []), 'is_array')));
        $neighbors = ExplainSupport::uniqueRows(array_values(array_filter((array) ($neighborhood['neighbors'] ?? []), 'is_array')));

        $known = [];
        foreach (array_merge($inbound, $outbound) as $row) {
            $known[(string) ($row['id'] ?? $row['label'] ?? '')] = true;
        }

        $lateral = [];
        foreach ($neighbors as $row) {
            $key = (string) ($row['id'] ?? $row['label'] ?? '');
            if ($key === '' || isset($known[$key])) {
                continue;
            }

            $lateral[] = $row;
        }

        return [
            'inbound' => $inbound,
            'outbound' => $outbound,
            'lateral' => ExplainSupport::uniqueRows($lateral),
        ];
    }
}
