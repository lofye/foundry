<?php
declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Compiler\ApplicationGraph;
use Foundry\Compiler\CompileOptions;
use Foundry\Compiler\GraphCompiler;
use Foundry\Compiler\GraphEdge;
use Foundry\Compiler\IR\GraphNode;
use Foundry\Support\FoundryError;
use Foundry\Support\Json;

final class InspectGraphCommand extends Command
{
    /**
     * @var array<int,string>
     */
    private array $targets = [
        'graph',
        'build',
        'node',
        'dependencies',
        'dependents',
        'pipeline',
        'execution-plan',
        'guards',
        'interceptors',
        'impact',
        'affected-tests',
        'affected-features',
        'extensions',
        'extension',
        'packs',
        'pack',
        'compatibility',
        'migrations',
        'spec-format',
    ];

    #[\Override]
    public function matches(array $args): bool
    {
        if (($args[0] ?? null) !== 'inspect') {
            return false;
        }

        $target = (string) ($args[1] ?? '');
        if (!in_array($target, $this->targets, true)) {
            return false;
        }

        if (in_array($target, ['dependencies', 'dependents', 'node', 'affected-tests', 'affected-features'], true)) {
            $nodeId = (string) ($args[2] ?? '');

            return str_contains($nodeId, ':');
        }

        if ($target === 'execution-plan') {
            return (string) ($args[2] ?? '') !== ''
                || $this->extractOption($args, '--feature') !== null
                || $this->extractOption($args, '--route') !== null;
        }

        if (in_array($target, ['extension', 'pack', 'spec-format'], true)) {
            $name = (string) ($args[2] ?? '');

            return $name !== '';
        }

        if ($target === 'impact') {
            $nodeId = (string) ($args[2] ?? '');
            $fileFlag = array_find($args, static fn (string $arg): bool => str_starts_with($arg, '--file'));

            return str_contains($nodeId, ':') || is_string($fileFlag);
        }

        return true;
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $target = (string) ($args[1] ?? '');

        return match ($target) {
            'extensions' => [
                'status' => 0,
                'message' => null,
                'payload' => [
                    'extensions' => $context->extensionRegistry()->inspectRows(),
                    'registration_sources' => $context->extensionRegistry()->registrationSources(),
                ],
            ],
            'extension' => $this->inspectExtension($context, (string) ($args[2] ?? '')),
            'packs' => [
                'status' => 0,
                'message' => null,
                'payload' => [
                    'packs' => $context->extensionRegistry()->packRegistry()->inspectRows(),
                ],
            ],
            'pack' => $this->inspectPack($context, (string) ($args[2] ?? '')),
            'compatibility' => [
                'status' => 0,
                'message' => null,
                'payload' => $context->extensionRegistry()->compatibilityReport(
                    frameworkVersion: $context->graphCompiler()->frameworkVersion(),
                    graphVersion: GraphCompiler::GRAPH_VERSION,
                )->toArray(),
            ],
            'migrations' => [
                'status' => 0,
                'message' => null,
                'payload' => [
                    'rules' => $context->specMigrator()->inspect(),
                    'spec_formats' => $context->specMigrator()->specFormats(),
                    'codemods' => $context->codemodEngine()->inspectRows(),
                    'pending' => $context->specMigrator()->migrate(false)->toArray(),
                ],
            ],
            'spec-format' => $this->inspectSpecFormat($context, (string) ($args[2] ?? '')),
            'build' => $this->inspectBuild($context),
            default => $this->inspectGraphSurface($args, $context),
        };
    }

    /**
     * @param array<int,string> $args
     */
    private function inspectGraphSurface(array $args, CommandContext $context): array
    {
        $compiler = $context->graphCompiler();
        $graph = $this->loadOrCompileGraph($compiler);

        $target = (string) ($args[1] ?? '');

        return match ($target) {
            'graph' => [
                'status' => 0,
                'message' => null,
                'payload' => [
                    'graph_version' => $graph->graphVersion(),
                    'framework_version' => $graph->frameworkVersion(),
                    'compiled_at' => $graph->compiledAt(),
                    'source_hash' => $graph->sourceHash(),
                    'node_counts' => $graph->nodeCountsByType(),
                    'edge_counts' => $graph->edgeCountsByType(),
                    'diagnostics_summary' => $this->diagnosticsSummary($compiler),
                ],
            ],
            'pipeline' => $this->inspectPipeline($graph),
            'execution-plan' => $this->inspectExecutionPlan($graph, $args),
            'guards' => $this->inspectGuards($graph, (string) ($args[2] ?? '')),
            'interceptors' => $this->inspectInterceptors($graph, $this->extractOption($args, '--stage')),
            'node' => $this->inspectNode($graph, (string) ($args[2] ?? ''), $context),
            'dependencies' => $this->inspectDependencies($graph, (string) ($args[2] ?? '')),
            'dependents' => $this->inspectDependents($graph, (string) ($args[2] ?? '')),
            'impact' => $this->inspectImpact($args, $graph, $context),
            'affected-tests' => $this->inspectAffectedTests($graph, (string) ($args[2] ?? ''), $context),
            'affected-features' => $this->inspectAffectedFeatures($graph, (string) ($args[2] ?? ''), $context),
            default => throw new FoundryError('CLI_INSPECT_TARGET_INVALID', 'validation', ['target' => $target], 'Unsupported inspect target.'),
        };
    }

    private function inspectBuild(CommandContext $context): array
    {
        $layout = $context->graphCompiler()->buildLayout();
        $manifest = $this->readJson($layout->compileManifestPath()) ?? [];
        $integrity = $this->readJson($layout->integrityHashesPath()) ?? [];
        $diagnostics = $this->readJson($layout->diagnosticsPath()) ?? [];

        $verification = $context->graphVerifier()->verify();

        return [
            'status' => $verification->ok ? 0 : 1,
            'message' => null,
            'payload' => [
                'manifest' => $manifest,
                'integrity_hashes' => $integrity,
                'diagnostics' => $diagnostics,
                'verification' => $verification->toArray(),
            ],
        ];
    }

    private function inspectExtension(CommandContext $context, string $name): array
    {
        if ($name === '') {
            throw new FoundryError('CLI_EXTENSION_REQUIRED', 'validation', [], 'Extension name required.');
        }

        $extension = $context->extensionRegistry()->extension($name);
        if ($extension === null) {
            throw new FoundryError('EXTENSION_NOT_FOUND', 'not_found', ['extension' => $name], 'Extension not found.');
        }

        return [
            'status' => 0,
            'message' => null,
            'payload' => [
                'extension' => $extension->describe(),
                'descriptor' => $extension->descriptor()->toArray(),
                'packs' => array_values(array_map(
                    static fn ($pack): array => $pack->toArray(),
                    $extension->packs(),
                )),
            ],
        ];
    }

    private function inspectPack(CommandContext $context, string $name): array
    {
        if ($name === '') {
            throw new FoundryError('CLI_PACK_REQUIRED', 'validation', [], 'Pack name required.');
        }

        $pack = $context->extensionRegistry()->packRegistry()->get($name);
        if ($pack === null) {
            throw new FoundryError('PACK_NOT_FOUND', 'not_found', ['pack' => $name], 'Pack not found.');
        }

        return [
            'status' => 0,
            'message' => null,
            'payload' => [
                'pack' => $pack->toArray(),
            ],
        ];
    }

    private function inspectSpecFormat(CommandContext $context, string $name): array
    {
        if ($name === '') {
            throw new FoundryError('CLI_SPEC_FORMAT_REQUIRED', 'validation', [], 'Spec format name required.');
        }

        $format = $context->specMigrator()->specFormat($name);
        if ($format === null) {
            throw new FoundryError('SPEC_FORMAT_NOT_FOUND', 'not_found', ['format' => $name], 'Spec format not found.');
        }

        return [
            'status' => 0,
            'message' => null,
            'payload' => [
                'spec_format' => $format,
            ],
        ];
    }

    private function inspectNode(ApplicationGraph $graph, string $nodeId, CommandContext $context): array
    {
        if ($nodeId === '') {
            throw new FoundryError('CLI_NODE_REQUIRED', 'validation', [], 'Node ID required.');
        }

        $node = $graph->node($nodeId);
        if ($node === null) {
            throw new FoundryError('GRAPH_NODE_NOT_FOUND', 'not_found', ['node_id' => $nodeId], 'Graph node not found.');
        }

        return [
            'status' => 0,
            'message' => null,
            'payload' => [
                'node' => $node->toArray(),
                'related_nodes' => [
                    'dependencies' => array_values(array_map(static fn (GraphEdge $edge): string => $edge->to, $graph->dependencies($nodeId))),
                    'dependents' => array_values(array_map(static fn (GraphEdge $edge): string => $edge->from, $graph->dependents($nodeId))),
                ],
                'diagnostics' => $this->diagnosticsForNode($nodeId, $context),
            ],
        ];
    }

    private function inspectDependencies(ApplicationGraph $graph, string $nodeId): array
    {
        if ($nodeId === '') {
            throw new FoundryError('CLI_NODE_REQUIRED', 'validation', [], 'Node ID required.');
        }

        if ($graph->node($nodeId) === null) {
            throw new FoundryError('GRAPH_NODE_NOT_FOUND', 'not_found', ['node_id' => $nodeId], 'Graph node not found.');
        }

        $edges = array_values(array_map(
            static fn (GraphEdge $edge): array => $edge->toArray(),
            $graph->dependencies($nodeId),
        ));

        return [
            'status' => 0,
            'message' => null,
            'payload' => [
                'node_id' => $nodeId,
                'dependencies' => $edges,
            ],
        ];
    }

    private function inspectDependents(ApplicationGraph $graph, string $nodeId): array
    {
        if ($nodeId === '') {
            throw new FoundryError('CLI_NODE_REQUIRED', 'validation', [], 'Node ID required.');
        }

        if ($graph->node($nodeId) === null) {
            throw new FoundryError('GRAPH_NODE_NOT_FOUND', 'not_found', ['node_id' => $nodeId], 'Graph node not found.');
        }

        $edges = array_values(array_map(
            static fn (GraphEdge $edge): array => $edge->toArray(),
            $graph->dependents($nodeId),
        ));

        return [
            'status' => 0,
            'message' => null,
            'payload' => [
                'node_id' => $nodeId,
                'dependents' => $edges,
            ],
        ];
    }

    private function inspectPipeline(ApplicationGraph $graph): array
    {
        $stages = [];
        foreach ($graph->nodesByType('pipeline_stage') as $node) {
            $payload = $node->payload();
            $name = (string) ($payload['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $stages[] = [
                'id' => $node->id(),
                'name' => $name,
                'order' => (int) ($payload['order'] ?? 0),
                'priority' => (int) ($payload['priority'] ?? 0),
                'extension' => (string) ($payload['extension'] ?? 'core'),
                'after_stage' => isset($payload['after_stage']) ? (string) $payload['after_stage'] : null,
                'before_stage' => isset($payload['before_stage']) ? (string) $payload['before_stage'] : null,
            ];
        }

        usort(
            $stages,
            static fn (array $a, array $b): int => ((int) ($a['order'] ?? 0) <=> (int) ($b['order'] ?? 0))
                ?: strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')),
        );

        $links = [];
        foreach ($graph->edges() as $edge) {
            if ($edge->type !== 'pipeline_stage_next') {
                continue;
            }

            $from = (string) ($graph->node($edge->from)?->payload()['name'] ?? '');
            $to = (string) ($graph->node($edge->to)?->payload()['name'] ?? '');
            $links[] = [
                'from' => $from !== '' ? $from : $edge->from,
                'to' => $to !== '' ? $to : $edge->to,
            ];
        }

        usort(
            $links,
            static fn (array $a, array $b): int => strcmp((string) ($a['from'] ?? '') . '->' . (string) ($a['to'] ?? ''), (string) ($b['from'] ?? '') . '->' . (string) ($b['to'] ?? '')),
        );

        return [
            'status' => 0,
            'message' => null,
            'payload' => [
                'order' => array_values(array_map(static fn (array $row): string => (string) ($row['name'] ?? ''), $stages)),
                'stages' => $stages,
                'links' => $links,
            ],
        ];
    }

    /**
     * @param array<int,string> $args
     */
    private function inspectExecutionPlan(ApplicationGraph $graph, array $args): array
    {
        $feature = $this->extractOption($args, '--feature');
        $route = $this->extractOption($args, '--route');
        $target = (string) ($args[2] ?? '');

        if ($feature !== null && $feature !== '') {
            $target = 'feature:' . $feature;
        } elseif ($route !== null && $route !== '') {
            $target = $route;
        } elseif (preg_match('/^[A-Z]+$/', $target) === 1 && str_starts_with((string) ($args[3] ?? ''), '/')) {
            $target .= ' ' . (string) ($args[3] ?? '');
        }

        if ($target === '') {
            throw new FoundryError('CLI_EXECUTION_PLAN_TARGET_REQUIRED', 'validation', [], 'Feature or route target is required.');
        }

        $planNode = $this->findExecutionPlanNode($graph, $target);
        if ($planNode === null) {
            throw new FoundryError('EXECUTION_PLAN_NOT_FOUND', 'not_found', ['target' => $target], 'Execution plan not found.');
        }

        $payload = $planNode->payload();
        $guardRows = [];
        foreach (array_values(array_map('strval', (array) ($payload['guards'] ?? []))) as $guardId) {
            $guardNode = $graph->node($guardId);
            if ($guardNode === null) {
                continue;
            }
            $guardRows[] = $guardNode->toArray();
        }
        usort(
            $guardRows,
            static fn (array $a, array $b): int => strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? '')),
        );

        $interceptorRows = [];
        foreach ((array) ($payload['interceptors'] ?? []) as $stage => $ids) {
            $stageName = (string) $stage;
            foreach ((array) $ids as $id) {
                $node = $graph->node('interceptor:' . (string) $id);
                if ($node === null) {
                    continue;
                }
                $row = $node->toArray();
                $row['stage'] = $stageName;
                $interceptorRows[] = $row;
            }
        }
        usort(
            $interceptorRows,
            static fn (array $a, array $b): int => strcmp((string) ($a['stage'] ?? '') . ':' . (string) ($a['id'] ?? ''), (string) ($b['stage'] ?? '') . ':' . (string) ($b['id'] ?? '')),
        );

        return [
            'status' => 0,
            'message' => null,
            'payload' => [
                'target' => $target,
                'plan' => $planNode->toArray(),
                'guards' => $guardRows,
                'interceptors' => $interceptorRows,
            ],
        ];
    }

    private function inspectGuards(ApplicationGraph $graph, string $feature): array
    {
        $guardRows = [];
        $feature = trim($feature);

        foreach ($graph->nodesByType('guard') as $node) {
            $payload = $node->payload();
            $nodeFeature = (string) ($payload['feature'] ?? '');
            if ($feature !== '' && $nodeFeature !== $feature) {
                continue;
            }

            $guardRows[] = [
                'id' => $node->id(),
                'feature' => $nodeFeature,
                'type' => (string) ($payload['type'] ?? ''),
                'stage' => $this->guardStage($graph, $node->id()),
                'config' => $payload,
            ];
        }

        usort(
            $guardRows,
            static fn (array $a, array $b): int => strcmp((string) ($a['feature'] ?? '') . ':' . (string) ($a['id'] ?? ''), (string) ($b['feature'] ?? '') . ':' . (string) ($b['id'] ?? '')),
        );

        return [
            'status' => 0,
            'message' => null,
            'payload' => [
                'feature' => $feature !== '' ? $feature : null,
                'guards' => $guardRows,
            ],
        ];
    }

    private function inspectInterceptors(ApplicationGraph $graph, ?string $stageFilter): array
    {
        $stageFilter = $stageFilter !== null ? trim($stageFilter) : null;
        if ($stageFilter === '') {
            $stageFilter = null;
        }

        $rows = [];
        foreach ($graph->nodesByType('interceptor') as $node) {
            $payload = $node->payload();
            $stage = (string) ($payload['stage'] ?? '');
            if ($stageFilter !== null && $stage !== $stageFilter) {
                continue;
            }

            $rows[] = [
                'id' => (string) ($payload['id'] ?? $node->id()),
                'node_id' => $node->id(),
                'stage' => $stage,
                'priority' => (int) ($payload['priority'] ?? 100),
                'dangerous' => (bool) ($payload['dangerous'] ?? false),
            ];
        }

        usort(
            $rows,
            static fn (array $a, array $b): int => strcmp((string) ($a['stage'] ?? ''), (string) ($b['stage'] ?? ''))
                ?: ((int) ($a['priority'] ?? 0) <=> (int) ($b['priority'] ?? 0))
                ?: strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? '')),
        );

        return [
            'status' => 0,
            'message' => null,
            'payload' => [
                'stage' => $stageFilter,
                'interceptors' => $rows,
            ],
        ];
    }

    /**
     * @param array<int,string> $args
     */
    private function inspectImpact(array $args, ApplicationGraph $graph, CommandContext $context): array
    {
        $file = $this->extractOption($args, '--file');
        if ($file !== null && $file !== '') {
            $report = $context->graphCompiler()->impactAnalyzer()->reportForFile($graph, $file);

            return [
                'status' => 0,
                'message' => null,
                'payload' => $report,
            ];
        }

        $nodeId = (string) ($args[2] ?? '');
        if ($nodeId === '') {
            throw new FoundryError('CLI_IMPACT_TARGET_REQUIRED', 'validation', [], 'Node ID or --file=<path> is required for inspect impact.');
        }

        $report = $context->graphCompiler()->impactAnalyzer()->reportForNode($graph, $nodeId);

        return [
            'status' => 0,
            'message' => null,
            'payload' => $report,
        ];
    }

    private function inspectAffectedTests(ApplicationGraph $graph, string $nodeId, CommandContext $context): array
    {
        if ($nodeId === '') {
            throw new FoundryError('CLI_NODE_REQUIRED', 'validation', [], 'Node ID required.');
        }

        $tests = $context->graphCompiler()->impactAnalyzer()->affectedTests($graph, $nodeId);

        return [
            'status' => 0,
            'message' => null,
            'payload' => [
                'node_id' => $nodeId,
                'tests' => $tests,
            ],
        ];
    }

    private function inspectAffectedFeatures(ApplicationGraph $graph, string $nodeId, CommandContext $context): array
    {
        if ($nodeId === '') {
            throw new FoundryError('CLI_NODE_REQUIRED', 'validation', [], 'Node ID required.');
        }

        $features = $context->graphCompiler()->impactAnalyzer()->affectedFeatures($graph, $nodeId);

        return [
            'status' => 0,
            'message' => null,
            'payload' => [
                'node_id' => $nodeId,
                'features' => $features,
            ],
        ];
    }

    private function findExecutionPlanNode(ApplicationGraph $graph, string $target): ?GraphNode
    {
        $normalized = trim($target);
        if ($normalized === '') {
            return null;
        }

        if (str_starts_with($normalized, 'execution_plan:')) {
            return $graph->node($normalized);
        }

        if (str_starts_with($normalized, 'feature:')) {
            return $graph->node('execution_plan:feature:' . substr($normalized, strlen('feature:')));
        }

        if (str_starts_with($normalized, 'route:')) {
            foreach ($graph->dependencies($normalized) as $edge) {
                if ($edge->type === 'route_to_execution_plan') {
                    return $graph->node($edge->to);
                }
            }

            return null;
        }

        if (preg_match('/^[A-Z]+\\s+\\/.+$/', $normalized) === 1) {
            $parts = preg_split('/\\s+/', $normalized, 2) ?: [];
            $method = strtoupper((string) ($parts[0] ?? ''));
            $path = (string) ($parts[1] ?? '');

            return $this->findExecutionPlanNode($graph, 'route:' . $method . ':' . $path);
        }

        return $graph->node('execution_plan:feature:' . $normalized);
    }

    private function guardStage(ApplicationGraph $graph, string $guardNodeId): string
    {
        foreach ($graph->dependencies($guardNodeId) as $edge) {
            if ($edge->type !== 'guard_to_pipeline_stage') {
                continue;
            }

            return (string) ($graph->node($edge->to)?->payload()['name'] ?? '');
        }

        return '';
    }

    private function loadOrCompileGraph(GraphCompiler $compiler): ApplicationGraph
    {
        $graph = $compiler->loadGraph();
        if ($graph !== null) {
            return $graph;
        }

        return $compiler->compile(new CompileOptions())->graph;
    }

    /**
     * @return array<string,mixed>
     */
    private function diagnosticsSummary(GraphCompiler $compiler): array
    {
        $path = $compiler->buildLayout()->diagnosticsPath();
        $json = $this->readJson($path);

        return is_array($json['summary'] ?? null) ? $json['summary'] : ['error' => 0, 'warning' => 0, 'info' => 0, 'total' => 0];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function diagnosticsForNode(string $nodeId, CommandContext $context): array
    {
        $diagnosticsJson = $this->readJson($context->graphCompiler()->buildLayout()->diagnosticsPath());
        $items = is_array($diagnosticsJson['diagnostics'] ?? null) ? $diagnosticsJson['diagnostics'] : [];

        $filtered = array_values(array_filter(
            $items,
            static function (mixed $item) use ($nodeId): bool {
                if (!is_array($item)) {
                    return false;
                }

                $itemNode = (string) ($item['node_id'] ?? '');
                if ($itemNode === $nodeId) {
                    return true;
                }

                $related = array_values(array_map('strval', (array) ($item['related_nodes'] ?? [])));

                return in_array($nodeId, $related, true);
            },
        ));

        usort(
            $filtered,
            static fn (array $a, array $b): int => strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? '')),
        );

        return $filtered;
    }

    /**
     * @param array<int,string> $args
     */
    private function extractOption(array $args, string $name): ?string
    {
        foreach ($args as $index => $arg) {
            if (str_starts_with($arg, $name . '=')) {
                return substr($arg, strlen($name . '='));
            }

            if ($arg === $name) {
                $value = (string) ($args[$index + 1] ?? '');
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return null;
        }

        try {
            return Json::decodeAssoc($content);
        } catch (\Throwable) {
            return null;
        }
    }
}
