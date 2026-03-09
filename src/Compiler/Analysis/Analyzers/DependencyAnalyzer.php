<?php
declare(strict_types=1);

namespace Foundry\Compiler\Analysis\Analyzers;

use Foundry\Compiler\Analysis\AnalyzerContext;
use Foundry\Compiler\Analysis\GraphAnalyzer;
use Foundry\Compiler\ApplicationGraph;
use Foundry\Compiler\Diagnostics\DiagnosticBag;
use Foundry\Compiler\GraphEdge;

final class DependencyAnalyzer implements GraphAnalyzer
{
    public function id(): string
    {
        return 'dependency_cycles';
    }

    public function description(): string
    {
        return 'Detects dependency cycles between features.';
    }

    /**
     * @return array<string,mixed>
     */
    public function analyze(ApplicationGraph $graph, AnalyzerContext $context, DiagnosticBag $diagnostics): array
    {
        $adjacency = $this->featureAdjacency($graph);

        $visited = [];
        $stackIndex = [];
        $path = [];
        $cycles = [];

        $nodes = array_keys($adjacency);
        sort($nodes);

        foreach ($nodes as $node) {
            if (isset($visited[$node])) {
                continue;
            }

            $this->dfs($node, $adjacency, $visited, $stackIndex, $path, $cycles);
        }

        $rows = [];
        foreach ($cycles as $cycle) {
            $features = array_values(array_map(
                static fn (string $nodeId): string => substr($nodeId, strlen('feature:')),
                $cycle,
            ));

            $core = array_values(array_unique($features));
            if ($context->featureFilter !== null && !in_array($context->featureFilter, $core, true)) {
                continue;
            }

            $rows[] = [
                'features' => $features,
                'message' => implode(' -> ', $features),
            ];

            $diagnostics->error(
                code: 'FDY9001_FEATURE_DEPENDENCY_CYCLE',
                category: 'graph',
                message: 'Feature dependency cycle detected: ' . implode(' -> ', $features),
                nodeId: 'feature:' . $core[0],
                relatedNodes: array_values(array_map(
                    static fn (string $feature): string => 'feature:' . $feature,
                    $core,
                )),
                suggestedFix: 'Break one dependency edge in the cycle.',
                pass: 'doctor.' . $this->id(),
            );
        }

        usort(
            $rows,
            static fn (array $a, array $b): int => strcmp((string) ($a['message'] ?? ''), (string) ($b['message'] ?? '')),
        );

        return [
            'cycle_count' => count($rows),
            'cycles' => $rows,
        ];
    }

    /**
     * @return array<string,array<int,string>>
     */
    private function featureAdjacency(ApplicationGraph $graph): array
    {
        $adjacency = [];

        foreach ($graph->nodesByType('feature') as $featureNode) {
            $adjacency[$featureNode->id()] = [];
        }

        foreach ($graph->edges() as $edge) {
            if (!$edge instanceof GraphEdge) {
                continue;
            }

            if (!str_starts_with($edge->from, 'feature:') || !str_starts_with($edge->to, 'feature:')) {
                continue;
            }

            if ($edge->from === $edge->to) {
                continue;
            }

            $adjacency[$edge->from] ??= [];
            $adjacency[$edge->from][] = $edge->to;
            $adjacency[$edge->to] ??= [];
        }

        foreach ($adjacency as &$targets) {
            sort($targets);
            $targets = array_values(array_unique($targets));
        }
        unset($targets);

        ksort($adjacency);

        return $adjacency;
    }

    /**
     * @param array<string,array<int,string>> $adjacency
     * @param array<string,bool> $visited
     * @param array<string,int> $stackIndex
     * @param array<int,string> $path
     * @param array<string,array<int,string>> $cycles
     */
    private function dfs(
        string $node,
        array $adjacency,
        array &$visited,
        array &$stackIndex,
        array &$path,
        array &$cycles,
    ): void {
        $visited[$node] = true;
        $stackIndex[$node] = count($path);
        $path[] = $node;

        foreach ($adjacency[$node] ?? [] as $next) {
            if (!isset($visited[$next])) {
                $this->dfs($next, $adjacency, $visited, $stackIndex, $path, $cycles);
                continue;
            }

            if (!isset($stackIndex[$next])) {
                continue;
            }

            $start = $stackIndex[$next];
            $cycle = array_slice($path, $start);
            $cycle[] = $next;
            $normalized = $this->normalizeCycle($cycle);
            $key = implode('|', $normalized);
            $cycles[$key] = $normalized;
        }

        array_pop($path);
        unset($stackIndex[$node]);
    }

    /**
     * @param array<int,string> $cycle
     * @return array<int,string>
     */
    private function normalizeCycle(array $cycle): array
    {
        if (count($cycle) <= 1) {
            return $cycle;
        }

        $core = array_slice($cycle, 0, -1);
        if ($core === []) {
            return $cycle;
        }

        $best = null;
        $count = count($core);
        for ($offset = 0; $offset < $count; $offset++) {
            $rotation = [];
            for ($i = 0; $i < $count; $i++) {
                $rotation[] = $core[($offset + $i) % $count];
            }
            $candidate = array_merge($rotation, [$rotation[0]]);
            $serialized = implode('|', $candidate);
            if ($best === null || $serialized < $best['serialized']) {
                $best = [
                    'serialized' => $serialized,
                    'cycle' => $candidate,
                ];
            }
        }

        return is_array($best['cycle'] ?? null) ? $best['cycle'] : $cycle;
    }
}

