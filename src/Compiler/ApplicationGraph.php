<?php
declare(strict_types=1);

namespace Foundry\Compiler;

use Foundry\Compiler\Diagnostics\DiagnosticBag;
use Foundry\Compiler\IR\GraphNode;
use Foundry\Compiler\IR\NodeFactory;

final class ApplicationGraph
{
    /**
     * @var array<string,GraphNode>
     */
    private array $nodes = [];

    /**
     * @var array<string,GraphEdge>
     */
    private array $edges = [];

    /**
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        private int $graphVersion,
        private string $frameworkVersion,
        private string $compiledAt,
        private string $sourceHash,
        private array $metadata = [],
    ) {
    }

    public function graphVersion(): int
    {
        return $this->graphVersion;
    }

    public function frameworkVersion(): string
    {
        return $this->frameworkVersion;
    }

    public function compiledAt(): string
    {
        return $this->compiledAt;
    }

    public function sourceHash(): string
    {
        return $this->sourceHash;
    }

    /**
     * @param array<string,mixed> $metadata
     */
    public function setMetadata(array $metadata): void
    {
        $this->metadata = $metadata;
    }

    /**
     * @return array<string,mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    public function setBuildInfo(string $frameworkVersion, string $compiledAt, string $sourceHash): void
    {
        $this->frameworkVersion = $frameworkVersion;
        $this->compiledAt = $compiledAt;
        $this->sourceHash = $sourceHash;
    }

    public function addNode(GraphNode $node): void
    {
        $this->nodes[$node->id()] = $node;
    }

    public function removeNode(string $nodeId): void
    {
        unset($this->nodes[$nodeId]);
        foreach ($this->edges as $edgeId => $edge) {
            if ($edge->from === $nodeId || $edge->to === $nodeId) {
                unset($this->edges[$edgeId]);
            }
        }
    }

    public function hasNode(string $nodeId): bool
    {
        return isset($this->nodes[$nodeId]);
    }

    public function node(string $nodeId): ?GraphNode
    {
        return $this->nodes[$nodeId] ?? null;
    }

    /**
     * @return array<string,GraphNode>
     */
    public function nodes(): array
    {
        ksort($this->nodes);

        return $this->nodes;
    }

    /**
     * @return array<string,GraphNode>
     */
    public function nodesByType(string $type): array
    {
        $nodes = array_filter(
            $this->nodes,
            static fn (GraphNode $node): bool => $node->type() === $type,
        );
        ksort($nodes);

        return $nodes;
    }

    public function addEdge(GraphEdge $edge): void
    {
        $this->edges[$edge->id] = $edge;
    }

    public function clearEdges(): void
    {
        $this->edges = [];
    }

    /**
     * @return array<string,GraphEdge>
     */
    public function edges(): array
    {
        ksort($this->edges);

        return $this->edges;
    }

    /**
     * @return array<int,GraphEdge>
     */
    public function dependencies(string $nodeId): array
    {
        $edges = array_values(array_filter(
            $this->edges,
            static fn (GraphEdge $edge): bool => $edge->from === $nodeId,
        ));

        usort($edges, static fn (GraphEdge $a, GraphEdge $b): int => strcmp($a->id, $b->id));

        return $edges;
    }

    /**
     * @return array<int,GraphEdge>
     */
    public function dependents(string $nodeId): array
    {
        $edges = array_values(array_filter(
            $this->edges,
            static fn (GraphEdge $edge): bool => $edge->to === $nodeId,
        ));

        usort($edges, static fn (GraphEdge $a, GraphEdge $b): int => strcmp($a->id, $b->id));

        return $edges;
    }

    /**
     * @return array<int,string>
     */
    public function features(): array
    {
        $features = [];
        foreach ($this->nodesByType('feature') as $node) {
            $payload = $node->payload();
            $feature = (string) ($payload['feature'] ?? '');
            if ($feature !== '') {
                $features[] = $feature;
            }
        }

        sort($features);

        return array_values(array_unique($features));
    }

    public function removeFeature(string $feature): void
    {
        foreach ($this->nodes as $nodeId => $node) {
            $payload = $node->payload();
            if ((string) ($payload['feature'] ?? '') === $feature) {
                $this->removeNode($nodeId);
            }
        }
    }

    public function retainOnlyFeatureNodes(): void
    {
        foreach ($this->nodes as $nodeId => $node) {
            if ($node->type() !== 'feature') {
                unset($this->nodes[$nodeId]);
            }
        }

        $this->edges = [];
    }

    /**
     * @return array<string,int>
     */
    public function nodeCountsByType(): array
    {
        $counts = [];
        foreach ($this->nodes as $node) {
            $counts[$node->type()] = ($counts[$node->type()] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @return array<string,int>
     */
    public function edgeCountsByType(): array
    {
        $counts = [];
        foreach ($this->edges as $edge) {
            $counts[$edge->type] = ($counts[$edge->type] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(DiagnosticBag $diagnostics): array
    {
        $nodeDiagnosticMap = $diagnostics->nodeDiagnosticMap();

        $dependencyMap = [];
        $dependentMap = [];
        foreach ($this->edges as $edge) {
            $dependencyMap[$edge->from] ??= [];
            $dependencyMap[$edge->from][] = $edge->to;

            $dependentMap[$edge->to] ??= [];
            $dependentMap[$edge->to][] = $edge->from;
        }

        foreach ($dependencyMap as &$row) {
            sort($row);
            $row = array_values(array_unique($row));
        }
        unset($row);

        foreach ($dependentMap as &$row) {
            sort($row);
            $row = array_values(array_unique($row));
        }
        unset($row);

        $nodeRows = [];
        foreach ($this->nodes() as $node) {
            $row = $node->toArray();
            $row['diagnostic_ids'] = $nodeDiagnosticMap[$node->id()] ?? [];
            $row['dependency_metadata'] = [
                'dependencies' => $dependencyMap[$node->id()] ?? [],
                'dependents' => $dependentMap[$node->id()] ?? [],
            ];
            $nodeRows[] = $row;
        }

        $edgeRows = [];
        foreach ($this->edges() as $edge) {
            $edgeRows[] = $edge->toArray();
        }

        return [
            'graph_version' => $this->graphVersion,
            'framework_version' => $this->frameworkVersion,
            'compiled_at' => $this->compiledAt,
            'source_hash' => $this->sourceHash,
            'metadata' => $this->metadata,
            'summary' => [
                'node_counts' => $this->nodeCountsByType(),
                'edge_counts' => $this->edgeCountsByType(),
                'feature_count' => count($this->features()),
            ],
            'nodes' => $nodeRows,
            'edges' => $edgeRows,
        ];
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $graph = new self(
            graphVersion: (int) ($row['graph_version'] ?? 1),
            frameworkVersion: (string) ($row['framework_version'] ?? 'unknown'),
            compiledAt: (string) ($row['compiled_at'] ?? gmdate(DATE_ATOM)),
            sourceHash: (string) ($row['source_hash'] ?? ''),
            metadata: is_array($row['metadata'] ?? null) ? $row['metadata'] : [],
        );

        foreach ((array) ($row['nodes'] ?? []) as $nodeRow) {
            if (!is_array($nodeRow)) {
                continue;
            }

            $graph->addNode(NodeFactory::fromArray($nodeRow));
        }

        foreach ((array) ($row['edges'] ?? []) as $edgeRow) {
            if (!is_array($edgeRow)) {
                continue;
            }

            $id = (string) ($edgeRow['id'] ?? '');
            $type = (string) ($edgeRow['type'] ?? '');
            $from = (string) ($edgeRow['from'] ?? '');
            $to = (string) ($edgeRow['to'] ?? '');
            if ($id === '' || $type === '' || $from === '' || $to === '') {
                continue;
            }

            $graph->addEdge(new GraphEdge(
                id: $id,
                type: $type,
                from: $from,
                to: $to,
                payload: is_array($edgeRow['payload'] ?? null) ? $edgeRow['payload'] : [],
            ));
        }

        return $graph;
    }
}
