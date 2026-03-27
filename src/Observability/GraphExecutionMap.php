<?php

declare(strict_types=1);

namespace Foundry\Observability;

use Foundry\Compiler\ApplicationGraph;

final class GraphExecutionMap
{
    public function __construct(private readonly ApplicationGraph $graph) {}

    /**
     * @return array<int,array<string,mixed>>
     */
    public function paths(?string $feature = null, ?string $routeSignature = null): array
    {
        $rows = [];

        foreach ($this->graph->nodesByType('execution_plan') as $node) {
            $payload = $node->payload();
            $planFeature = trim((string) ($payload['feature'] ?? ''));
            $planRoute = trim((string) ($payload['route_signature'] ?? ''));

            if ($feature !== null && $feature !== '' && $planFeature !== $feature) {
                continue;
            }

            if ($routeSignature !== null && $routeSignature !== '' && $planRoute !== $routeSignature) {
                continue;
            }

            $guards = $this->guardRows(array_values(array_map('strval', (array) ($payload['guards'] ?? []))));
            $interceptors = $this->interceptorRows((array) ($payload['interceptors'] ?? []));
            $stages = array_values(array_map('strval', (array) ($payload['stages'] ?? [])));

            $rows[] = [
                'feature' => $planFeature,
                'execution_plan' => $node->id(),
                'route_signature' => $planRoute !== '' ? $planRoute : null,
                'route_node' => ($payload['route_node'] ?? null) !== null ? (string) $payload['route_node'] : null,
                'pipeline_stages' => $stages,
                'guards' => $guards,
                'interceptors' => $interceptors,
                'graph_mapping' => [
                    'feature' => $planFeature !== '' ? 'feature:' . $planFeature : null,
                    'execution_plan' => $node->id(),
                    'guard_ids' => array_values(array_map(
                        static fn(array $guard): string => (string) ($guard['id'] ?? ''),
                        $guards,
                    )),
                    'interceptor_ids' => array_values(array_map(
                        static fn(array $interceptor): string => (string) ($interceptor['id'] ?? ''),
                        $interceptors,
                    )),
                ],
            ];
        }

        usort(
            $rows,
            static fn(array $a, array $b): int => strcmp(
                (string) ($a['execution_plan'] ?? ''),
                (string) ($b['execution_plan'] ?? ''),
            ),
        );

        return $rows;
    }

    /**
     * @param array<int,string> $guardIds
     * @return array<int,array<string,mixed>>
     */
    private function guardRows(array $guardIds): array
    {
        $rows = [];

        foreach ($guardIds as $guardId) {
            $node = $this->graph->node($guardId);
            if ($node === null) {
                continue;
            }

            $payload = $node->payload();
            $rows[] = [
                'id' => $node->id(),
                'feature' => (string) ($payload['feature'] ?? ''),
                'type' => (string) ($payload['type'] ?? ''),
                'stage' => $this->guardStage($node->id()),
                'payload' => $payload,
            ];
        }

        usort(
            $rows,
            static fn(array $a, array $b): int => strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? '')),
        );

        return $rows;
    }

    /**
     * @param array<string,mixed> $map
     * @return array<int,array<string,mixed>>
     */
    private function interceptorRows(array $map): array
    {
        $rows = [];

        foreach ($map as $stage => $ids) {
            foreach (array_values(array_map('strval', (array) $ids)) as $id) {
                $node = $this->graph->node('interceptor:' . $id);
                if ($node === null) {
                    continue;
                }

                $payload = $node->payload();
                $rows[] = [
                    'id' => $node->id(),
                    'stage' => (string) $stage,
                    'priority' => (int) ($payload['priority'] ?? 0),
                    'dangerous' => (bool) ($payload['dangerous'] ?? false),
                    'payload' => $payload,
                ];
            }
        }

        usort(
            $rows,
            static fn(array $a, array $b): int => strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? '')),
        );

        return $rows;
    }

    private function guardStage(string $guardNodeId): string
    {
        foreach ($this->graph->dependencies($guardNodeId) as $edge) {
            if ($edge->type !== 'guard_to_pipeline_stage') {
                continue;
            }

            $stageNode = $this->graph->node($edge->to);
            if ($stageNode === null) {
                continue;
            }

            return (string) ($stageNode->payload()['name'] ?? '');
        }

        return '';
    }
}
