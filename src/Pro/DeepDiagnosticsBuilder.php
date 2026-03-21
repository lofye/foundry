<?php
declare(strict_types=1);

namespace Foundry\Pro;

use Foundry\Compiler\ApplicationGraph;
use Foundry\Compiler\IR\GraphNode;

final class DeepDiagnosticsBuilder
{
    /**
     * @return array<string,mixed>
     */
    public function build(ApplicationGraph $graph, ?string $featureFilter = null): array
    {
        $nodes = $graph->nodes();
        $edges = $graph->edges();

        $hotspots = [];
        foreach ($nodes as $node) {
            $dependencyCount = count($graph->dependencies($node->id()));
            $dependentCount = count($graph->dependents($node->id()));

            $hotspots[] = [
                'node_id' => $node->id(),
                'type' => $node->type(),
                'label' => $this->nodeLabel($node),
                'feature' => $this->nodeFeature($node),
                'dependencies' => $dependencyCount,
                'dependents' => $dependentCount,
                'connections' => $dependencyCount + $dependentCount,
            ];
        }

        usort($hotspots, static function (array $left, array $right): int {
            $connectionComparison = (int) ($right['connections'] ?? 0) <=> (int) ($left['connections'] ?? 0);
            if ($connectionComparison !== 0) {
                return $connectionComparison;
            }

            return strcmp((string) ($left['node_id'] ?? ''), (string) ($right['node_id'] ?? ''));
        });

        return [
            'graph' => [
                'node_count' => count($nodes),
                'edge_count' => count($edges),
                'features' => $graph->features(),
                'node_types' => $graph->nodeCountsByType(),
                'edge_types' => $graph->edgeCountsByType(),
            ],
            'hotspots' => array_slice($hotspots, 0, 10),
            'focus_feature' => $featureFilter !== null && $featureFilter !== ''
                ? $this->focusFeature($graph, $featureFilter)
                : null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function focusFeature(ApplicationGraph $graph, string $feature): array
    {
        $nodeIds = [];

        foreach ($graph->nodes() as $node) {
            if ($this->nodeFeature($node) === $feature) {
                $nodeIds[] = $node->id();
            }
        }

        sort($nodeIds);

        return [
            'feature' => $feature,
            'node_count' => count($nodeIds),
            'node_ids' => $nodeIds,
        ];
    }

    private function nodeFeature(GraphNode $node): ?string
    {
        $feature = trim((string) ($node->payload()['feature'] ?? ''));

        return $feature === '' ? null : $feature;
    }

    private function nodeLabel(GraphNode $node): string
    {
        $payload = $node->payload();

        foreach (['feature', 'signature', 'name', 'key', 'path'] as $key) {
            $value = trim((string) ($payload[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return $node->id();
    }
}
