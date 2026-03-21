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
        $feature = $this->resolvedFeature($graph, $node);
        $pipeline = $feature !== null ? $this->pipelineContext($graph, $feature) : null;
        $events = $feature !== null ? $this->relatedFeatureNodes($graph, $feature, 'event') : [];
        $workflows = $feature !== null ? $this->relatedFeatureNodes($graph, $feature, 'workflow') : [];

        return [
            'target' => $target,
            'resolved_node_id' => $node->id(),
            'node' => $node->toArray(),
            'feature' => $feature,
            'dependencies' => $dependencies,
            'dependents' => $dependents,
            'pipeline_execution' => $pipeline,
            'guards' => is_array($pipeline['guards'] ?? null) ? $pipeline['guards'] : [],
            'events' => $events,
            'workflows' => $workflows,
            'impact' => $impact,
            'explanation' => $this->renderExplanation($target, $node, $dependencies, $dependents, $impact, $pipeline, $events, $workflows),
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
     * @param array<string,mixed>|null $pipeline
     * @param array<int,array<string,mixed>> $events
     * @param array<int,array<string,mixed>> $workflows
     */
    private function renderExplanation(
        string $target,
        GraphNode $node,
        array $dependencies,
        array $dependents,
        array $impact,
        ?array $pipeline,
        array $events,
        array $workflows,
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

        $stages = array_values(array_map('strval', (array) ($pipeline['stages'] ?? [])));
        if ($stages !== []) {
            $parts[] = 'Pipeline stages: ' . implode(', ', $stages) . '.';
        }

        $guards = array_values(array_filter(
            (array) ($pipeline['guards'] ?? []),
            static fn (mixed $row): bool => is_array($row),
        ));
        if ($guards !== []) {
            $parts[] = 'Guards: ' . $this->joinLabels($guards) . '.';
        }

        if ($events !== []) {
            $parts[] = 'Events: ' . $this->joinLabels($events) . '.';
        }

        if ($workflows !== []) {
            $parts[] = 'Workflows: ' . $this->joinLabels($workflows) . '.';
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

    private function resolvedFeature(ApplicationGraph $graph, GraphNode $node): ?string
    {
        $feature = $this->nodeFeature($node);
        if ($feature !== null) {
            return $feature;
        }

        foreach (array_merge($graph->dependencies($node->id()), $graph->dependents($node->id())) as $edge) {
            foreach ([$edge->from, $edge->to] as $candidateId) {
                $candidate = $graph->node($candidateId);
                if (!$candidate instanceof GraphNode) {
                    continue;
                }

                $feature = $this->nodeFeature($candidate);
                if ($feature !== null) {
                    return $feature;
                }
            }
        }

        return null;
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

    /**
     * @return array<string,mixed>
     */
    private function pipelineContext(ApplicationGraph $graph, string $feature): array
    {
        $executionPlans = $this->relatedFeatureNodes($graph, $feature, 'execution_plan');
        if ($executionPlans === []) {
            foreach ($graph->nodesByType('execution_plan') as $node) {
                if ($this->nodeFeature($node) === $feature) {
                    $executionPlans[] = $this->summarizeNode($node);
                }
            }
        }

        usort(
            $executionPlans,
            static fn (array $left, array $right): int => strcmp((string) ($left['node_id'] ?? ''), (string) ($right['node_id'] ?? '')),
        );

        $stages = [];
        $guards = [];
        foreach ($executionPlans as $row) {
            $planNode = $graph->node((string) ($row['node_id'] ?? ''));
            if (!$planNode instanceof GraphNode) {
                continue;
            }

            $stages = array_merge($stages, array_values(array_map('strval', (array) ($planNode->payload()['stages'] ?? []))));
            foreach ($graph->dependencies($planNode->id()) as $edge) {
                $candidate = $graph->node($edge->to);
                if (!$candidate instanceof GraphNode || $candidate->type() !== 'guard') {
                    continue;
                }

                $guards[$candidate->id()] = $this->summarizeNode($candidate, $edge->type);
            }
        }

        $stages = array_values(array_unique(array_filter($stages, static fn (string $stage): bool => $stage !== '')));
        sort($stages);
        ksort($guards);

        return [
            'feature' => $feature,
            'execution_plans' => $executionPlans,
            'guards' => array_values($guards),
            'stages' => $stages,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function relatedFeatureNodes(ApplicationGraph $graph, string $feature, string $type): array
    {
        $featureNodeId = 'feature:' . $feature;
        $rows = [];

        foreach (array_merge($graph->dependencies($featureNodeId), $graph->dependents($featureNodeId)) as $edge) {
            foreach ([$edge->from, $edge->to] as $candidateId) {
                $candidate = $graph->node($candidateId);
                if (!$candidate instanceof GraphNode || $candidate->type() !== $type) {
                    continue;
                }

                $rows[$candidate->id()] = $this->summarizeNode($candidate, $edge->type);
            }
        }

        ksort($rows);

        return array_values($rows);
    }

    /**
     * @return array<string,mixed>
     */
    private function summarizeNode(GraphNode $node, ?string $edgeType = null): array
    {
        return [
            'node_id' => $node->id(),
            'type' => $node->type(),
            'label' => $this->nodeLabel($node),
            'feature' => $this->nodeFeature($node),
            'edge_type' => $edgeType,
            'source_path' => $node->sourcePath(),
        ];
    }
}
