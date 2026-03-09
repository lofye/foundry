<?php
declare(strict_types=1);

namespace Foundry\Compiler\Analysis\Analyzers;

use Foundry\Compiler\Analysis\AnalyzerContext;
use Foundry\Compiler\Analysis\GraphAnalyzer;
use Foundry\Compiler\ApplicationGraph;
use Foundry\Compiler\Diagnostics\DiagnosticBag;
use Foundry\Pipeline\PipelineDefinitionResolver;

final class PipelineAnalyzer implements GraphAnalyzer
{
    public function id(): string
    {
        return 'pipeline_integrity';
    }

    public function description(): string
    {
        return 'Validates execution plans, required guards, and interceptor safety.';
    }

    /**
     * @return array<string,mixed>
     */
    public function analyze(ApplicationGraph $graph, AnalyzerContext $context, DiagnosticBag $diagnostics): array
    {
        $requiredStages = PipelineDefinitionResolver::defaultStages();
        $stageNames = [];
        foreach ($graph->nodesByType('pipeline_stage') as $node) {
            $name = (string) ($node->payload()['name'] ?? '');
            if ($name !== '') {
                $stageNames[] = $name;
            }
        }
        $stageNames = array_values(array_unique($stageNames));
        sort($stageNames);

        $missingStages = array_values(array_diff($requiredStages, $stageNames));
        sort($missingStages);
        foreach ($missingStages as $stage) {
            $diagnostics->warning(
                code: 'FDY9012_PIPELINE_STAGE_MISSING',
                category: 'pipeline',
                message: sprintf('Pipeline is missing required stage %s.', $stage),
                suggestedFix: 'Recompile graph and ensure pipeline stages are registered.',
                pass: 'doctor.' . $this->id(),
            );
        }

        $missingAuthGuards = [];
        $missingValidationGuards = [];
        $missingExecutionPlans = [];

        foreach ($graph->nodesByType('feature') as $featureNode) {
            $payload = $featureNode->payload();
            $feature = (string) ($payload['feature'] ?? '');
            if ($feature === '' || !$context->includesFeature($feature)) {
                continue;
            }

            $plan = $graph->node('execution_plan:feature:' . $feature);
            if ($plan === null) {
                $missingExecutionPlans[] = $feature;
                $diagnostics->warning(
                    code: 'FDY9013_EXECUTION_PLAN_MISSING',
                    category: 'pipeline',
                    message: sprintf('Feature %s has no execution plan node.', $feature),
                    nodeId: $featureNode->id(),
                    suggestedFix: 'Compile graph and ensure pipeline pass runs.',
                    pass: 'doctor.' . $this->id(),
                );
                continue;
            }

            $planPayload = $plan->payload();
            $guards = array_values(array_map('strval', (array) ($planPayload['guards'] ?? [])));
            sort($guards);

            $route = is_array($payload['route'] ?? null) ? $payload['route'] : [];
            $method = strtoupper((string) ($route['method'] ?? 'GET'));
            $authGuardPresent = $this->hasGuardType($graph, $guards, 'authentication');
            if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && !$authGuardPresent) {
                $missingAuthGuards[] = $feature;
                $diagnostics->warning(
                    code: 'FDY9014_PIPELINE_AUTH_GUARD_MISSING',
                    category: 'auth',
                    message: sprintf('Feature %s route %s is missing an authentication guard.', $feature, $method),
                    nodeId: $featureNode->id(),
                    suggestedFix: 'Declare auth.required in feature manifest.',
                    pass: 'doctor.' . $this->id(),
                );
            }

            $inputSchema = (string) ($payload['input_schema_path'] ?? '');
            if ($inputSchema !== '' && !$this->hasGuardType($graph, $guards, 'request_validation')) {
                $missingValidationGuards[] = $feature;
                $diagnostics->warning(
                    code: 'FDY9015_PIPELINE_VALIDATION_GUARD_MISSING',
                    category: 'pipeline',
                    message: sprintf('Feature %s is missing request validation guard.', $feature),
                    nodeId: $featureNode->id(),
                    suggestedFix: 'Keep input.schema configured and compile execution plan guards.',
                    pass: 'doctor.' . $this->id(),
                );
            }
        }

        $dangerousInterceptors = [];
        foreach ($graph->nodesByType('interceptor') as $interceptorNode) {
            $payload = $interceptorNode->payload();
            if (!(bool) ($payload['dangerous'] ?? false)) {
                continue;
            }

            $id = (string) ($payload['id'] ?? $interceptorNode->id());
            $stage = (string) ($payload['stage'] ?? '');
            $dangerousInterceptors[] = ['id' => $id, 'stage' => $stage];

            $diagnostics->warning(
                code: 'FDY9016_PIPELINE_DANGEROUS_INTERCEPTOR',
                category: 'pipeline',
                message: sprintf('Interceptor %s is marked dangerous at stage %s.', $id, $stage),
                nodeId: $interceptorNode->id(),
                suggestedFix: 'Review interceptor for side effects and ordering safety.',
                pass: 'doctor.' . $this->id(),
            );
        }

        sort($missingAuthGuards);
        $missingAuthGuards = array_values(array_unique($missingAuthGuards));
        sort($missingValidationGuards);
        $missingValidationGuards = array_values(array_unique($missingValidationGuards));
        sort($missingExecutionPlans);
        $missingExecutionPlans = array_values(array_unique($missingExecutionPlans));
        usort(
            $dangerousInterceptors,
            static fn (array $a, array $b): int => strcmp((string) ($a['stage'] ?? '') . ':' . (string) ($a['id'] ?? ''), (string) ($b['stage'] ?? '') . ':' . (string) ($b['id'] ?? '')),
        );

        return [
            'missing_stages' => $missingStages,
            'missing_execution_plans' => $missingExecutionPlans,
            'missing_auth_guards' => $missingAuthGuards,
            'missing_validation_guards' => $missingValidationGuards,
            'dangerous_interceptors' => $dangerousInterceptors,
        ];
    }

    /**
     * @param array<int,string> $guardIds
     */
    private function hasGuardType(ApplicationGraph $graph, array $guardIds, string $type): bool
    {
        foreach ($guardIds as $guardId) {
            $guardNode = $graph->node($guardId);
            if ($guardNode === null) {
                continue;
            }

            if ((string) ($guardNode->payload()['type'] ?? '') === $type) {
                return true;
            }
        }

        return false;
    }
}
