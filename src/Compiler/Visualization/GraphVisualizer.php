<?php
declare(strict_types=1);

namespace Foundry\Compiler\Visualization;

use Foundry\Compiler\ApplicationGraph;
use Foundry\Compiler\GraphEdge;
use Foundry\Compiler\IR\GraphNode;

final class GraphVisualizer
{
    /**
     * @return array<string,mixed>
     */
    public function build(ApplicationGraph $graph, string $view = 'dependencies', ?string $featureFilter = null): array
    {
        $view = $this->normalizeView($view);
        $edges = $this->selectEdges($graph, $view);
        $edges = $this->applyFeatureFilter($edges, $featureFilter);

        $nodeIds = [];
        foreach ($edges as $edge) {
            $nodeIds[$edge->from] = true;
            $nodeIds[$edge->to] = true;
        }

        if ($nodeIds === []) {
            if ($featureFilter !== null && $featureFilter !== '') {
                $featureNodeId = 'feature:' . $featureFilter;
                if ($graph->hasNode($featureNodeId)) {
                    $nodeIds[$featureNodeId] = true;
                }
            } elseif ($view === 'dependencies') {
                foreach ($graph->nodesByType('feature') as $node) {
                    $nodeIds[$node->id()] = true;
                }
            }
        }

        $nodes = [];
        $sortedNodeIds = array_keys($nodeIds);
        sort($sortedNodeIds);
        foreach ($sortedNodeIds as $nodeId) {
            $node = $graph->node($nodeId);
            if (!$node instanceof GraphNode) {
                continue;
            }

            $nodes[] = [
                'id' => $node->id(),
                'type' => $node->type(),
                'label' => $this->label($node),
                'source_path' => $node->sourcePath(),
            ];
        }

        usort(
            $edges,
            static fn (GraphEdge $a, GraphEdge $b): int => strcmp($a->id, $b->id),
        );
        $edgeRows = array_values(array_map(
            fn (GraphEdge $edge): array => [
                'id' => $edge->id,
                'type' => $edge->type,
                'from' => $edge->from,
                'to' => $edge->to,
                'label' => $this->edgeLabel($edge),
            ],
            $edges,
        ));

        return [
            'view' => $view,
            'feature_filter' => $featureFilter,
            'nodes' => $nodes,
            'edges' => $edgeRows,
        ];
    }

    /**
     * @param array<string,mixed> $graphData
     */
    public function render(array $graphData, string $format): string
    {
        $format = $this->normalizeFormat($format);

        return match ($format) {
            'dot' => $this->renderDot($graphData),
            'svg' => $this->renderSvg($graphData),
            'json' => json_encode($graphData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
            default => $this->renderMermaid($graphData),
        };
    }

    private function normalizeView(string $view): string
    {
        return match ($view) {
            'events', 'routes', 'caches', 'pipeline', 'dependencies' => $view,
            default => 'dependencies',
        };
    }

    private function normalizeFormat(string $format): string
    {
        return match ($format) {
            'mermaid', 'dot', 'json', 'svg' => $format,
            default => 'mermaid',
        };
    }

    /**
     * @return array<int,GraphEdge>
     */
    private function selectEdges(ApplicationGraph $graph, string $view): array
    {
        $selected = [];
        foreach ($graph->edges() as $edge) {
            if (!$edge instanceof GraphEdge) {
                continue;
            }

            $include = match ($view) {
                'events' => in_array($edge->type, ['feature_to_event_emit', 'feature_to_event_subscribe'], true),
                'routes' => in_array($edge->type, [
                    'feature_to_route',
                    'feature_to_input_schema',
                    'feature_to_output_schema',
                    'feature_to_query',
                    'feature_to_event_emit',
                    'feature_to_job_dispatch',
                    'feature_to_api_resource',
                    'feature_to_notification_dispatch',
                ], true),
                'caches' => $edge->type === 'feature_to_cache_invalidation',
                'pipeline' => in_array($edge->type, [
                    'pipeline_stage_next',
                    'feature_to_execution_plan',
                    'route_to_execution_plan',
                    'execution_plan_to_stage',
                    'execution_plan_to_guard',
                    'execution_plan_to_interceptor',
                    'feature_to_guard',
                    'guard_to_pipeline_stage',
                    'interceptor_to_pipeline_stage',
                    'execution_plan_to_feature_action',
                ], true),
                default => str_starts_with($edge->from, 'feature:') && str_starts_with($edge->to, 'feature:'),
            };

            if ($include) {
                $selected[] = $edge;
            }
        }

        return $selected;
    }

    /**
     * @param array<int,GraphEdge> $edges
     * @return array<int,GraphEdge>
     */
    private function applyFeatureFilter(array $edges, ?string $featureFilter): array
    {
        if ($featureFilter === null || $featureFilter === '') {
            return $edges;
        }

        $featureNodeId = 'feature:' . $featureFilter;
        $selected = [];
        $known = [$featureNodeId => true];

        for ($round = 0; $round < 2; $round++) {
            foreach ($edges as $edge) {
                if (isset($selected[$edge->id])) {
                    continue;
                }

                if (!isset($known[$edge->from]) && !isset($known[$edge->to])) {
                    continue;
                }

                $selected[$edge->id] = $edge;
                $known[$edge->from] = true;
                $known[$edge->to] = true;
            }
        }

        $rows = array_values($selected);
        usort(
            $rows,
            static fn (GraphEdge $a, GraphEdge $b): int => strcmp($a->id, $b->id),
        );

        return $rows;
    }

    private function label(GraphNode $node): string
    {
        $payload = $node->payload();

        return match ($node->type()) {
            'feature' => (string) ($payload['feature'] ?? $node->id()),
            'route' => (string) ($payload['signature'] ?? $node->id()),
            'schema' => (string) ($payload['path'] ?? $node->id()),
            'permission' => (string) ($payload['name'] ?? $node->id()),
            'query' => (string) ($payload['name'] ?? $node->id()),
            'event' => (string) ($payload['name'] ?? $node->id()),
            'job' => (string) ($payload['name'] ?? $node->id()),
            'cache' => (string) ($payload['key'] ?? $node->id()),
            'scheduler' => (string) ($payload['name'] ?? $node->id()),
            'webhook' => (string) ($payload['name'] ?? $node->id()),
            'test' => (string) ($payload['name'] ?? $node->id()),
            'pipeline_stage' => (string) ($payload['name'] ?? $node->id()),
            'guard' => (string) ($payload['type'] ?? $node->id()),
            'interceptor' => (string) ($payload['id'] ?? $node->id()),
            'execution_plan' => (string) ($payload['route_signature'] ?? $payload['feature'] ?? $node->id()),
            'starter_kit' => (string) ($payload['starter'] ?? $node->id()),
            'resource' => (string) ($payload['resource'] ?? $node->id()),
            'admin_resource' => (string) ($payload['resource'] ?? $node->id()),
            'upload_profile' => (string) ($payload['profile'] ?? $node->id()),
            'listing_config' => (string) ($payload['resource'] ?? $node->id()),
            'form_definition' => (string) (($payload['resource'] ?? '') . ':' . ($payload['intent'] ?? '')) ?: $node->id(),
            'notification' => (string) ($payload['notification'] ?? $node->id()),
            'api_resource' => (string) ($payload['resource'] ?? $node->id()),
            default => $node->id(),
        };
    }

    private function edgeLabel(GraphEdge $edge): string
    {
        if (isset($edge->payload['event']) && (string) $edge->payload['event'] !== '') {
            return (string) $edge->payload['event'];
        }

        return $edge->type;
    }

    /**
     * @param array<string,mixed> $graphData
     */
    private function renderMermaid(array $graphData): string
    {
        $nodes = is_array($graphData['nodes'] ?? null) ? $graphData['nodes'] : [];
        $edges = is_array($graphData['edges'] ?? null) ? $graphData['edges'] : [];

        $aliases = [];
        $lines = ['graph TD'];
        foreach ($nodes as $index => $node) {
            if (!is_array($node)) {
                continue;
            }

            $id = (string) ($node['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $alias = 'N' . $index;
            $aliases[$id] = $alias;
            $label = str_replace('"', "'", (string) ($node['label'] ?? $id));
            $type = (string) ($node['type'] ?? 'node');
            $lines[] = sprintf('    %s["%s (%s)"]', $alias, $label, $type);
        }

        foreach ($edges as $edge) {
            if (!is_array($edge)) {
                continue;
            }
            $from = (string) ($edge['from'] ?? '');
            $to = (string) ($edge['to'] ?? '');
            if ($from === '' || $to === '' || !isset($aliases[$from], $aliases[$to])) {
                continue;
            }

            $label = str_replace('"', "'", (string) ($edge['label'] ?? ''));
            $lines[] = $label === ''
                ? sprintf('    %s --> %s', $aliases[$from], $aliases[$to])
                : sprintf('    %s -->|%s| %s', $aliases[$from], $label, $aliases[$to]);
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string,mixed> $graphData
     */
    private function renderDot(array $graphData): string
    {
        $nodes = is_array($graphData['nodes'] ?? null) ? $graphData['nodes'] : [];
        $edges = is_array($graphData['edges'] ?? null) ? $graphData['edges'] : [];

        $lines = ['digraph foundry {', '  rankdir=LR;'];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            $id = addslashes((string) ($node['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $label = addslashes((string) ($node['label'] ?? $id));
            $type = addslashes((string) ($node['type'] ?? 'node'));
            $lines[] = sprintf('  "%s" [label="%s (%s)"];', $id, $label, $type);
        }

        foreach ($edges as $edge) {
            if (!is_array($edge)) {
                continue;
            }

            $from = addslashes((string) ($edge['from'] ?? ''));
            $to = addslashes((string) ($edge['to'] ?? ''));
            if ($from === '' || $to === '') {
                continue;
            }
            $label = addslashes((string) ($edge['label'] ?? ''));
            $lines[] = sprintf('  "%s" -> "%s" [label="%s"];', $from, $to, $label);
        }

        $lines[] = '}';

        return implode("\n", $lines);
    }

    /**
     * @param array<string,mixed> $graphData
     */
    private function renderSvg(array $graphData): string
    {
        $nodes = is_array($graphData['nodes'] ?? null) ? $graphData['nodes'] : [];
        $edges = is_array($graphData['edges'] ?? null) ? $graphData['edges'] : [];

        $height = 60 + ((count($nodes) + count($edges)) * 18);
        $lines = [
            sprintf('<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="%d">', $height),
            '  <rect width="1200" height="' . $height . '" fill="#ffffff"/>',
            '  <text x="10" y="20" font-family="monospace" font-size="14">Foundry Graph Visualization</text>',
        ];

        $y = 40;
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $label = htmlspecialchars((string) ($node['label'] ?? ''), ENT_QUOTES);
            $type = htmlspecialchars((string) ($node['type'] ?? 'node'), ENT_QUOTES);
            $lines[] = sprintf('  <text x="10" y="%d" font-family="monospace" font-size="12">[%s] %s</text>', $y, $type, $label);
            $y += 16;
        }

        $y += 8;
        foreach ($edges as $edge) {
            if (!is_array($edge)) {
                continue;
            }
            $from = htmlspecialchars((string) ($edge['from'] ?? ''), ENT_QUOTES);
            $to = htmlspecialchars((string) ($edge['to'] ?? ''), ENT_QUOTES);
            $label = htmlspecialchars((string) ($edge['label'] ?? ''), ENT_QUOTES);
            $lines[] = sprintf('  <text x="10" y="%d" font-family="monospace" font-size="12">%s -> %s (%s)</text>', $y, $from, $to, $label);
            $y += 16;
        }

        $lines[] = '</svg>';

        return implode("\n", $lines);
    }
}
