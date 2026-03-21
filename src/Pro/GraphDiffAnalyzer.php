<?php
declare(strict_types=1);

namespace Foundry\Pro;

use Foundry\Compiler\ApplicationGraph;
use Foundry\Compiler\IR\GraphNode;

final class GraphDiffAnalyzer
{
    /**
     * @return array<string,mixed>
     */
    public function diff(?ApplicationGraph $baseline, ApplicationGraph $current): array
    {
        $baselineNodes = $baseline?->nodes() ?? [];
        $currentNodes = $current->nodes();
        $baselineEdges = $baseline?->edges() ?? [];
        $currentEdges = $current->edges();

        $addedNodeIds = array_values(array_diff(array_keys($currentNodes), array_keys($baselineNodes)));
        $removedNodeIds = array_values(array_diff(array_keys($baselineNodes), array_keys($currentNodes)));
        $commonNodeIds = array_values(array_intersect(array_keys($baselineNodes), array_keys($currentNodes)));

        $changedNodeIds = [];
        foreach ($commonNodeIds as $nodeId) {
            if ($this->normalizeNode($baselineNodes[$nodeId]) !== $this->normalizeNode($currentNodes[$nodeId])) {
                $changedNodeIds[] = $nodeId;
            }
        }

        $addedEdgeIds = array_values(array_diff(array_keys($currentEdges), array_keys($baselineEdges)));
        $removedEdgeIds = array_values(array_diff(array_keys($baselineEdges), array_keys($currentEdges)));
        $commonEdgeIds = array_values(array_intersect(array_keys($baselineEdges), array_keys($currentEdges)));

        $changedEdgeIds = [];
        foreach ($commonEdgeIds as $edgeId) {
            if ($baselineEdges[$edgeId]->toArray() !== $currentEdges[$edgeId]->toArray()) {
                $changedEdgeIds[] = $edgeId;
            }
        }

        sort($addedNodeIds);
        sort($removedNodeIds);
        sort($changedNodeIds);
        sort($addedEdgeIds);
        sort($removedEdgeIds);
        sort($changedEdgeIds);

        $affectedFeatures = [];
        foreach (array_merge($addedNodeIds, $removedNodeIds, $changedNodeIds) as $nodeId) {
            $node = $currentNodes[$nodeId] ?? $baselineNodes[$nodeId] ?? null;
            if ($node === null) {
                continue;
            }

            $feature = trim((string) ($node->payload()['feature'] ?? ''));
            if ($feature !== '') {
                $affectedFeatures[] = $feature;
            }
        }

        sort($affectedFeatures);

        return [
            'baseline_available' => $baseline !== null,
            'baseline' => $baseline !== null ? $this->graphSummary($baseline) : null,
            'current' => $this->graphSummary($current),
            'summary' => [
                'added_nodes' => count($addedNodeIds),
                'removed_nodes' => count($removedNodeIds),
                'changed_nodes' => count($changedNodeIds),
                'added_edges' => count($addedEdgeIds),
                'removed_edges' => count($removedEdgeIds),
                'changed_edges' => count($changedEdgeIds),
            ],
            'nodes' => [
                'added' => array_map(fn (string $nodeId): array => $this->summarizeNode($currentNodes[$nodeId]), $addedNodeIds),
                'removed' => array_map(fn (string $nodeId): array => $this->summarizeNode($baselineNodes[$nodeId]), $removedNodeIds),
                'changed' => array_map(
                    fn (string $nodeId): array => [
                        'before' => $this->summarizeNode($baselineNodes[$nodeId]),
                        'after' => $this->summarizeNode($currentNodes[$nodeId]),
                    ],
                    $changedNodeIds,
                ),
            ],
            'edges' => [
                'added' => array_map(fn (string $edgeId): array => $currentEdges[$edgeId]->toArray(), $addedEdgeIds),
                'removed' => array_map(fn (string $edgeId): array => $baselineEdges[$edgeId]->toArray(), $removedEdgeIds),
                'changed' => array_map(
                    fn (string $edgeId): array => [
                        'before' => $baselineEdges[$edgeId]->toArray(),
                        'after' => $currentEdges[$edgeId]->toArray(),
                    ],
                    $changedEdgeIds,
                ),
            ],
            'affected_features' => array_values(array_unique($affectedFeatures)),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function graphSummary(ApplicationGraph $graph): array
    {
        return [
            'graph_version' => $graph->graphVersion(),
            'framework_version' => $graph->frameworkVersion(),
            'compiled_at' => $graph->compiledAt(),
            'source_hash' => $graph->sourceHash(),
            'node_count' => count($graph->nodes()),
            'edge_count' => count($graph->edges()),
            'node_types' => $graph->nodeCountsByType(),
            'edge_types' => $graph->edgeCountsByType(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function summarizeNode(GraphNode $node): array
    {
        return [
            'node_id' => $node->id(),
            'type' => $node->type(),
            'label' => $this->nodeLabel($node),
            'feature' => trim((string) ($node->payload()['feature'] ?? '')) ?: null,
            'source_path' => $node->sourcePath(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizeNode(GraphNode $node): array
    {
        return [
            'type' => $node->type(),
            'source_path' => $node->sourcePath(),
            'source_region' => $node->sourceRegion(),
            'payload' => $node->payload(),
        ];
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
