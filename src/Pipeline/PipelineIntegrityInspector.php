<?php
declare(strict_types=1);

namespace Foundry\Pipeline;

use Foundry\Compiler\ApplicationGraph;
use Foundry\Compiler\IR\GraphNode;

final class PipelineIntegrityInspector
{
    /**
     * @return array{
     *   issues:array<int,array<string,mixed>>,
     *   summary:array<string,mixed>
     * }
     */
    public function inspect(ApplicationGraph $graph, ?string $featureFilter = null): array
    {
        $issues = [];
        $requiredStages = PipelineDefinitionResolver::defaultStages();
        $stageNodes = [];

        foreach ($graph->nodesByType('pipeline_stage') as $node) {
            $name = (string) ($node->payload()['name'] ?? '');
            if ($name !== '') {
                $stageNodes[$name] = $node->id();
            }
        }
        ksort($stageNodes);

        foreach ($requiredStages as $stage) {
            if (!isset($stageNodes[$stage])) {
                $issues[] = [
                    'code' => 'FDY9115_PIPELINE_STAGE_MISSING',
                    'severity' => 'error',
                    'category' => 'pipeline',
                    'message' => 'Missing required pipeline stage: ' . $stage . '.',
                    'suggested_fix' => 'Register the missing stage before compiling runtime execution plans.',
                    'why_it_matters' => 'Foundry routes and features rely on the canonical pipeline stage sequence to produce consistent runtime behavior.',
                    'details' => ['stage' => $stage],
                ];
            }
        }

        $stageNextEdges = 0;
        foreach ($graph->edges() as $edge) {
            if ($edge->type === 'pipeline_stage_next') {
                $stageNextEdges++;
            }
        }
        if (count($stageNodes) > 1 && $stageNextEdges === 0) {
            $issues[] = [
                'code' => 'FDY9116_PIPELINE_STAGE_ORDER_MISSING',
                'severity' => 'error',
                'category' => 'pipeline',
                'message' => 'Pipeline stage ordering edges are missing.',
                'suggested_fix' => 'Rebuild the pipeline graph so stage-to-stage ordering edges are emitted.',
                'why_it_matters' => 'Without stage ordering, runtime execution cannot deterministically sequence guards, validation, action, and response work.',
                'details' => ['stage_count' => count($stageNodes)],
            ];
        }

        $executionPlans = array_values(array_filter(
            $graph->nodesByType('execution_plan'),
            fn (GraphNode $node): bool => $this->includesFeature($node, $featureFilter),
        ));

        if ($executionPlans === []) {
            $issues[] = [
                'code' => 'FDY9117_EXECUTION_PLANS_MISSING',
                'severity' => 'error',
                'category' => 'pipeline',
                'message' => $featureFilter === null || $featureFilter === ''
                    ? 'No execution plans were compiled.'
                    : 'No execution plan was compiled for feature ' . $featureFilter . '.',
                'suggested_fix' => 'Recompile the graph and inspect pipeline generation for the affected feature.',
                'why_it_matters' => 'Execution plans drive request handling and background execution; missing plans usually mean routes cannot run safely.',
                'details' => ['feature_filter' => $featureFilter],
            ];
        }

        foreach ($graph->nodesByType('route') as $routeNode) {
            if (!$this->includesFeature($routeNode, $featureFilter)) {
                continue;
            }

            $hasPlan = false;
            foreach ($graph->dependencies($routeNode->id()) as $edge) {
                if ($edge->type === 'route_to_execution_plan') {
                    $hasPlan = true;
                    break;
                }
            }

            if ($hasPlan) {
                continue;
            }

            $issues[] = [
                'code' => 'FDY9118_ROUTE_EXECUTION_PLAN_MISSING',
                'severity' => 'error',
                'category' => 'pipeline',
                'message' => 'Route missing execution plan: ' . (string) ($routeNode->payload()['signature'] ?? $routeNode->id()) . '.',
                'node_id' => $routeNode->id(),
                'source_path' => $routeNode->sourcePath(),
                'suggested_fix' => 'Recompile the graph and inspect the route execution plan projection.',
                'why_it_matters' => 'A route without an execution plan cannot be dispatched through the runtime pipeline.',
                'details' => [
                    'feature' => (string) ($routeNode->payload()['feature'] ?? ''),
                    'route' => (string) ($routeNode->payload()['signature'] ?? ''),
                ],
            ];
        }

        foreach ($graph->nodesByType('feature') as $featureNode) {
            if (!$this->includesFeature($featureNode, $featureFilter)) {
                continue;
            }

            $feature = (string) ($featureNode->payload()['feature'] ?? '');
            if ($feature === '') {
                continue;
            }

            $plan = $graph->node('execution_plan:feature:' . $feature);
            if ($plan === null) {
                $issues[] = [
                    'code' => 'FDY9119_FEATURE_EXECUTION_PLAN_MISSING',
                    'severity' => 'error',
                    'category' => 'pipeline',
                    'message' => 'Feature missing execution plan: ' . $feature . '.',
                    'node_id' => $featureNode->id(),
                    'source_path' => $featureNode->sourcePath(),
                    'suggested_fix' => 'Recompile the graph and inspect pipeline generation for this feature.',
                    'why_it_matters' => 'Every compiled feature needs an execution plan so the runtime can attach guards, action handlers, and response stages.',
                    'details' => ['feature' => $feature],
                ];
                continue;
            }

            $payload = $plan->payload();
            $stages = array_values(array_map('strval', (array) ($payload['stages'] ?? [])));
            if (!in_array('action', $stages, true)) {
                $issues[] = [
                    'code' => 'FDY9120_EXECUTION_PLAN_ACTION_STAGE_MISSING',
                    'severity' => 'error',
                    'category' => 'pipeline',
                    'message' => 'Execution plan for ' . $feature . ' does not include action stage.',
                    'node_id' => $plan->id(),
                    'source_path' => $plan->sourcePath(),
                    'suggested_fix' => 'Ensure the feature compiles to a runnable action stage and recompile the graph.',
                    'why_it_matters' => 'The action stage is where the feature work actually runs; without it, the plan cannot complete useful work.',
                    'details' => ['feature' => $feature],
                ];
            }

            if (!in_array('validation', $stages, true)) {
                $issues[] = [
                    'code' => 'FDY9121_EXECUTION_PLAN_VALIDATION_STAGE_MISSING',
                    'severity' => 'warning',
                    'category' => 'pipeline',
                    'message' => 'Execution plan for ' . $feature . ' does not include validation stage.',
                    'node_id' => $plan->id(),
                    'source_path' => $plan->sourcePath(),
                    'suggested_fix' => 'Emit request validation guards for the feature input schema and recompile the graph.',
                    'why_it_matters' => 'Validation stages enforce input contracts before feature logic runs, which reduces runtime contract drift.',
                    'details' => ['feature' => $feature],
                ];
            }

            $actionNode = (string) ($payload['action_node'] ?? '');
            if ($actionNode === '' || $actionNode !== $featureNode->id()) {
                $issues[] = [
                    'code' => 'FDY9122_EXECUTION_PLAN_ACTION_TARGET_MISMATCH',
                    'severity' => 'warning',
                    'category' => 'pipeline',
                    'message' => 'Execution plan action target mismatch for feature ' . $feature . '.',
                    'node_id' => $plan->id(),
                    'source_path' => $plan->sourcePath(),
                    'suggested_fix' => 'Rebuild the execution plan so its action target points at the compiled feature node.',
                    'why_it_matters' => 'Action target mismatches can route execution to the wrong node or make runtime tracing unreliable.',
                    'details' => [
                        'feature' => $feature,
                        'expected_action_node' => $featureNode->id(),
                        'actual_action_node' => $actionNode,
                    ],
                ];
            }
        }

        foreach ($graph->nodesByType('guard') as $guardNode) {
            if (!$this->includesFeature($guardNode, $featureFilter)) {
                continue;
            }

            $hasStage = false;
            foreach ($graph->dependencies($guardNode->id()) as $edge) {
                if ($edge->type === 'guard_to_pipeline_stage') {
                    $hasStage = true;
                    break;
                }
            }

            if ($hasStage) {
                continue;
            }

            $issues[] = [
                'code' => 'FDY9123_GUARD_STAGE_MISSING',
                'severity' => 'warning',
                'category' => 'pipeline',
                'message' => 'Guard is not attached to a pipeline stage: ' . $guardNode->id() . '.',
                'node_id' => $guardNode->id(),
                'source_path' => $guardNode->sourcePath(),
                'suggested_fix' => 'Attach the guard to a canonical pipeline stage and recompile the graph.',
                'why_it_matters' => 'Unattached guards will not run deterministically, which can bypass validation or auth expectations.',
                'details' => ['feature' => (string) ($guardNode->payload()['feature'] ?? '')],
            ];
        }

        foreach ($graph->nodesByType('interceptor') as $interceptorNode) {
            $stage = (string) ($interceptorNode->payload()['stage'] ?? '');
            if ($stage === '' || isset($stageNodes[$stage])) {
                continue;
            }

            $issues[] = [
                'code' => 'FDY9124_INTERCEPTOR_STAGE_UNKNOWN',
                'severity' => 'error',
                'category' => 'pipeline',
                'message' => 'Interceptor references unknown stage ' . $stage . ': ' . $interceptorNode->id() . '.',
                'node_id' => $interceptorNode->id(),
                'source_path' => $interceptorNode->sourcePath(),
                'suggested_fix' => 'Point the interceptor at a registered pipeline stage or register the missing stage.',
                'why_it_matters' => 'Interceptors bound to missing stages never execute and can break tracing, auth, or response instrumentation.',
                'details' => ['stage' => $stage],
            ];
        }

        usort(
            $issues,
            static fn (array $a, array $b): int => strcmp((string) ($a['code'] ?? ''), (string) ($b['code'] ?? ''))
                ?: strcmp((string) ($a['message'] ?? ''), (string) ($b['message'] ?? '')),
        );

        return [
            'issues' => $issues,
            'summary' => [
                'required_stages' => $requiredStages,
                'stage_count' => count($stageNodes),
                'execution_plan_count' => count($executionPlans),
                'guard_count' => count(array_filter(
                    $graph->nodesByType('guard'),
                    fn (GraphNode $node): bool => $this->includesFeature($node, $featureFilter),
                )),
                'interceptor_count' => count($graph->nodesByType('interceptor')),
            ],
        ];
    }

    private function includesFeature(GraphNode $node, ?string $featureFilter): bool
    {
        if ($featureFilter === null || $featureFilter === '') {
            return true;
        }

        $feature = trim((string) ($node->payload()['feature'] ?? ''));
        if ($feature !== '') {
            return $feature === $featureFilter;
        }

        return $node->type() === 'pipeline_stage' || $node->type() === 'interceptor';
    }
}
