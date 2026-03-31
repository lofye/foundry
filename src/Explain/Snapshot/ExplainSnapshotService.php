<?php

declare(strict_types=1);

namespace Foundry\Explain\Snapshot;

use Foundry\Compiler\ApplicationGraph;
use Foundry\Compiler\Extensions\ExtensionRegistry;
use Foundry\Compiler\IR\GraphNode;
use Foundry\Confidence\ConfidenceEngine;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainModel;
use Foundry\Explain\ExplainOrigin;
use Foundry\Explain\ExplainSupport;
use Foundry\Generate\GeneratorRegistry;
use Foundry\Generate\RegisteredGenerator;
use Foundry\Pro\ArchitectureExplainer;
use Foundry\Support\ApiSurfaceRegistry;
use Foundry\Support\FoundryError;
use Foundry\Support\Json;
use Foundry\Support\Paths;

final class ExplainSnapshotService
{
    public function __construct(
        private readonly Paths $paths,
        private readonly ApiSurfaceRegistry $apiSurfaceRegistry = new ApiSurfaceRegistry(),
        private readonly ConfidenceEngine $confidenceEngine = new ConfidenceEngine(),
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function capture(
        string $label,
        ApplicationGraph $graph,
        ExtensionRegistry $extensions,
        ?GeneratorRegistry $generators = null,
        ?string $target = null,
    ): array {
        $normalizedLabel = $this->normalizeLabel($label);
        $generatorRegistry = $generators ?? GeneratorRegistry::forExtensions($extensions);
        $extensionRows = $extensions->inspectRows();
        $extensionEntries = $this->extensionEntries($extensionRows);
        $resolvedTarget = $target ?? ExplainSupport::defaultTargetOrNull($graph);
        $explain = $this->buildExplainPayload($graph, $extensions, $extensionRows, $extensionEntries, $resolvedTarget);

        $snapshot = [
            'schema_version' => 1,
            'label' => $normalizedLabel,
            'confidence' => is_array($explain['confidence'] ?? null) ? $explain['confidence'] : [],
            'metadata' => [
                'explain_schema_version' => (int) ($explain['metadata']['schema_version'] ?? 0),
                'framework_version' => $graph->frameworkVersion(),
                'graph_version' => $graph->graphVersion(),
                'source_hash' => $graph->sourceHash(),
                'graph_fingerprint' => $graph->fingerprint(),
                'timestamp' => $graph->compiledAt(),
                'target' => $explain['metadata']['target'] ?? ['raw' => 'system:root', 'kind' => null, 'selector' => 'system:root'],
            ],
            'application' => [
                'summary' => [
                    'features' => count($graph->features()),
                    'routes' => count($graph->nodesByType('route')),
                    'extensions' => count($extensionEntries),
                    'packs' => count(array_values(array_filter(
                        $extensionEntries,
                        static fn(array $entry): bool => (string) ($entry['type'] ?? '') === 'pack',
                    ))),
                    'generators' => count($generatorRegistry->all()),
                    'nodes' => count($graph->nodes()),
                    'edges' => count($graph->edges()),
                ],
                'features' => array_values(array_map('strval', $graph->features())),
                'routes' => $this->routeRows($graph),
                'components' => [
                    'nodes_by_type' => $graph->nodeCountsByType(),
                    'nodes_by_category' => $graph->nodeCountsByCategory(),
                    'edges_by_type' => $graph->edgeCountsByType(),
                ],
                'extensions' => $extensionEntries,
            ],
            'explain' => $explain,
            'categories' => [
                'routes' => $this->routeRows($graph),
                'schemas' => $this->nodeCategoryRows($graph, 'schema'),
                'commands' => $this->commandRows($extensionRows),
                'workflows' => $this->nodeCategoryRows($graph, 'workflow'),
                'guards' => $this->nodeCategoryRows($graph, 'guard'),
                'events' => $this->nodeCategoryRows($graph, 'event'),
                'generators' => $this->generatorRows($generatorRegistry),
                'packs' => array_values(array_filter(
                    $extensionEntries,
                    static fn(array $entry): bool => (string) ($entry['type'] ?? '') === 'pack',
                )),
                'extensions' => $extensionEntries,
                'graph_nodes' => $this->graphNodeRows($graph),
                'graph_edges' => $this->graphEdgeRows($graph),
            ],
        ];

        $this->write($this->snapshotPath($normalizedLabel), $snapshot);

        return $snapshot;
    }

    /**
     * @return array<string,mixed>
     */
    public function load(string $label): array
    {
        $path = $this->snapshotPath($label);
        if (!is_file($path)) {
            throw new FoundryError(
                'EXPLAIN_SNAPSHOT_NOT_FOUND',
                'not_found',
                ['label' => $this->normalizeLabel($label), 'path' => $path],
                'Architectural snapshot was not found.',
            );
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new FoundryError(
                'EXPLAIN_SNAPSHOT_UNREADABLE',
                'io',
                ['label' => $this->normalizeLabel($label), 'path' => $path],
                'Architectural snapshot could not be read.',
            );
        }

        try {
            $snapshot = Json::decodeAssoc($content);
        } catch (FoundryError $error) {
            throw new FoundryError(
                'EXPLAIN_SNAPSHOT_CORRUPT',
                'parsing',
                ['label' => $this->normalizeLabel($label), 'path' => $path],
                'Architectural snapshot is corrupt.',
                0,
                $error,
            );
        }

        if (!is_int($snapshot['schema_version'] ?? null) || !is_array($snapshot['metadata'] ?? null) || !is_array($snapshot['categories'] ?? null)) {
            throw new FoundryError(
                'EXPLAIN_SNAPSHOT_INVALID',
                'validation',
                ['label' => $this->normalizeLabel($label), 'path' => $path],
                'Architectural snapshot is invalid.',
            );
        }

        return $snapshot;
    }

    public function snapshotPath(string $label): string
    {
        return $this->paths->join('.foundry/snapshots/' . $this->normalizeLabel($label) . '.json');
    }

    /**
     * @param array<string,mixed> $snapshot
     */
    private function write(string $path, array $snapshot): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($path, Json::encode($snapshot, true));
    }

    /**
     * @param array<int,array<string,mixed>> $extensionRows
     * @param array<int,array<string,mixed>> $extensionEntries
     * @return array<string,mixed>
     */
    private function buildExplainPayload(
        ApplicationGraph $graph,
        ExtensionRegistry $extensions,
        array $extensionRows,
        array $extensionEntries,
        ?string $target,
    ): array {
        if ($target === null || trim($target) === '') {
            return $this->emptyExplainPayload($graph, $extensionEntries);
        }

        return (new ArchitectureExplainer(
            paths: $this->paths,
            impactAnalyzer: (new \Foundry\Compiler\GraphCompiler($this->paths, $extensions))->impactAnalyzer(),
            apiSurfaceRegistry: $this->apiSurfaceRegistry,
            extensionRows: $extensionRows,
        ))->explain($graph, $target, new ExplainOptions())->toArray();
    }

    /**
     * @param array<int,array<string,mixed>> $extensionEntries
     * @return array<string,mixed>
     */
    private function emptyExplainPayload(ApplicationGraph $graph, array $extensionEntries): array
    {
        $model = new ExplainModel(
            subject: ExplainOrigin::applyToRow([
                'id' => 'system:root',
                'kind' => 'system',
                'label' => 'system',
            ]),
            graph: [
                'node_ids' => [],
                'subject_node' => null,
                'neighbors' => ['inbound' => [], 'outbound' => [], 'lateral' => []],
            ],
            execution: [
                'entries' => [],
                'stages' => [],
                'action' => null,
                'workflows' => [],
                'jobs' => [],
            ],
            guards: ['items' => []],
            events: ['emits' => [], 'subscriptions' => [], 'emitters' => [], 'subscribers' => []],
            schemas: ['subject' => null, 'items' => [], 'reads' => [], 'writes' => [], 'fields' => []],
            relationships: [
                'dependsOn' => ['items' => []],
                'usedBy' => ['items' => []],
                'graph' => ['inbound' => [], 'outbound' => [], 'lateral' => []],
            ],
            diagnostics: [
                'summary' => ['error' => 0, 'warning' => 0, 'info' => 0, 'total' => 0],
                'items' => [],
            ],
            docs: ['related' => []],
            impact: [],
            commands: ['subject' => null, 'related' => []],
            metadata: [
                'schema_version' => 2,
                'target' => ['raw' => 'system:root', 'kind' => null, 'selector' => 'system:root'],
                'options' => (new ExplainOptions())->toArray(),
                'graph' => [
                    'graph_version' => $graph->graphVersion(),
                    'framework_version' => $graph->frameworkVersion(),
                    'source_hash' => $graph->sourceHash(),
                ],
                'command_prefix' => ExplainSupport::commandPrefix($this->paths),
                'impact' => null,
            ],
            extensions: $extensionEntries,
        );

        return $model->withConfidence($this->confidenceEngine->explain($model))->toArray();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function routeRows(ApplicationGraph $graph): array
    {
        $rows = [];
        foreach ($graph->nodesByType('route') as $node) {
            $payload = $node->payload();
            $signature = ExplainSupport::normalizeRouteSignature((string) ($payload['signature'] ?? ''));
            if ($signature === '') {
                $signature = trim(strtoupper((string) ($payload['method'] ?? '')) . ' ' . trim((string) ($payload['path'] ?? '')));
            }

            $rows[] = ExplainOrigin::applyToRow([
                'id' => $signature !== '' ? $signature : $node->id(),
                'type' => 'route',
                'kind' => 'route',
                'label' => $signature !== '' ? $signature : ExplainSupport::nodeLabel($node),
                'node_id' => $node->id(),
                'feature' => ExplainSupport::featureFromNode($node),
                'source_path' => $node->sourcePath(),
                'method' => strtoupper(trim((string) ($payload['method'] ?? ''))),
                'path' => trim((string) ($payload['path'] ?? '')),
            ]);
        }

        return $this->sortRows($rows);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function nodeCategoryRows(ApplicationGraph $graph, string $type): array
    {
        $rows = [];
        foreach ($graph->nodesByType($type) as $node) {
            $rows[] = match ($type) {
                'schema' => $this->schemaRow($node),
                'workflow' => $this->workflowRow($node),
                'guard' => $this->guardRow($node),
                'event' => $this->eventRow($node),
                default => ExplainOrigin::applyToRow([
                    'id' => $node->id(),
                    'type' => $type,
                    'kind' => $type,
                    'label' => ExplainSupport::nodeLabel($node),
                    'feature' => ExplainSupport::featureFromNode($node),
                    'source_path' => $node->sourcePath(),
                ]),
            };
        }

        return $this->sortRows($rows);
    }

    /**
     * @param array<int,array<string,mixed>> $extensionRows
     * @return array<int,array<string,mixed>>
     */
    private function commandRows(array $extensionRows): array
    {
        $rows = [];
        foreach ($extensionRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $extension = ExplainOrigin::packNameFromRow($row) ?? trim((string) ($row['name'] ?? ''));
            if ($extension === '') {
                continue;
            }

            foreach ((array) ((is_array($row['declared_contributions'] ?? null) ? $row['declared_contributions'] : [])['commands'] ?? []) as $command) {
                $signature = trim((string) $command);
                if ($signature === '') {
                    continue;
                }

                $rows[] = [
                    'id' => $signature,
                    'type' => 'command',
                    'label' => $signature,
                    'signature' => $signature,
                    'origin' => 'extension',
                    'extension' => $extension,
                ];
            }
        }

        return $this->sortRows($rows);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function generatorRows(GeneratorRegistry $registry): array
    {
        $rows = array_map(
            function (RegisteredGenerator $generator): array {
                return [
                    'id' => $generator->id,
                    'type' => 'generator',
                    'label' => $generator->id,
                    'capabilities' => ExplainSupport::uniqueStrings($generator->capabilities),
                    'priority' => $generator->priority,
                    'origin' => $generator->origin === 'pack' ? 'extension' : 'core',
                    'extension' => $generator->extension,
                ];
            },
            $registry->all(),
        );

        return $this->sortRows($rows);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function graphNodeRows(ApplicationGraph $graph): array
    {
        $rows = [];
        foreach ($graph->nodes() as $node) {
            $row = ExplainSupport::summarizeGraphNode($node);
            $row['type'] = 'graph_node';
            $row['kind'] = 'graph_node';
            $row['node_type'] = $node->type();
            $rows[] = $row;
        }

        return $this->sortRows($rows);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function graphEdgeRows(ApplicationGraph $graph): array
    {
        $rows = [];
        foreach ($graph->edges() as $edge) {
            $from = ExplainSupport::summarizeGraphNodeById($graph, $edge->from, $edge->type);
            $to = ExplainSupport::summarizeGraphNodeById($graph, $edge->to, $edge->type);
            $extension = null;
            $origin = 'core';

            foreach ([$from, $to] as $row) {
                if ((string) ($row['origin'] ?? 'core') === 'extension') {
                    $origin = 'extension';
                    $extension = trim((string) ($row['extension'] ?? '')) ?: $extension;
                    break;
                }
            }

            $rows[] = [
                'id' => $edge->id,
                'type' => 'graph_edge',
                'kind' => 'graph_edge',
                'label' => $edge->type . ': ' . (string) ($from['label'] ?? $edge->from) . ' -> ' . (string) ($to['label'] ?? $edge->to),
                'edge_type' => $edge->type,
                'from' => $edge->from,
                'to' => $edge->to,
                'origin' => $origin,
                'extension' => $extension,
                'payload' => $edge->payload,
            ];
        }

        return $this->sortRows($rows);
    }

    /**
     * @param array<int,array<string,mixed>> $extensionRows
     * @return array<int,array<string,mixed>>
     */
    private function extensionEntries(array $extensionRows): array
    {
        $rows = [];

        foreach ($extensionRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $packName = ExplainOrigin::packNameFromRow($row);
            $type = $packName !== null ? 'pack' : 'extension';
            $name = $type === 'pack'
                ? $packName
                : trim((string) ($row['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $declaredContributions = is_array($row['declared_contributions'] ?? null) ? $row['declared_contributions'] : [];
            $provides = [];
            foreach ($declaredContributions as $key => $values) {
                if (is_string($key) && is_array($values) && $values !== []) {
                    $provides[] = $key;
                }
            }
            if ($provides === []) {
                $provides = $this->flattenProvides($row['provides'] ?? $row['capabilities'] ?? []);
            }
            sort($provides);

            $affects = [];
            foreach ((array) ($row['graph_nodes'] ?? []) as $node) {
                if (!is_array($node)) {
                    continue;
                }

                $feature = trim((string) ($node['feature'] ?? ''));
                if ($feature !== '') {
                    $affects[] = 'feature.' . $feature;
                }
            }

            $diagnostics = array_values(array_filter((array) ($row['diagnostics'] ?? []), 'is_array'));
            $verified = (bool) ($row['enabled'] ?? false);
            foreach ($diagnostics as $diagnostic) {
                if (strtolower((string) ($diagnostic['severity'] ?? '')) === 'error') {
                    $verified = false;
                    break;
                }
            }

            $rows[] = [
                'id' => $name,
                'name' => $name,
                'version' => (string) ($row['version'] ?? '0.0.0'),
                'type' => $type,
                'label' => $name,
                'provides' => ExplainSupport::uniqueStrings($provides),
                'affects' => ExplainSupport::uniqueStrings($affects),
                'entry_points' => ExplainSupport::uniqueStrings(array_values(array_filter(array_map(
                    'strval',
                    [
                        is_array($row['pack_manifest'] ?? null) ? ($row['pack_manifest']['entry'] ?? null) : null,
                        $row['class'] ?? null,
                    ],
                )))),
                'nodes' => ExplainSupport::uniqueStrings(array_values(array_filter(array_map(
                    static fn(mixed $node): string => is_array($node)
                        ? (string) ($node['id'] ?? $node['label'] ?? '')
                        : '',
                    (array) ($row['graph_nodes'] ?? []),
                )))),
                'verified' => $verified,
                'source' => $type === 'pack' ? ExplainOrigin::installSource($row) : 'local',
                'origin' => 'extension',
                'extension' => $name,
            ];
        }

        usort($rows, static fn(array $left, array $right): int => strcmp((string) ($left['type'] ?? ''), (string) ($right['type'] ?? ''))
            ?: strcmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''))
            ?: version_compare((string) ($left['version'] ?? '0.0.0'), (string) ($right['version'] ?? '0.0.0')));

        return array_values($rows);
    }

    /**
     * @return array<int,string>
     */
    private function flattenProvides(mixed $provides): array
    {
        $flattened = [];
        foreach ((array) $provides as $value) {
            if (is_array($value)) {
                foreach ($value as $nested) {
                    $candidate = trim((string) $nested);
                    if ($candidate !== '') {
                        $flattened[] = $candidate;
                    }
                }

                continue;
            }

            $candidate = trim((string) $value);
            if ($candidate !== '') {
                $flattened[] = $candidate;
            }
        }

        return ExplainSupport::uniqueStrings($flattened);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function sortRows(array $rows): array
    {
        usort($rows, static fn(array $left, array $right): int => strcmp((string) ($left['origin'] ?? 'core'), (string) ($right['origin'] ?? 'core'))
            ?: strcmp((string) ($left['extension'] ?? ''), (string) ($right['extension'] ?? ''))
            ?: strcmp((string) ($left['type'] ?? ''), (string) ($right['type'] ?? ''))
            ?: strcmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''))
            ?: strcmp((string) ($left['id'] ?? ''), (string) ($right['id'] ?? '')));

        return array_values($rows);
    }

    /**
     * @return array<string,mixed>
     */
    private function schemaRow(GraphNode $node): array
    {
        $payload = $node->payload();
        $path = trim((string) ($payload['path'] ?? ''));

        return ExplainOrigin::applyToRow([
            'id' => $path !== '' ? $path : $node->id(),
            'type' => 'schema',
            'kind' => 'schema',
            'label' => $path !== '' ? $path : ExplainSupport::nodeLabel($node),
            'node_id' => $node->id(),
            'feature' => ExplainSupport::featureFromNode($node),
            'source_path' => $node->sourcePath(),
            'path' => $path,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function workflowRow(GraphNode $node): array
    {
        $payload = $node->payload();
        $resource = trim((string) ($payload['resource'] ?? ''));

        return ExplainOrigin::applyToRow([
            'id' => $resource !== '' ? $resource : $node->id(),
            'type' => 'workflow',
            'kind' => 'workflow',
            'label' => $resource !== '' ? $resource : ExplainSupport::nodeLabel($node),
            'node_id' => $node->id(),
            'feature' => ExplainSupport::featureFromNode($node),
            'source_path' => $node->sourcePath(),
            'resource' => $resource,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function guardRow(GraphNode $node): array
    {
        $payload = $node->payload();
        $feature = trim((string) ($payload['feature'] ?? ''));
        $guardType = trim((string) ($payload['type'] ?? 'guard'));
        $label = $feature !== '' ? $feature . ' (' . $guardType . ')' : ExplainSupport::nodeLabel($node);

        return ExplainOrigin::applyToRow([
            'id' => $feature !== '' ? $feature . ':' . $guardType : $node->id(),
            'type' => 'guard',
            'kind' => 'guard',
            'label' => $label,
            'node_id' => $node->id(),
            'feature' => $feature !== '' ? $feature : ExplainSupport::featureFromNode($node),
            'source_path' => $node->sourcePath(),
            'guard_type' => $guardType,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function eventRow(GraphNode $node): array
    {
        $payload = $node->payload();
        $name = trim((string) ($payload['name'] ?? ''));

        return ExplainOrigin::applyToRow([
            'id' => $name !== '' ? $name : $node->id(),
            'type' => 'event',
            'kind' => 'event',
            'label' => $name !== '' ? $name : ExplainSupport::nodeLabel($node),
            'node_id' => $node->id(),
            'feature' => ExplainSupport::featureFromNode($node),
            'source_path' => $node->sourcePath(),
            'name' => $name,
        ]);
    }

    private function normalizeLabel(string $label): string
    {
        $normalized = trim(strtolower($label));

        return match ($normalized) {
            'pre-generate', 'post-generate' => $normalized,
            default => throw new FoundryError(
                'EXPLAIN_SNAPSHOT_LABEL_INVALID',
                'validation',
                ['label' => $label],
                'Architectural snapshot label must be `pre-generate` or `post-generate`.',
            ),
        };
    }
}
