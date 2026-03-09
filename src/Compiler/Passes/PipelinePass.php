<?php
declare(strict_types=1);

namespace Foundry\Compiler\Passes;

use Foundry\Compiler\CompilationState;
use Foundry\Compiler\CompilerPass;
use Foundry\Compiler\GraphEdge;
use Foundry\Compiler\IR\ExecutionPlanNode;
use Foundry\Compiler\IR\GuardNode;
use Foundry\Compiler\IR\InterceptorNode;
use Foundry\Compiler\IR\PipelineStageNode;
use Foundry\Pipeline\PipelineDefinitionResolver;
use Foundry\Pipeline\PipelineStageDefinition;
use Foundry\Pipeline\StageInterceptor;

final class PipelinePass implements CompilerPass
{
    public function name(): string
    {
        return 'pipeline';
    }

    public function run(CompilationState $state): void
    {
        $resolver = new PipelineDefinitionResolver();
        $resolved = $resolver->resolve($state->extensions->pipelineStages(), $state->diagnostics);
        $orderedStages = array_values(array_map('strval', (array) ($resolved['ordered_stages'] ?? [])));
        if ($orderedStages === []) {
            $orderedStages = PipelineDefinitionResolver::defaultStages();
        }

        $definitions = is_array($resolved['definitions'] ?? null) ? $resolved['definitions'] : [];

        $stageNodes = $this->registerStages($state, $orderedStages, $definitions);
        $interceptorsByStage = $this->registerInterceptors($state, $stageNodes);
        $routeByFeature = $this->routeByFeature($state);
        $guardNodes = [];

        foreach ($state->graph->nodesByType('feature') as $featureNode) {
            $payload = $featureNode->payload();
            $feature = (string) ($payload['feature'] ?? '');
            if ($feature === '') {
                continue;
            }

            $featureId = $featureNode->id();
            $routeId = $routeByFeature[$feature] ?? null;
            $routePayload = $routeId !== null
                ? ($state->graph->node($routeId)?->payload() ?? [])
                : [];
            $routeSignature = (string) ($routePayload['signature'] ?? '');

            $guards = $this->buildGuards($feature, $payload, $state);
            foreach ($guards as $guard) {
                $guardId = (string) ($guard['id'] ?? '');
                if ($guardId === '') {
                    continue;
                }

                if (!isset($guardNodes[$guardId])) {
                    $guardPayload = is_array($guard['payload'] ?? null) ? $guard['payload'] : [];
                    $sourcePath = (string) ($guard['source_path'] ?? $featureNode->sourcePath());
                    $state->graph->addNode(new GuardNode(
                        $guardId,
                        $sourcePath,
                        $guardPayload,
                        ['line_start' => 1, 'line_end' => null],
                        [1],
                    ));
                    $guardNodes[$guardId] = true;
                }

                $state->graph->addEdge(GraphEdge::make('feature_to_guard', $featureId, $guardId));

                $stage = (string) ($guard['stage'] ?? '');
                $stageNodeId = $stage !== '' ? ('pipeline_stage:' . $stage) : null;
                if ($stageNodeId !== null && isset($stageNodes[$stageNodeId])) {
                    $state->graph->addEdge(GraphEdge::make('guard_to_pipeline_stage', $guardId, $stageNodeId));
                }
            }

            $planId = 'execution_plan:feature:' . $feature;
            $guardIds = array_values(array_map(
                static fn (array $guard): string => (string) ($guard['id'] ?? ''),
                $guards,
            ));
            $guardIds = array_values(array_unique(array_filter($guardIds, static fn (string $id): bool => $id !== '')));
            sort($guardIds);

            $interceptorMap = [];
            foreach ($orderedStages as $stage) {
                $items = $interceptorsByStage[$stage] ?? [];
                sort($items);
                $interceptorMap[$stage] = array_values(array_unique($items));
            }

            $planPayload = [
                'feature' => $feature,
                'route_signature' => $routeSignature,
                'route_node' => $routeId,
                'stages' => $orderedStages,
                'guards' => $guardIds,
                'interceptors' => $interceptorMap,
                'action_node' => $featureId,
                'plan_version' => 1,
            ];

            $state->graph->addNode(new ExecutionPlanNode(
                $planId,
                (string) ($payload['manifest_path'] ?? $featureNode->sourcePath()),
                $planPayload,
                ['line_start' => 1, 'line_end' => null],
                [1],
            ));

            $state->graph->addEdge(GraphEdge::make('feature_to_execution_plan', $featureId, $planId));
            $state->graph->addEdge(GraphEdge::make('execution_plan_to_feature_action', $planId, $featureId));

            if ($routeId !== null) {
                $state->graph->addEdge(GraphEdge::make('route_to_execution_plan', $routeId, $planId));
            }

            foreach ($orderedStages as $stage) {
                $stageId = 'pipeline_stage:' . $stage;
                if (!isset($stageNodes[$stageId])) {
                    continue;
                }

                $state->graph->addEdge(GraphEdge::make('execution_plan_to_stage', $planId, $stageId));

                foreach ($interceptorsByStage[$stage] ?? [] as $interceptorId) {
                    $state->graph->addEdge(GraphEdge::make(
                        'execution_plan_to_interceptor',
                        $planId,
                        'interceptor:' . $interceptorId,
                        ['stage' => $stage],
                    ));
                }
            }

            foreach ($guardIds as $guardId) {
                $state->graph->addEdge(GraphEdge::make('execution_plan_to_guard', $planId, $guardId));
            }
        }

        $state->analysis['pipeline'] = [
            'ordered_stages' => $orderedStages,
            'interceptors_by_stage' => $interceptorsByStage,
        ];
    }

    /**
     * @param array<int,string> $orderedStages
     * @param array<string,mixed> $definitions
     * @return array<string,bool>
     */
    private function registerStages(CompilationState $state, array $orderedStages, array $definitions): array
    {
        $stageNodes = [];
        $count = count($orderedStages);

        foreach ($orderedStages as $index => $stage) {
            $stageId = 'pipeline_stage:' . $stage;
            $definition = $definitions[$stage] ?? null;
            $extension = $definition instanceof PipelineStageDefinition ? (string) ($definition->extension ?? 'core') : 'core';
            $priority = $definition instanceof PipelineStageDefinition ? $definition->priority : ($index + 10);
            $afterStage = $definition instanceof PipelineStageDefinition ? $definition->afterStage : ($index > 0 ? $orderedStages[$index - 1] : null);
            $beforeStage = $definition instanceof PipelineStageDefinition ? $definition->beforeStage : ($index < $count - 1 ? $orderedStages[$index + 1] : null);

            $state->graph->addNode(new PipelineStageNode(
                $stageId,
                'app/.foundry/pipeline',
                [
                    'name' => $stage,
                    'order' => $index,
                    'priority' => $priority,
                    'extension' => $extension,
                    'after_stage' => $afterStage,
                    'before_stage' => $beforeStage,
                ],
                ['line_start' => $index + 1, 'line_end' => $index + 1],
                [1],
            ));
            $stageNodes[$stageId] = true;

            if ($index > 0) {
                $previousId = 'pipeline_stage:' . $orderedStages[$index - 1];
                $state->graph->addEdge(GraphEdge::make('pipeline_stage_next', $previousId, $stageId));
            }
        }

        return $stageNodes;
    }

    /**
     * @param array<string,bool> $stageNodes
     * @return array<string,array<int,string>>
     */
    private function registerInterceptors(CompilationState $state, array $stageNodes): array
    {
        $interceptorsByStage = [];
        $seenIds = [];

        foreach ($state->extensions->pipelineInterceptors() as $interceptor) {
            if (!$interceptor instanceof StageInterceptor) {
                continue;
            }

            $id = trim($interceptor->id());
            if ($id === '') {
                continue;
            }

            if (isset($seenIds[$id])) {
                $state->diagnostics->warning(
                    code: 'FDY8002_INTERCEPTOR_STAGE_CONFLICT',
                    category: 'pipeline',
                    message: sprintf('Duplicate interceptor id %s detected; keeping first registration.', $id),
                    nodeId: 'interceptor:' . $id,
                    pass: $this->name(),
                );
                continue;
            }
            $seenIds[$id] = true;

            $stage = trim($interceptor->stage());
            if ($stage === '' || !isset($stageNodes['pipeline_stage:' . $stage])) {
                $state->diagnostics->error(
                    code: 'FDY8002_INTERCEPTOR_STAGE_CONFLICT',
                    category: 'pipeline',
                    message: sprintf('Interceptor %s attaches to unknown stage %s.', $id, $stage === '' ? '(empty)' : $stage),
                    nodeId: 'interceptor:' . $id,
                    suggestedFix: 'Attach interceptor to a known stage declared in the pipeline definition.',
                    pass: $this->name(),
                );
                continue;
            }

            $interceptorsByStage[$stage] ??= [];
            $interceptorsByStage[$stage][] = $id;
            sort($interceptorsByStage[$stage]);
            $interceptorsByStage[$stage] = array_values(array_unique($interceptorsByStage[$stage]));

            $interceptorId = 'interceptor:' . $id;
            $state->graph->addNode(new InterceptorNode(
                $interceptorId,
                'app/.foundry/extensions',
                [
                    'id' => $id,
                    'stage' => $stage,
                    'priority' => $interceptor->priority(),
                    'dangerous' => $interceptor->isDangerous(),
                ],
                ['line_start' => 1, 'line_end' => null],
                [1],
            ));

            $state->graph->addEdge(GraphEdge::make(
                'interceptor_to_pipeline_stage',
                $interceptorId,
                'pipeline_stage:' . $stage,
            ));
        }

        ksort($interceptorsByStage);

        return $interceptorsByStage;
    }

    /**
     * @return array<string,string>
     */
    private function routeByFeature(CompilationState $state): array
    {
        $routeByFeature = [];
        foreach ($state->graph->nodesByType('route') as $routeNode) {
            $features = array_values(array_map('strval', (array) ($routeNode->payload()['features'] ?? [])));
            foreach ($features as $feature) {
                if ($feature === '' || isset($routeByFeature[$feature])) {
                    continue;
                }
                $routeByFeature[$feature] = $routeNode->id();
            }
        }

        ksort($routeByFeature);

        return $routeByFeature;
    }

    /**
     * @param array<string,mixed> $featurePayload
     * @return array<int,array{id:string,stage:string,source_path:string,payload:array<string,mixed>}>
     */
    private function buildGuards(string $feature, array $featurePayload, CompilationState $state): array
    {
        $guards = [];
        $manifestPath = (string) ($featurePayload['manifest_path'] ?? ('app/features/' . $feature . '/feature.yaml'));
        $route = is_array($featurePayload['route'] ?? null) ? $featurePayload['route'] : [];
        $method = strtoupper((string) ($route['method'] ?? 'GET'));

        $auth = is_array($featurePayload['auth'] ?? null) ? $featurePayload['auth'] : [];
        $permissions = array_values(array_unique(array_filter(array_map('strval', (array) ($auth['permissions'] ?? [])), static fn (string $value): bool => $value !== '')));
        sort($permissions);
        $authRequired = (bool) ($auth['required'] ?? false);
        $authPublic = (bool) ($auth['public'] ?? false);

        if ($authRequired || $permissions !== []) {
            $guards[] = [
                'id' => 'guard:auth:' . $feature,
                'stage' => 'auth',
                'source_path' => $manifestPath,
                'payload' => [
                    'feature' => $feature,
                    'type' => 'authentication',
                    'required' => $authRequired,
                    'public' => $authPublic,
                    'strategies' => array_values(array_map('strval', (array) ($auth['strategies'] ?? []))),
                ],
            ];
        }

        if ($this->shouldRequireAuth($featurePayload, $method) && !$authRequired && !$authPublic && $permissions === []) {
            $state->diagnostics->warning(
                code: 'FDY8001_FEATURE_REQUIRES_AUTH',
                category: 'auth',
                message: sprintf('Feature %s handles a write-capable route but has no authentication guard.', $feature),
                nodeId: 'feature:' . $feature,
                sourcePath: $manifestPath,
                suggestedFix: 'Set auth.required: true (or auth.public: true when explicitly intended).',
                pass: $this->name(),
            );
        }

        foreach ($permissions as $permission) {
            $guards[] = [
                'id' => 'guard:permission:' . $feature . ':' . $this->slug($permission),
                'stage' => 'auth',
                'source_path' => $manifestPath,
                'payload' => [
                    'feature' => $feature,
                    'type' => 'permission',
                    'permission' => $permission,
                ],
            ];
        }

        $rateLimit = is_array($featurePayload['rate_limit'] ?? null) ? $featurePayload['rate_limit'] : [];
        if ($rateLimit !== []) {
            $hasBucket = isset($rateLimit['bucket']) && (string) $rateLimit['bucket'] !== '';
            $multipleBuckets = is_array($rateLimit['buckets'] ?? null) && count((array) $rateLimit['buckets']) > 1;
            $multipleLimits = is_array($rateLimit['limits'] ?? null) && count((array) $rateLimit['limits']) > 1;
            if (($hasBucket && isset($rateLimit['buckets'])) || $multipleBuckets || $multipleLimits) {
                $state->diagnostics->error(
                    code: 'FDY8003_CONFLICTING_RATE_LIMIT',
                    category: 'pipeline',
                    message: sprintf('Feature %s declares conflicting rate limit configuration.', $feature),
                    nodeId: 'feature:' . $feature,
                    sourcePath: $manifestPath,
                    suggestedFix: 'Define one deterministic rate limit strategy per feature.',
                    pass: $this->name(),
                );
            }

            $bucket = (string) ($rateLimit['bucket'] ?? $feature);
            $guards[] = [
                'id' => 'guard:rate_limit:' . $feature . ':' . $this->slug($bucket),
                'stage' => 'before_auth',
                'source_path' => $manifestPath,
                'payload' => [
                    'feature' => $feature,
                    'type' => 'rate_limit',
                    'strategy' => (string) ($rateLimit['strategy'] ?? 'user'),
                    'bucket' => $bucket,
                    'cost' => (int) ($rateLimit['cost'] ?? 1),
                ],
            ];
        }

        $csrf = is_array($featurePayload['csrf'] ?? null) ? $featurePayload['csrf'] : [];
        if ((bool) ($csrf['required'] ?? false)) {
            $guards[] = [
                'id' => 'guard:csrf:' . $feature,
                'stage' => 'before_validation',
                'source_path' => $manifestPath,
                'payload' => [
                    'feature' => $feature,
                    'type' => 'csrf',
                    'required' => true,
                ],
            ];
        }

        $inputSchemaPath = (string) ($featurePayload['input_schema_path'] ?? '');
        if ($inputSchemaPath !== '') {
            $guards[] = [
                'id' => 'guard:request_validation:' . $feature,
                'stage' => 'validation',
                'source_path' => $manifestPath,
                'payload' => [
                    'feature' => $feature,
                    'type' => 'request_validation',
                    'schema' => $inputSchemaPath,
                ],
            ];
        }

        $database = is_array($featurePayload['database'] ?? null) ? $featurePayload['database'] : [];
        if ((string) ($database['transactions'] ?? '') === 'required') {
            $guards[] = [
                'id' => 'guard:transaction:' . $feature,
                'stage' => 'before_action',
                'source_path' => $manifestPath,
                'payload' => [
                    'feature' => $feature,
                    'type' => 'transaction',
                    'mode' => 'required',
                ],
            ];
        }

        usort(
            $guards,
            static fn (array $a, array $b): int => strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? '')),
        );

        return $guards;
    }

    /**
     * @param array<string,mixed> $featurePayload
     */
    private function shouldRequireAuth(array $featurePayload, string $method): bool
    {
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return true;
        }

        $writes = array_values(array_map('strval', (array) ($featurePayload['database']['writes'] ?? [])));

        return $writes !== [];
    }

    private function slug(string $value): string
    {
        $slug = preg_replace('/[^a-zA-Z0-9]+/', '_', strtolower($value));
        if (!is_string($slug) || $slug === '') {
            return 'default';
        }

        return trim($slug, '_');
    }
}
