<?php
declare(strict_types=1);

namespace Foundry\Pro;

use Foundry\Compiler\ApplicationGraph;
use Foundry\Compiler\Analysis\ImpactAnalyzer;
use Foundry\Compiler\GraphEdge;
use Foundry\Compiler\IR\GraphNode;
use Foundry\Support\FoundryError;

final readonly class ArchitectureExplainer
{
    public function __construct(private ImpactAnalyzer $impactAnalyzer)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function explain(ApplicationGraph $graph, string $target): array
    {
        $node = $this->resolveNode($graph, trim($target));
        if ($node === null) {
            throw new FoundryError(
                'PRO_EXPLAIN_TARGET_NOT_FOUND',
                'not_found',
                ['target' => $target],
                'Explain target not found in the application graph.',
            );
        }

        $dependencies = array_map(
            fn (GraphEdge $edge): array => $this->summarizeLinkedNode($graph, $edge->to, $edge->type),
            $graph->dependencies($node->id()),
        );
        $dependents = array_map(
            fn (GraphEdge $edge): array => $this->summarizeLinkedNode($graph, $edge->from, $edge->type),
            $graph->dependents($node->id()),
        );
        $impact = $this->impactAnalyzer->reportForNode($graph, $node->id());

        return [
            'target' => $target,
            'resolved_node_id' => $node->id(),
            'node' => $node->toArray(),
            'dependencies' => $dependencies,
            'dependents' => $dependents,
            'impact' => $impact,
            'explanation' => $this->renderExplanation($target, $node, $dependencies, $dependents, $impact),
        ];
    }

    private function resolveNode(ApplicationGraph $graph, string $target): ?GraphNode
    {
        if ($target === '') {
            return null;
        }

        foreach ([$target, 'feature:' . $target] as $nodeId) {
            $node = $graph->node($nodeId);
            if ($node !== null) {
                return $node;
            }
        }

        $matches = [];
        foreach ($graph->nodes() as $node) {
            $payload = $node->payload();

            foreach (['feature', 'signature', 'name', 'key', 'path'] as $key) {
                if (trim((string) ($payload[$key] ?? '')) === $target) {
                    $matches[] = $node;
                    continue 2;
                }
            }
        }

        usort(
            $matches,
            static fn (GraphNode $left, GraphNode $right): int => strcmp($left->id(), $right->id()),
        );

        return $matches[0] ?? null;
    }

    /**
     * @return array<string,mixed>
     */
    private function summarizeLinkedNode(ApplicationGraph $graph, string $nodeId, string $edgeType): array
    {
        $node = $graph->node($nodeId);
        if ($node === null) {
            return [
                'node_id' => $nodeId,
                'edge_type' => $edgeType,
                'missing' => true,
            ];
        }

        return [
            'node_id' => $node->id(),
            'type' => $node->type(),
            'label' => $this->nodeLabel($node),
            'feature' => $this->nodeFeature($node),
            'edge_type' => $edgeType,
            'source_path' => $node->sourcePath(),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $dependencies
     * @param array<int,array<string,mixed>> $dependents
     * @param array<string,mixed> $impact
     */
    private function renderExplanation(
        string $target,
        GraphNode $node,
        array $dependencies,
        array $dependents,
        array $impact,
    ): string {
        $parts = [
            sprintf(
                '%s resolves to %s %s.',
                $target,
                $node->type(),
                $this->nodeLabel($node),
            ),
        ];

        $signature = trim((string) ($node->payload()['signature'] ?? ''));
        $route = $this->routeLabel($node);
        if ($signature !== '') {
            $parts[] = 'Command signature: ' . $signature . '.';
        } elseif ($route !== '') {
            $parts[] = 'Route: ' . $route . '.';
        }

        if ($dependencies !== []) {
            $parts[] = 'Depends on ' . $this->joinLabels($dependencies) . '.';
        }

        if ($dependents !== []) {
            $parts[] = 'Used by ' . $this->joinLabels($dependents) . '.';
        }

        $affectedFeatures = array_values(array_map('strval', (array) ($impact['affected_features'] ?? [])));
        if ($affectedFeatures !== []) {
            $parts[] = 'Impact reaches features ' . implode(', ', array_slice($affectedFeatures, 0, 5)) . '.';
        }

        $recommendedVerification = array_values(array_map('strval', (array) ($impact['recommended_verification'] ?? [])));
        if ($recommendedVerification !== []) {
            $parts[] = 'Recommended verification: ' . implode('; ', array_slice($recommendedVerification, 0, 3)) . '.';
        }

        return implode(' ', $parts);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private function joinLabels(array $rows): string
    {
        $labels = [];
        foreach ($rows as $row) {
            $labels[] = (string) ($row['label'] ?? $row['node_id'] ?? 'unknown');
        }

        return implode(', ', array_slice($labels, 0, 5));
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

    private function routeLabel(GraphNode $node): string
    {
        $route = $node->payload()['route'] ?? null;
        if (is_array($route)) {
            $method = trim((string) ($route['method'] ?? ''));
            $path = trim((string) ($route['path'] ?? ''));

            return trim($method . ' ' . $path);
        }

        return trim((string) $route);
    }
}
