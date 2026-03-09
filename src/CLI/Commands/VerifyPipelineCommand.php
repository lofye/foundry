<?php
declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Compiler\ApplicationGraph;
use Foundry\Compiler\CompileOptions;
use Foundry\Pipeline\PipelineDefinitionResolver;

final class VerifyPipelineCommand extends Command
{
    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'verify' && ($args[1] ?? null) === 'pipeline';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $compiler = $context->graphCompiler();
        $graph = $compiler->loadGraph() ?? $compiler->compile(new CompileOptions())->graph;

        [$errors, $warnings, $summary] = $this->verifyGraph($graph);
        $ok = $errors === [];

        return [
            'status' => $ok ? 0 : 1,
            'message' => $ok ? 'Pipeline verification passed.' : 'Pipeline verification failed.',
            'payload' => [
                'ok' => $ok,
                'errors' => $errors,
                'warnings' => $warnings,
                'summary' => $summary,
            ],
        ];
    }

    /**
     * @return array{0:array<int,string>,1:array<int,string>,2:array<string,mixed>}
     */
    private function verifyGraph(ApplicationGraph $graph): array
    {
        $errors = [];
        $warnings = [];

        $required = PipelineDefinitionResolver::defaultStages();
        $stageNodes = [];
        foreach ($graph->nodesByType('pipeline_stage') as $node) {
            $name = (string) ($node->payload()['name'] ?? '');
            if ($name !== '') {
                $stageNodes[$name] = $node->id();
            }
        }
        ksort($stageNodes);

        foreach ($required as $stage) {
            if (!isset($stageNodes[$stage])) {
                $errors[] = 'Missing required pipeline stage: ' . $stage;
            }
        }

        $stageNextEdges = 0;
        foreach ($graph->edges() as $edge) {
            if ($edge->type === 'pipeline_stage_next') {
                $stageNextEdges++;
            }
        }
        if (count($stageNodes) > 1 && $stageNextEdges === 0) {
            $errors[] = 'Pipeline stage ordering edges are missing.';
        }

        $executionPlans = $graph->nodesByType('execution_plan');
        if ($executionPlans === []) {
            $errors[] = 'No execution plans were compiled.';
        }

        foreach ($graph->nodesByType('route') as $routeNode) {
            $hasPlan = false;
            foreach ($graph->dependencies($routeNode->id()) as $edge) {
                if ($edge->type === 'route_to_execution_plan') {
                    $hasPlan = true;
                    break;
                }
            }
            if (!$hasPlan) {
                $errors[] = 'Route missing execution plan: ' . (string) ($routeNode->payload()['signature'] ?? $routeNode->id());
            }
        }

        foreach ($graph->nodesByType('feature') as $featureNode) {
            $feature = (string) ($featureNode->payload()['feature'] ?? '');
            if ($feature === '') {
                continue;
            }

            $plan = $graph->node('execution_plan:feature:' . $feature);
            if ($plan === null) {
                $errors[] = 'Feature missing execution plan: ' . $feature;
                continue;
            }

            $payload = $plan->payload();
            $stages = array_values(array_map('strval', (array) ($payload['stages'] ?? [])));
            if (!in_array('action', $stages, true)) {
                $errors[] = 'Execution plan for ' . $feature . ' does not include action stage.';
            }
            if (!in_array('validation', $stages, true)) {
                $warnings[] = 'Execution plan for ' . $feature . ' does not include validation stage.';
            }

            $actionNode = (string) ($payload['action_node'] ?? '');
            if ($actionNode === '' || $actionNode !== $featureNode->id()) {
                $warnings[] = 'Execution plan action target mismatch for feature ' . $feature . '.';
            }
        }

        foreach ($graph->nodesByType('guard') as $guardNode) {
            $hasStage = false;
            foreach ($graph->dependencies($guardNode->id()) as $edge) {
                if ($edge->type === 'guard_to_pipeline_stage') {
                    $hasStage = true;
                    break;
                }
            }

            if (!$hasStage) {
                $warnings[] = 'Guard is not attached to a pipeline stage: ' . $guardNode->id();
            }
        }

        foreach ($graph->nodesByType('interceptor') as $interceptorNode) {
            $stage = (string) ($interceptorNode->payload()['stage'] ?? '');
            if ($stage !== '' && !isset($stageNodes[$stage])) {
                $errors[] = 'Interceptor references unknown stage ' . $stage . ': ' . $interceptorNode->id();
            }
        }

        sort($errors);
        $errors = array_values(array_unique($errors));
        sort($warnings);
        $warnings = array_values(array_unique($warnings));

        return [
            $errors,
            $warnings,
            [
                'required_stages' => $required,
                'stage_count' => count($stageNodes),
                'execution_plan_count' => count($executionPlans),
                'guard_count' => count($graph->nodesByType('guard')),
                'interceptor_count' => count($graph->nodesByType('interceptor')),
            ],
        ];
    }
}
