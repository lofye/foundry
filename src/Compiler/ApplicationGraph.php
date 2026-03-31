<?php

declare(strict_types=1);

namespace Foundry\Compiler;

use Foundry\Compiler\Diagnostics\DiagnosticBag;
use Foundry\Compiler\GraphSpec\CanonicalGraphSpecification;
use Foundry\Compiler\GraphSpec\GraphArtifactMigrator;
use Foundry\Compiler\GraphSpec\IllegalGraphEdge;
use Foundry\Compiler\GraphSpec\UnknownGraphEdgeType;
use Foundry\Compiler\IR\GraphNode;
use Foundry\Compiler\IR\NodeFactory;
use Foundry\Support\Json;

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
     * @var array<string,array<string,GraphEdge>>
     */
    private array $outgoingEdgesByNode = [];

    /**
     * @var array<string,array<string,GraphEdge>>
     */
    private array $incomingEdgesByNode = [];

    /**
     * @var array<string,array<string,GraphEdge>>
     */
    private array $edgesByTypeIndex = [];

    /**
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        private int $graphVersion,
        private string $frameworkVersion,
        private string $compiledAt,
        private string $sourceHash,
        private array $metadata = [],
    ) {}

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
        if (isset($this->nodes[$node->id()])) {
            throw new \RuntimeException(sprintf('Duplicate graph node id %s cannot be inserted.', $node->id()));
        }

        $this->nodes[$node->id()] = $node;
    }

    public function removeNode(string $nodeId): void
    {
        unset($this->nodes[$nodeId]);

        foreach (array_keys($this->outgoingEdgesByNode[$nodeId] ?? []) as $edgeId) {
            unset($this->edges[$edgeId]);
        }
        foreach (array_keys($this->incomingEdgesByNode[$nodeId] ?? []) as $edgeId) {
            unset($this->edges[$edgeId]);
        }

        unset($this->outgoingEdgesByNode[$nodeId], $this->incomingEdgesByNode[$nodeId]);
        $this->rebuildEdgeIndexes();
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
        $nodes = $this->nodes;
        ksort($nodes);

        return $nodes;
    }

    /**
     * @return array<string,GraphNode>
     */
    public function nodesByType(string $type): array
    {
        $nodes = array_filter(
            $this->nodes,
            static fn(GraphNode $node): bool => $node->type() === $type,
        );
        ksort($nodes);

        return $nodes;
    }

    /**
     * @return array<string,GraphNode>
     */
    public function nodesByCategory(string $category): array
    {
        $spec = CanonicalGraphSpecification::instance();

        $nodes = array_filter(
            $this->nodes,
            static function (GraphNode $node) use ($category, $spec): bool {
                return $spec->nodeType($node->type())?->semanticCategory === $category;
            },
        );
        ksort($nodes);

        return $nodes;
    }

    public function addEdge(GraphEdge $edge): void
    {
        $this->edges[$edge->id] = $edge;
        $this->indexEdge($edge);
    }

    public function addVerifiedEdge(GraphEdge $edge): void
    {
        $spec = CanonicalGraphSpecification::instance();
        $definition = $spec->edgeType($edge->type);
        if ($definition === null) {
            throw new UnknownGraphEdgeType($edge->type, $edge->id);
        }

        $fromNode = $this->node($edge->from);
        $toNode = $this->node($edge->to);
        if (!$fromNode instanceof GraphNode) {
            throw new IllegalGraphEdge(
                sprintf('Graph edge %s references missing source node %s.', $edge->id, $edge->from),
            );
        }

        if (!$toNode instanceof GraphNode) {
            throw new IllegalGraphEdge(
                sprintf('Graph edge %s references missing target node %s.', $edge->id, $edge->to),
            );
        }

        if (!$definition->allowsSourceType($fromNode->type()) || !$definition->allowsTargetType($toNode->type())) {
            throw new IllegalGraphEdge(
                sprintf('Graph edge %s is not legal between %s and %s.', $edge->type, $fromNode->type(), $toNode->type()),
            );
        }

        if (isset($this->edges[$edge->id])) {
            throw new IllegalGraphEdge(
                sprintf('Duplicate graph edge id %s cannot be inserted.', $edge->id),
            );
        }

        $this->addEdge($edge);
    }

    public function clearEdges(): void
    {
        $this->edges = [];
        $this->outgoingEdgesByNode = [];
        $this->incomingEdgesByNode = [];
        $this->edgesByTypeIndex = [];
    }

    /**
     * @return array<string,GraphEdge>
     */
    public function edges(): array
    {
        $edges = $this->edges;
        ksort($edges);

        return $edges;
    }

    /**
     * @return array<string,GraphEdge>
     */
    public function edgesByType(string $type): array
    {
        $edges = $this->edgesByTypeIndex[$type] ?? [];
        ksort($edges);

        return $edges;
    }

    /**
     * @return array<int,GraphEdge>
     */
    public function dependencies(string $nodeId): array
    {
        return $this->sortedEdgeList($this->outgoingEdgesByNode[$nodeId] ?? []);
    }

    /**
     * @return array<int,GraphEdge>
     */
    public function dependents(string $nodeId): array
    {
        return $this->sortedEdgeList($this->incomingEdgesByNode[$nodeId] ?? []);
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

        $this->clearEdges();
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
    public function nodeCountsByCategory(): array
    {
        $counts = [];
        $spec = CanonicalGraphSpecification::instance();
        foreach ($this->nodes as $node) {
            $category = (string) ($spec->nodeType($node->type())?->semanticCategory ?? 'unknown');
            $counts[$category] = ($counts[$category] ?? 0) + 1;
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

    public function fingerprint(): string
    {
        return $this->stableHash([
            'graph_version' => $this->graphVersion,
            'nodes' => array_map(static fn(GraphNode $node): array => $node->toArray(), $this->nodes()),
            'edges' => array_map(static fn(GraphEdge $edge): array => $edge->toArray(), $this->edges()),
        ]);
    }

    public function topologyFingerprint(): string
    {
        return $this->stableHash([
            'nodes' => array_map(static fn(GraphNode $node): array => [
                'id' => $node->id(),
                'type' => $node->type(),
            ], $this->nodes()),
            'edges' => array_map(static fn(GraphEdge $edge): array => [
                'id' => $edge->id,
                'type' => $edge->type,
                'from' => $edge->from,
                'to' => $edge->to,
            ], $this->edges()),
        ]);
    }

    public function payloadStructureFingerprint(): string
    {
        return $this->stableHash([
            'nodes' => array_map(fn(GraphNode $node): array => $this->payloadShape($node->payload()), $this->nodes()),
            'edges' => array_map(fn(GraphEdge $edge): array => $this->payloadShape($edge->payload), $this->edges()),
        ]);
    }

    public function featureSubgraph(string $feature): self
    {
        $featureId = 'feature:' . $feature;
        if (!$this->hasNode($featureId)) {
            return $this->emptyClone();
        }

        $spec = CanonicalGraphSpecification::instance();
        $allowedRoles = ['ownership', 'execution', 'dependency', 'publication', 'invalidation', 'observational'];

        $selected = [$featureId => true];
        $queue = [$featureId];

        while ($queue !== []) {
            $nodeId = array_shift($queue);
            if (!is_string($nodeId)) {
                continue;
            }

            foreach (array_merge($this->dependencies($nodeId), $this->dependents($nodeId)) as $edge) {
                $edgeDefinition = $spec->edgeType($edge->type);
                if ($edgeDefinition === null) {
                    continue;
                }

                if (array_intersect($allowedRoles, $edgeDefinition->roles) === []) {
                    continue;
                }

                $other = $edge->from === $nodeId ? $edge->to : $edge->from;
                if (isset($selected[$other])) {
                    continue;
                }

                $selected[$other] = true;
                $queue[] = $other;
            }
        }

        return $this->subgraph(array_keys($selected));
    }

    public function executionSubgraph(?string $feature = null): self
    {
        $spec = CanonicalGraphSpecification::instance();
        $nodeIds = [];

        foreach ($this->nodes as $nodeId => $node) {
            $definition = $spec->nodeType($node->type());
            if (($definition?->participatesInExecutionTopology ?? false) || $definition?->semanticCategory === 'execution') {
                $nodeIds[$nodeId] = true;
            }
        }

        if ($feature !== null && $feature !== '') {
            $featureGraph = $this->featureSubgraph($feature);

            return $featureGraph->executionSubgraph();
        }

        return $this->subgraph(array_keys($nodeIds), function (GraphEdge $edge) use ($spec): bool {
            return $spec->edgeType($edge->type)?->hasRole('execution') ?? false;
        });
    }

    public function ownershipSubgraph(?string $feature = null): self
    {
        $spec = CanonicalGraphSpecification::instance();

        if ($feature !== null && $feature !== '') {
            $featureGraph = $this->featureSubgraph($feature);

            return $featureGraph->ownershipSubgraph();
        }

        $nodeIds = [];
        foreach ($this->nodes as $nodeId => $node) {
            if ($spec->nodeType($node->type())?->participatesInOwnershipTopology ?? false) {
                $nodeIds[$nodeId] = true;
            }
        }

        return $this->subgraph(array_keys($nodeIds), function (GraphEdge $edge) use ($spec): bool {
            return $spec->edgeType($edge->type)?->hasRole('ownership') ?? false;
        });
    }

    public function observabilitySubgraph(?string $feature = null): self
    {
        $spec = CanonicalGraphSpecification::instance();

        if ($feature !== null && $feature !== '') {
            $featureGraph = $this->featureSubgraph($feature);

            return $featureGraph->observabilitySubgraph();
        }

        $nodeIds = [];
        foreach ($this->nodes as $nodeId => $node) {
            $definition = $spec->nodeType($node->type());
            if (($definition?->traceable ?? false) || ($definition?->profileable ?? false) || $definition?->semanticCategory === 'observational') {
                $nodeIds[$nodeId] = true;
            }
        }

        return $this->subgraph(array_keys($nodeIds), function (GraphEdge $edge) use ($spec): bool {
            $definition = $spec->edgeType($edge->type);

            return ($definition?->hasRole('observational') ?? false)
                || ($definition?->hasRole('execution') ?? false)
                || ($definition?->hasRole('publication') ?? false);
        });
    }

    /**
     * @param array<int,string> $nodeIds
     */
    public function subgraph(array $nodeIds, ?callable $edgeFilter = null): self
    {
        $selected = array_fill_keys(array_values(array_unique(array_map('strval', $nodeIds))), true);
        $graph = $this->emptyClone();

        foreach ($this->nodes() as $nodeId => $node) {
            if (isset($selected[$nodeId])) {
                $graph->addNode($node);
            }
        }

        foreach ($this->edges() as $edge) {
            if (!isset($selected[$edge->from], $selected[$edge->to])) {
                continue;
            }
            if ($edgeFilter !== null && $edgeFilter($edge) !== true) {
                continue;
            }

            $graph->addEdge($edge);
        }

        return $graph;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(DiagnosticBag $diagnostics): array
    {
        $spec = CanonicalGraphSpecification::instance();
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
            $definition = $spec->nodeType($node->type());
            $row = $node->toArray();
            $row['semantic_category'] = $definition?->semanticCategory;
            $row['runtime_scope'] = $definition?->runtimeScope;
            $row['participates_in_execution_topology'] = $definition?->participatesInExecutionTopology ?? false;
            $row['participates_in_ownership_topology'] = $definition?->participatesInOwnershipTopology ?? false;
            $row['diagnostic_ids'] = $nodeDiagnosticMap[$node->id()] ?? [];
            $row['dependency_metadata'] = [
                'dependencies' => $dependencyMap[$node->id()] ?? [],
                'dependents' => $dependentMap[$node->id()] ?? [],
            ];
            $nodeRows[] = $row;
        }

        $edgeRows = [];
        foreach ($this->edges() as $edge) {
            $definition = $spec->edgeType($edge->type);
            $row = $edge->toArray();
            $row['semantic_class'] = $definition?->semanticClass;
            $row['roles'] = $definition?->roles ?? [];
            $edgeRows[] = $row;
        }

        return [
            'graph_version' => $this->graphVersion,
            'graph_spec_version' => $spec->specVersion(),
            'framework_version' => $this->frameworkVersion,
            'compiled_at' => $this->compiledAt,
            'source_hash' => $this->sourceHash,
            'metadata' => $this->metadata,
            'graph_metadata' => [
                'graph_version' => $this->graphVersion,
                'framework_version' => $this->frameworkVersion,
                'compiled_at' => $this->compiledAt,
                'source_hash' => $this->sourceHash,
                'metadata' => $this->metadata,
            ],
            'summary' => [
                'node_counts' => $this->nodeCountsByType(),
                'node_categories' => $this->nodeCountsByCategory(),
                'edge_counts' => $this->edgeCountsByType(),
                'feature_count' => count($this->features()),
            ],
            'integrity' => [
                'status' => 'unverified',
                'fingerprints' => [
                    'graph' => $this->fingerprint(),
                    'topology' => $this->topologyFingerprint(),
                    'payload_structure' => $this->payloadStructureFingerprint(),
                ],
            ],
            'compatibility' => [
                'current_graph_version' => $spec->currentGraphVersion(),
                'supported_graph_versions' => $spec->supportedGraphVersions(),
            ],
            'observability' => [
                'node_correlation_field' => 'id',
                'edge_correlation_field' => 'id',
                'traceable_node_types' => $spec->traceableNodeTypes(),
                'profileable_node_types' => $spec->profileableNodeTypes(),
                'build_association' => [
                    'compiled_at' => $this->compiledAt,
                    'source_hash' => $this->sourceHash,
                ],
                'run_association' => [
                    'build_id' => substr(hash('sha256', $this->sourceHash . ':' . $this->compiledAt), 0, 16),
                ],
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
        $migrated = (new GraphArtifactMigrator())->migrate($row);

        $graph = new self(
            graphVersion: (int) ($migrated['graph_version'] ?? 1),
            frameworkVersion: (string) ($migrated['framework_version'] ?? 'unknown'),
            compiledAt: (string) ($migrated['compiled_at'] ?? gmdate(DATE_ATOM)),
            sourceHash: (string) ($migrated['source_hash'] ?? ''),
            metadata: is_array($migrated['metadata'] ?? null) ? $migrated['metadata'] : [],
        );

        foreach ((array) ($migrated['nodes'] ?? []) as $nodeRow) {
            if (!is_array($nodeRow)) {
                continue;
            }

            $graph->addNode(NodeFactory::fromArray($nodeRow));
        }

        foreach ((array) ($migrated['edges'] ?? []) as $edgeRow) {
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

            $graph->addVerifiedEdge(new GraphEdge(
                id: $id,
                type: $type,
                from: $from,
                to: $to,
                payload: is_array($edgeRow['payload'] ?? null) ? $edgeRow['payload'] : [],
            ));
        }

        return $graph;
    }

    private function indexEdge(GraphEdge $edge): void
    {
        $this->outgoingEdgesByNode[$edge->from][$edge->id] = $edge;
        $this->incomingEdgesByNode[$edge->to][$edge->id] = $edge;
        $this->edgesByTypeIndex[$edge->type][$edge->id] = $edge;
    }

    private function rebuildEdgeIndexes(): void
    {
        $this->outgoingEdgesByNode = [];
        $this->incomingEdgesByNode = [];
        $this->edgesByTypeIndex = [];

        foreach ($this->edges as $edge) {
            $this->indexEdge($edge);
        }
    }

    /**
     * @param array<string,GraphEdge> $edges
     * @return array<int,GraphEdge>
     */
    private function sortedEdgeList(array $edges): array
    {
        ksort($edges);

        return array_values($edges);
    }

    private function emptyClone(): self
    {
        return new self(
            graphVersion: $this->graphVersion,
            frameworkVersion: $this->frameworkVersion,
            compiledAt: $this->compiledAt,
            sourceHash: $this->sourceHash,
            metadata: $this->metadata,
        );
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function payloadShape(array $payload): array
    {
        if ($payload !== [] && array_keys($payload) === range(0, count($payload) - 1)) {
            $shapes = array_map(function (mixed $value): mixed {
                if (is_array($value)) {
                    return $this->payloadShape($value);
                }

                return get_debug_type($value);
            }, $payload);
            usort($shapes, static fn(mixed $a, mixed $b): int => strcmp(serialize($a), serialize($b)));

            return ['items' => array_values(array_unique($shapes, SORT_REGULAR))];
        }

        $shape = [];
        foreach ($payload as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if (is_array($value)) {
                $shape[$key] = $this->payloadShape($value);
                continue;
            }

            $shape[$key] = get_debug_type($value);
        }

        ksort($shape);

        return $shape;
    }

    /**
     * @param array<string,mixed> $value
     */
    private function stableHash(array $value): string
    {
        $normalized = $this->sortRecursive($value);
        $encoded = Json::encode($normalized, true);

        return hash('sha256', $encoded);
    }

    private function sortRecursive(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $isAssoc = $value !== [] && array_keys($value) !== range(0, count($value) - 1);
        if ($isAssoc) {
            ksort($value);
            foreach ($value as $key => $row) {
                $value[$key] = $this->sortRecursive($row);
            }

            return $value;
        }

        $normalized = array_map(fn(mixed $row): mixed => $this->sortRecursive($row), $value);
        usort($normalized, static fn(mixed $a, mixed $b): int => strcmp(serialize($a), serialize($b)));

        return array_values($normalized);
    }
}
