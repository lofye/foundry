<?php
declare(strict_types=1);

namespace Foundry\Compiler\Visualization;

use Foundry\Compiler\ApplicationGraph;
use Foundry\Compiler\GraphEdge;
use Foundry\Compiler\IR\GraphNode;

final class GraphVisualizer
{
    /**
     * @return array<int,string>
     */
    public function allowedViews(): array
    {
        return ['dependencies', 'events', 'routes', 'caches', 'pipeline', 'workflows', 'command', 'extensions'];
    }

    /**
     * @return array<int,string>
     */
    public function allowedFormats(): array
    {
        return ['mermaid', 'dot', 'json', 'svg'];
    }

    /**
     * @param array<string,mixed> $options
     * @param array<int,array<string,mixed>> $extensions
     * @return array<string,mixed>
     */
    public function inspect(ApplicationGraph $graph, array $options = [], array $extensions = []): array
    {
        $options = $this->normalizeOptions($options);
        $view = (string) $options['view'];
        $filters = is_array($options['filters'] ?? null) ? $options['filters'] : [];

        $graphData = $view === 'extensions'
            ? $this->buildExtensionData($graph, $filters, $extensions)
            : $this->buildGraphData($graph, $view, $filters, $extensions);

        return [
            'schema_version' => 1,
            'view' => $view,
            'filters' => $filters,
            'graph' => $graphData,
            'summary' => $this->buildSummary($graphData, $view, $filters),
            'available_views' => $this->allowedViews(),
            'available_formats' => $this->allowedFormats(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function build(ApplicationGraph $graph, string $view = 'dependencies', ?string $featureFilter = null): array
    {
        /** @var array<string,mixed> $inspection */
        $inspection = $this->inspect($graph, [
            'view' => $view,
            'feature' => $featureFilter,
        ]);

        return is_array($inspection['graph'] ?? null) ? $inspection['graph'] : [];
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

    /**
     * @param array<string,mixed> $inspection
     */
    public function renderSummary(array $inspection): string
    {
        $summary = is_array($inspection['summary'] ?? null) ? $inspection['summary'] : [];
        $filters = is_array($inspection['filters'] ?? null) ? $inspection['filters'] : [];
        $focus = is_array($summary['focus'] ?? null) ? $summary['focus'] : [];
        $highlights = is_array($summary['highlights'] ?? null) ? $summary['highlights'] : [];

        $lines = [
            (string) ($summary['title'] ?? 'Foundry Graph'),
            (string) ($summary['description'] ?? ''),
            '',
            'nodes: ' . (string) ($summary['node_count'] ?? 0),
            'edges: ' . (string) ($summary['edge_count'] ?? 0),
        ];

        if ($focus !== []) {
            $parts = [];
            foreach ($focus as $name => $value) {
                if (!is_string($name) || $name === '' || !is_scalar($value)) {
                    continue;
                }

                $parts[] = $name . '=' . (string) $value;
            }

            if ($parts !== []) {
                $lines[] = 'focus: ' . implode(', ', $parts);
            }
        }

        foreach ([
            'features' => 'features',
            'routes' => 'routes',
            'events' => 'events',
            'workflows' => 'workflows',
            'pipeline_stages' => 'pipeline stages',
            'extensions' => 'extensions',
        ] as $key => $label) {
            $values = array_values(array_filter(array_map('strval', (array) ($summary[$key] ?? [])), static fn (string $value): bool => $value !== ''));
            if ($values !== []) {
                $lines[] = $label . ': ' . implode(', ', $values);
            }
        }

        if ($highlights !== []) {
            $lines[] = '';
            foreach ($highlights as $highlight) {
                if (!is_string($highlight) || $highlight === '') {
                    continue;
                }

                $lines[] = '- ' . $highlight;
            }
        }

        if ($filters === []) {
            return implode("\n", array_values(array_filter($lines, static fn (string $line): bool => $line !== '')));
        }

        return rtrim(implode("\n", $lines));
    }

    private function normalizeView(?string $view): string
    {
        return match ($view) {
            'events', 'routes', 'caches', 'pipeline', 'dependencies', 'workflows', 'command', 'extensions' => $view,
            'workflow' => 'workflows',
            'commands' => 'command',
            'extension' => 'extensions',
            'overview' => 'dependencies',
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
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function normalizeOptions(array $options): array
    {
        $explicitView = isset($options['view']) ? trim((string) $options['view']) : null;
        $area = isset($options['area']) ? trim((string) $options['area']) : null;
        $feature = $this->normalizeNullableString($options['feature'] ?? null);
        $extension = $this->normalizeNullableString($options['extension'] ?? null);
        $pipeline = $this->normalizeNullableString($options['pipeline'] ?? null);
        $command = $this->normalizeNullableString($options['command'] ?? null);
        $event = $this->normalizeNullableString($options['event'] ?? null);
        $workflow = $this->normalizeNullableString($options['workflow'] ?? null);

        if ($explicitView === null || $explicitView === '') {
            $explicitView = $this->inferViewFromFilters(
                $area,
                $feature,
                $extension,
                $pipeline,
                $command,
                $event,
                $workflow,
            );
        }

        return [
            'view' => $this->normalizeView($explicitView),
            'filters' => [
                'feature' => $feature,
                'extension' => $extension,
                'pipeline' => $pipeline,
                'command' => $command,
                'event' => $event,
                'workflow' => $workflow,
                'area' => $this->normalizeNullableString($area),
            ],
        ];
    }

    private function inferViewFromFilters(
        ?string $area,
        ?string $feature,
        ?string $extension,
        ?string $pipeline,
        ?string $command,
        ?string $event,
        ?string $workflow,
    ): string {
        if ($area !== null) {
            return $this->normalizeView($area);
        }

        if ($command !== null) {
            return 'command';
        }

        if ($workflow !== null) {
            return 'workflows';
        }

        if ($event !== null) {
            return 'events';
        }

        if ($pipeline !== null) {
            return 'pipeline';
        }

        if ($extension !== null) {
            return 'extensions';
        }

        if ($feature !== null) {
            return 'dependencies';
        }

        return 'dependencies';
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
                    'feature_to_stream',
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
                'workflows' => in_array($edge->type, [
                    'workflow_to_permission',
                    'workflow_to_event_emit',
                    'orchestration_to_job',
                ], true),
                'command' => in_array($edge->type, [
                    'feature_to_route',
                    'feature_to_execution_plan',
                    'route_to_execution_plan',
                    'execution_plan_to_stage',
                    'execution_plan_to_guard',
                    'execution_plan_to_interceptor',
                    'execution_plan_to_feature_action',
                    'feature_to_guard',
                    'guard_to_pipeline_stage',
                    'interceptor_to_pipeline_stage',
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

    /**
     * @param array<string,mixed> $filters
     * @param array<int,array<string,mixed>> $extensions
     * @return array<string,mixed>
     */
    private function buildGraphData(ApplicationGraph $graph, string $view, array $filters, array $extensions): array
    {
        $edges = $this->selectEdges($graph, $view);
        $anchorNodeIds = $this->anchorNodeIds($graph, $filters, $extensions);
        if (isset($filters['feature']) && is_string($filters['feature']) && $filters['feature'] !== '') {
            $edges = $this->applyFeatureFilter($edges, $filters['feature']);
        }

        if ($anchorNodeIds !== []) {
            $edges = $this->selectConnectedEdges($edges, $anchorNodeIds);
        }

        $nodeIds = [];
        foreach ($edges as $edge) {
            $nodeIds[$edge->from] = true;
            $nodeIds[$edge->to] = true;
        }

        foreach ($anchorNodeIds as $nodeId) {
            if ($graph->hasNode($nodeId)) {
                $nodeIds[$nodeId] = true;
            }
        }

        if ($nodeIds === []) {
            if ($view === 'dependencies' && $this->isBlankFilterSet($filters)) {
                foreach ($graph->nodesByType('feature') as $node) {
                    $nodeIds[$node->id()] = true;
                }
            } else {
                foreach ($this->standaloneNodeIds($graph, $filters) as $nodeId) {
                    $nodeIds[$nodeId] = true;
                }
            }
        }

        ksort($nodeIds);

        $nodes = [];
        foreach (array_keys($nodeIds) as $nodeId) {
            $node = $graph->node($nodeId);
            if (!$node instanceof GraphNode) {
                continue;
            }

            $nodes[] = $this->nodeRow($node);
        }

        usort(
            $edges,
            static fn (GraphEdge $a, GraphEdge $b): int => strcmp($a->id, $b->id),
        );

        return [
            'schema_version' => 1,
            'view' => $view,
            'feature_filter' => $filters['feature'] ?? null,
            'extension_filter' => $filters['extension'] ?? null,
            'pipeline_filter' => $filters['pipeline'] ?? null,
            'command_filter' => $filters['command'] ?? null,
            'event_filter' => $filters['event'] ?? null,
            'workflow_filter' => $filters['workflow'] ?? null,
            'filters' => $filters,
            'nodes' => $nodes,
            'edges' => array_values(array_map(fn (GraphEdge $edge): array => $this->edgeRow($edge), $edges)),
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @param array<int,array<string,mixed>> $extensions
     * @return array<string,mixed>
     */
    private function buildExtensionData(ApplicationGraph $graph, array $filters, array $extensions): array
    {
        $selectedExtensions = array_values(array_filter(
            $extensions,
            static function (array $row) use ($filters): bool {
                $extensionFilter = (string) ($filters['extension'] ?? '');
                if ($extensionFilter === '') {
                    return true;
                }

                return (string) ($row['name'] ?? '') === $extensionFilter;
            },
        ));

        usort(
            $selectedExtensions,
            static fn (array $a, array $b): int => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')),
        );

        $nodes = [];
        $edges = [];

        foreach ($selectedExtensions as $row) {
            $extensionName = (string) ($row['name'] ?? '');
            if ($extensionName === '') {
                continue;
            }

            $extensionId = 'extension:' . $extensionName;
            $this->addNodeRow($nodes, [
                'id' => $extensionId,
                'type' => 'extension',
                'label' => $extensionName,
                'source_path' => (string) ($row['source_path'] ?? ''),
                'source_region' => null,
                'payload' => $row,
                'extension' => $extensionName,
            ]);

            foreach (array_values(array_map('strval', (array) ($row['packs'] ?? []))) as $pack) {
                if ($pack === '') {
                    continue;
                }

                $packId = 'pack:' . $pack;
                $this->addNodeRow($nodes, [
                    'id' => $packId,
                    'type' => 'pack',
                    'label' => $pack,
                    'source_path' => (string) ($row['source_path'] ?? ''),
                    'source_region' => null,
                    'payload' => ['pack' => $pack, 'extension' => $extensionName],
                    'extension' => $extensionName,
                ]);
                $this->addEdgeRow($edges, [
                    'id' => 'edge:extension_to_pack:' . $extensionId . '->' . $packId,
                    'type' => 'extension_to_pack',
                    'from' => $extensionId,
                    'to' => $packId,
                    'label' => 'pack',
                    'payload' => ['extension' => $extensionName, 'pack' => $pack],
                ]);
            }

            foreach (array_values(array_map('strval', (array) ($row['pipeline_stages'] ?? []))) as $stage) {
                $node = $graph->node('pipeline_stage:' . $stage);
                if (!$node instanceof GraphNode) {
                    continue;
                }

                $this->addNodeRow($nodes, $this->nodeRow($node));
                $this->addEdgeRow($edges, [
                    'id' => 'edge:extension_to_pipeline_stage:' . $extensionId . '->' . $node->id(),
                    'type' => 'extension_to_pipeline_stage',
                    'from' => $extensionId,
                    'to' => $node->id(),
                    'label' => 'pipeline stage',
                    'payload' => ['extension' => $extensionName, 'stage' => $stage],
                ]);
            }

            foreach (array_values(array_map('strval', (array) ($row['pipeline_interceptors'] ?? []))) as $interceptorId) {
                $node = $graph->node('interceptor:' . $interceptorId);
                if (!$node instanceof GraphNode) {
                    continue;
                }

                $this->addNodeRow($nodes, $this->nodeRow($node));
                $this->addEdgeRow($edges, [
                    'id' => 'edge:extension_to_interceptor:' . $extensionId . '->' . $node->id(),
                    'type' => 'extension_to_interceptor',
                    'from' => $extensionId,
                    'to' => $node->id(),
                    'label' => 'interceptor',
                    'payload' => ['extension' => $extensionName, 'interceptor' => $interceptorId],
                ]);
            }
        }

        ksort($nodes);
        ksort($edges);

        return [
            'schema_version' => 1,
            'view' => 'extensions',
            'feature_filter' => $filters['feature'] ?? null,
            'extension_filter' => $filters['extension'] ?? null,
            'pipeline_filter' => $filters['pipeline'] ?? null,
            'command_filter' => $filters['command'] ?? null,
            'event_filter' => $filters['event'] ?? null,
            'workflow_filter' => $filters['workflow'] ?? null,
            'filters' => $filters,
            'nodes' => array_values($nodes),
            'edges' => array_values($edges),
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @param array<int,array<string,mixed>> $extensions
     * @return array<int,string>
     */
    private function anchorNodeIds(ApplicationGraph $graph, array $filters, array $extensions): array
    {
        $nodeIds = [];

        foreach ($this->nodeIdsForFeature($graph, (string) ($filters['feature'] ?? '')) as $nodeId) {
            $nodeIds[$nodeId] = true;
        }
        foreach ($this->nodeIdsForExtension($graph, (string) ($filters['extension'] ?? ''), $extensions) as $nodeId) {
            $nodeIds[$nodeId] = true;
        }
        foreach ($this->nodeIdsForPipeline($graph, (string) ($filters['pipeline'] ?? '')) as $nodeId) {
            $nodeIds[$nodeId] = true;
        }
        foreach ($this->nodeIdsForCommand($graph, (string) ($filters['command'] ?? '')) as $nodeId) {
            $nodeIds[$nodeId] = true;
        }
        foreach ($this->nodeIdsForEvent($graph, (string) ($filters['event'] ?? '')) as $nodeId) {
            $nodeIds[$nodeId] = true;
        }
        foreach ($this->nodeIdsForWorkflow($graph, (string) ($filters['workflow'] ?? '')) as $nodeId) {
            $nodeIds[$nodeId] = true;
        }

        ksort($nodeIds);

        return array_keys($nodeIds);
    }

    /**
     * @param array<int,GraphEdge> $edges
     * @param array<int,string> $anchorNodeIds
     * @return array<int,GraphEdge>
     */
    private function selectConnectedEdges(array $edges, array $anchorNodeIds): array
    {
        if ($anchorNodeIds === []) {
            return $edges;
        }

        $known = [];
        foreach ($anchorNodeIds as $nodeId) {
            $known[$nodeId] = true;
        }

        $selected = [];
        $progress = true;
        while ($progress) {
            $progress = false;

            foreach ($edges as $edge) {
                if (isset($selected[$edge->id])) {
                    continue;
                }

                if (!isset($known[$edge->from]) && !isset($known[$edge->to])) {
                    continue;
                }

                $selected[$edge->id] = $edge;
                if (!isset($known[$edge->from]) || !isset($known[$edge->to])) {
                    $progress = true;
                }
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

    /**
     * @param array<string,mixed> $filters
     * @return array<int,string>
     */
    private function standaloneNodeIds(ApplicationGraph $graph, array $filters): array
    {
        $nodeIds = [];

        foreach (['feature', 'event', 'workflow'] as $key) {
            $value = (string) ($filters[$key] ?? '');
            if ($value === '') {
                continue;
            }

            $prefix = match ($key) {
                'feature' => 'feature:',
                'event' => 'event:',
                default => 'workflow:',
            };
            if ($graph->hasNode($prefix . $value)) {
                $nodeIds[$prefix . $value] = true;
            }
        }

        $pipeline = (string) ($filters['pipeline'] ?? '');
        if ($pipeline !== '' && $graph->hasNode('pipeline_stage:' . $pipeline)) {
            $nodeIds['pipeline_stage:' . $pipeline] = true;
        }

        foreach ($this->nodeIdsForCommand($graph, (string) ($filters['command'] ?? '')) as $nodeId) {
            $nodeIds[$nodeId] = true;
        }

        ksort($nodeIds);

        return array_keys($nodeIds);
    }

    /**
     * @return array<int,string>
     */
    private function nodeIdsForFeature(ApplicationGraph $graph, string $feature): array
    {
        $feature = trim($feature);
        if ($feature === '') {
            return [];
        }

        $nodeIds = [];
        if ($graph->hasNode('feature:' . $feature)) {
            $nodeIds[] = 'feature:' . $feature;
        }
        if ($graph->hasNode('execution_plan:feature:' . $feature)) {
            $nodeIds[] = 'execution_plan:feature:' . $feature;
        }

        return array_values(array_unique($nodeIds));
    }

    /**
     * @param array<int,array<string,mixed>> $extensions
     * @return array<int,string>
     */
    private function nodeIdsForExtension(ApplicationGraph $graph, string $extension, array $extensions): array
    {
        $extension = trim($extension);
        if ($extension === '') {
            return [];
        }

        $nodeIds = [];
        foreach ($graph->nodes() as $node) {
            if ($this->nodeExtension($node) === $extension) {
                $nodeIds[$node->id()] = true;
            }
        }

        foreach ($extensions as $row) {
            if ((string) ($row['name'] ?? '') !== $extension) {
                continue;
            }

            foreach (array_values(array_map('strval', (array) ($row['pipeline_stages'] ?? []))) as $stage) {
                if ($graph->hasNode('pipeline_stage:' . $stage)) {
                    $nodeIds['pipeline_stage:' . $stage] = true;
                }
            }

            foreach (array_values(array_map('strval', (array) ($row['pipeline_interceptors'] ?? []))) as $interceptorId) {
                if ($graph->hasNode('interceptor:' . $interceptorId)) {
                    $nodeIds['interceptor:' . $interceptorId] = true;
                }
            }
        }

        ksort($nodeIds);

        return array_keys($nodeIds);
    }

    /**
     * @return array<int,string>
     */
    private function nodeIdsForPipeline(ApplicationGraph $graph, string $pipeline): array
    {
        $pipeline = trim($pipeline);
        if ($pipeline === '') {
            return [];
        }

        $nodeIds = [];
        if ($graph->hasNode('pipeline_stage:' . $pipeline)) {
            $nodeIds['pipeline_stage:' . $pipeline] = true;
        }

        foreach ($graph->nodesByType('pipeline_stage') as $node) {
            if ((string) ($node->payload()['name'] ?? '') === $pipeline) {
                $nodeIds[$node->id()] = true;
            }
        }

        ksort($nodeIds);

        return array_keys($nodeIds);
    }

    /**
     * @return array<int,string>
     */
    private function nodeIdsForCommand(ApplicationGraph $graph, string $command): array
    {
        $command = trim($command);
        if ($command === '') {
            return [];
        }

        $nodeIds = [];
        if (str_starts_with($command, 'execution_plan:') && $graph->hasNode($command)) {
            $nodeIds[$command] = true;
        }

        if (str_starts_with($command, 'feature:')) {
            $feature = substr($command, strlen('feature:'));
            foreach ($this->nodeIdsForFeature($graph, $feature) as $nodeId) {
                $nodeIds[$nodeId] = true;
            }
        } elseif ($graph->hasNode('feature:' . $command)) {
            foreach ($this->nodeIdsForFeature($graph, $command) as $nodeId) {
                $nodeIds[$nodeId] = true;
            }
        }

        $routeId = null;
        if (str_starts_with($command, 'route:')) {
            $routeId = $command;
        } elseif (preg_match('/^[A-Z]+\\s+\\/.+$/', $command) === 1) {
            $parts = preg_split('/\\s+/', $command, 2) ?: [];
            $routeId = 'route:' . strtoupper((string) ($parts[0] ?? '')) . ':' . (string) ($parts[1] ?? '');
        }

        if ($routeId !== null && $graph->hasNode($routeId)) {
            $nodeIds[$routeId] = true;
            foreach ($graph->dependencies($routeId) as $edge) {
                if ($edge->type === 'route_to_execution_plan') {
                    $nodeIds[$edge->to] = true;
                }
            }
        }

        ksort($nodeIds);

        return array_keys($nodeIds);
    }

    /**
     * @return array<int,string>
     */
    private function nodeIdsForEvent(ApplicationGraph $graph, string $event): array
    {
        $event = trim($event);
        if ($event === '') {
            return [];
        }

        $nodeIds = [];
        if ($graph->hasNode('event:' . $event)) {
            $nodeIds['event:' . $event] = true;
        }

        foreach ($graph->nodesByType('event') as $node) {
            if ((string) ($node->payload()['name'] ?? '') === $event) {
                $nodeIds[$node->id()] = true;
            }
        }

        ksort($nodeIds);

        return array_keys($nodeIds);
    }

    /**
     * @return array<int,string>
     */
    private function nodeIdsForWorkflow(ApplicationGraph $graph, string $workflow): array
    {
        $workflow = trim($workflow);
        if ($workflow === '') {
            return [];
        }

        $nodeIds = [];
        foreach (['workflow:' . $workflow, 'orchestration:' . $workflow] as $nodeId) {
            if ($graph->hasNode($nodeId)) {
                $nodeIds[$nodeId] = true;
            }
        }

        foreach (['workflow', 'orchestration'] as $type) {
            foreach ($graph->nodesByType($type) as $node) {
                $payload = $node->payload();
                $match = (string) ($payload['resource'] ?? $payload['name'] ?? '');
                if ($match === $workflow) {
                    $nodeIds[$node->id()] = true;
                }
            }
        }

        ksort($nodeIds);

        return array_keys($nodeIds);
    }

    /**
     * @param array<string,mixed> $graphData
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    private function buildSummary(array $graphData, string $view, array $filters): array
    {
        $nodes = is_array($graphData['nodes'] ?? null) ? $graphData['nodes'] : [];
        $edges = is_array($graphData['edges'] ?? null) ? $graphData['edges'] : [];

        $nodeTypes = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            $type = (string) ($node['type'] ?? 'node');
            $nodeTypes[$type] = ($nodeTypes[$type] ?? 0) + 1;
        }
        ksort($nodeTypes);

        $edgeTypes = [];
        foreach ($edges as $edge) {
            if (!is_array($edge)) {
                continue;
            }

            $type = (string) ($edge['type'] ?? 'edge');
            $edgeTypes[$type] = ($edgeTypes[$type] ?? 0) + 1;
        }
        ksort($edgeTypes);

        $summary = [
            'title' => $this->summaryTitle($view, $filters),
            'description' => $this->summaryDescription($view, $filters, count($nodes), count($edges)),
            'node_count' => count($nodes),
            'edge_count' => count($edges),
            'features' => $this->labelsForTypes($nodes, ['feature']),
            'routes' => $this->labelsForTypes($nodes, ['route']),
            'events' => $this->labelsForTypes($nodes, ['event']),
            'workflows' => $this->labelsForTypes($nodes, ['workflow', 'orchestration']),
            'pipeline_stages' => $this->labelsForTypes($nodes, ['pipeline_stage']),
            'extensions' => $this->extensionLabels($nodes),
            'node_types' => $nodeTypes,
            'edge_types' => $edgeTypes,
            'focus' => array_filter(
                $filters,
                static fn (mixed $value): bool => is_string($value) && $value !== '',
            ),
        ];
        $summary['highlights'] = $this->summaryHighlights($summary);

        return $summary;
    }

    /**
     * @param array<int,array<string,mixed>> $nodes
     * @return array<int,string>
     */
    private function labelsForTypes(array $nodes, array $types): array
    {
        $labels = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            $type = (string) ($node['type'] ?? '');
            if (!in_array($type, $types, true)) {
                continue;
            }

            $label = trim((string) ($node['label'] ?? ''));
            if ($label !== '') {
                $labels[] = $label;
            }
        }

        $labels = array_values(array_unique($labels));
        sort($labels);

        return $labels;
    }

    /**
     * @param array<int,array<string,mixed>> $nodes
     * @return array<int,string>
     */
    private function extensionLabels(array $nodes): array
    {
        $labels = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            $type = (string) ($node['type'] ?? '');
            if ($type === 'extension') {
                $labels[] = (string) ($node['label'] ?? '');
                continue;
            }

            $extension = trim((string) ($node['extension'] ?? ''));
            if ($extension !== '') {
                $labels[] = $extension;
            }
        }

        $labels = array_values(array_unique(array_filter($labels, static fn (string $value): bool => $value !== '')));
        sort($labels);

        return $labels;
    }

    /**
     * @param array<string,mixed> $summary
     * @return array<int,string>
     */
    private function summaryHighlights(array $summary): array
    {
        $highlights = [];

        foreach ([
            'features' => 'features',
            'routes' => 'routes',
            'events' => 'events',
            'workflows' => 'workflows',
            'pipeline_stages' => 'pipeline stages',
            'extensions' => 'extensions',
        ] as $key => $label) {
            $values = array_values(array_map('strval', (array) ($summary[$key] ?? [])));
            if ($values === []) {
                continue;
            }

            $highlights[] = sprintf(
                '%s: %s',
                $label,
                implode(', ', array_slice($values, 0, 4)) . (count($values) > 4 ? ', ...' : ''),
            );
        }

        $nodeTypes = is_array($summary['node_types'] ?? null) ? $summary['node_types'] : [];
        if ($nodeTypes !== []) {
            $parts = [];
            foreach ($nodeTypes as $type => $count) {
                $parts[] = $type . '=' . (int) $count;
            }
            $highlights[] = 'node types: ' . implode(', ', array_slice($parts, 0, 6));
        }

        $edgeTypes = is_array($summary['edge_types'] ?? null) ? $summary['edge_types'] : [];
        if ($edgeTypes !== []) {
            $parts = [];
            foreach ($edgeTypes as $type => $count) {
                $parts[] = $type . '=' . (int) $count;
            }
            $highlights[] = 'edge types: ' . implode(', ', array_slice($parts, 0, 6));
        }

        return $highlights;
    }

    /**
     * @param array<string,mixed> $filters
     */
    private function summaryTitle(string $view, array $filters): string
    {
        foreach ([
            'command' => 'Command graph',
            'workflow' => 'Workflow graph',
            'event' => 'Event graph',
            'extension' => 'Extension graph',
            'feature' => 'Feature graph',
        ] as $key => $label) {
            $value = trim((string) ($filters[$key] ?? ''));
            if ($value !== '') {
                return $label . ' for ' . $value;
            }
        }

        return match ($view) {
            'events' => 'Event graph overview',
            'routes' => 'Route graph overview',
            'caches' => 'Cache graph overview',
            'pipeline' => 'Pipeline graph overview',
            'workflows' => 'Workflow graph overview',
            'command' => 'Command graph overview',
            'extensions' => 'Extension graph overview',
            default => 'Application graph overview',
        };
    }

    /**
     * @param array<string,mixed> $filters
     */
    private function summaryDescription(string $view, array $filters, int $nodeCount, int $edgeCount): string
    {
        $segments = [];
        if (!$this->isBlankFilterSet($filters)) {
            foreach ($filters as $name => $value) {
                if (!is_string($value) || $value === '') {
                    continue;
                }

                $segments[] = $name . '=' . $value;
            }
        }

        $scope = $segments === [] ? $view : $view . ' filtered by ' . implode(', ', $segments);

        return sprintf('Foundry %s slice with %d nodes and %d edges.', $scope, $nodeCount, $edgeCount);
    }

    /**
     * @param array<string,mixed> $node
     * @param array<string,array<string,mixed>> $nodes
     */
    private function addNodeRow(array &$nodes, array $node): void
    {
        $id = (string) ($node['id'] ?? '');
        if ($id === '') {
            return;
        }

        $nodes[$id] = $node;
    }

    /**
     * @param array<string,mixed> $edge
     * @param array<string,array<string,mixed>> $edges
     */
    private function addEdgeRow(array &$edges, array $edge): void
    {
        $id = (string) ($edge['id'] ?? '');
        if ($id === '') {
            return;
        }

        $edges[$id] = $edge;
    }

    private function nodeRow(GraphNode $node): array
    {
        return [
            'id' => $node->id(),
            'type' => $node->type(),
            'label' => $this->label($node),
            'source_path' => $node->sourcePath(),
            'source_region' => $node->sourceRegion(),
            'payload' => $node->payload(),
            'extension' => $this->nodeExtension($node),
        ];
    }

    private function edgeRow(GraphEdge $edge): array
    {
        return [
            'id' => $edge->id,
            'type' => $edge->type,
            'from' => $edge->from,
            'to' => $edge->to,
            'label' => $this->edgeLabel($edge),
            'payload' => $edge->payload,
        ];
    }

    private function nodeExtension(GraphNode $node): ?string
    {
        $extension = trim((string) ($node->payload()['extension'] ?? ''));

        return $extension !== '' ? $extension : null;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /**
     * @param array<string,mixed> $filters
     */
    private function isBlankFilterSet(array $filters): bool
    {
        foreach ($filters as $value) {
            if (is_string($value) && $value !== '') {
                return false;
            }
        }

        return true;
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
            'billing' => (string) ($payload['provider'] ?? $node->id()),
            'workflow' => (string) ($payload['resource'] ?? $node->id()),
            'orchestration' => (string) ($payload['name'] ?? $node->id()),
            'search_index' => (string) ($payload['index'] ?? $node->id()),
            'stream' => (string) ($payload['stream'] ?? $node->id()),
            'locale_bundle' => (string) ($payload['bundle'] ?? $node->id()),
            'role' => (string) ($payload['role'] ?? $node->id()),
            'policy' => (string) ($payload['policy'] ?? $node->id()),
            'inspect_ui' => (string) ($payload['name'] ?? $node->id()),
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
