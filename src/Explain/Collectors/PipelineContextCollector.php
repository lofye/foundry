<?php
declare(strict_types=1);

namespace Foundry\Explain\Collectors;

use Foundry\Explain\ExplainArtifactCatalog;
use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;
use Foundry\Explain\ExplainSupport;

final readonly class PipelineContextCollector implements ExplainContextCollectorInterface
{
    public function __construct(private ExplainArtifactCatalog $artifacts)
    {
    }

    public function supports(ExplainSubject $subject): bool
    {
        return in_array($subject->kind, ['feature', 'route', 'pipeline_stage'], true);
    }

    public function collect(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): void
    {
        $index = $this->artifacts->executionPlanIndex();
        $byFeature = is_array($index['by_feature'] ?? null) ? $index['by_feature'] : [];
        $byRoute = is_array($index['by_route'] ?? null) ? $index['by_route'] : [];
        $featureIndex = $this->artifacts->featureIndex();
        $permissionIndex = $this->artifacts->permissionIndex();
        $pipelineDefinition = $this->artifacts->pipelineIndex();

        $feature = trim((string) ($subject->metadata['feature'] ?? ''));
        $routeSignature = trim((string) ($subject->metadata['signature'] ?? ''));
        $executionPlan = null;

        if ($subject->kind === 'feature' && $feature !== '') {
            $executionPlan = is_array($byFeature[$feature] ?? null) ? $byFeature[$feature] : null;
        } elseif ($subject->kind === 'route') {
            $routeSignature = ExplainSupport::normalizeRouteSignature($routeSignature !== '' ? $routeSignature : $subject->label);
            $executionPlan = is_array($byRoute[$routeSignature] ?? null) ? $byRoute[$routeSignature] : null;
            if ($feature === '' && is_array($executionPlan)) {
                $feature = trim((string) ($executionPlan['feature'] ?? ''));
            }
        } elseif ($subject->kind === 'pipeline_stage') {
            $feature = trim((string) ($subject->metadata['feature'] ?? ''));
        }

        $guards = [];
        $guardIndex = $this->artifacts->guardIndex();
        foreach ((array) ($executionPlan['guards'] ?? []) as $guardId) {
            $guardRow = $guardIndex[(string) $guardId] ?? null;
            if (is_array($guardRow)) {
                $guards[] = $guardRow;
            }
        }

        $interceptors = [];
        $interceptorIndex = $this->artifacts->interceptorIndex();
        foreach ((array) ($executionPlan['interceptors'] ?? []) as $stage => $ids) {
            $stageRows = [];
            foreach ((array) $ids as $id) {
                $row = $interceptorIndex[(string) $id] ?? null;
                if (is_array($row)) {
                    $stageRows[] = $row;
                }
            }

            if ($stageRows !== []) {
                $interceptors[(string) $stage] = $stageRows;
            }
        }

        $jobs = [];
        $action = null;
        $permissions = [];
        $featureRow = $feature !== '' && is_array($featureIndex[$feature] ?? null) ? $featureIndex[$feature] : null;
        if (is_array($featureRow)) {
            foreach ((array) ($featureRow['jobs']['dispatch'] ?? []) as $jobName) {
                $jobs[] = [
                    'id' => 'job:' . (string) $jobName,
                    'kind' => 'job',
                    'label' => (string) $jobName,
                    'name' => (string) $jobName,
                ];
            }

            $action = [
                'id' => 'feature:' . $feature,
                'kind' => 'feature',
                'label' => $feature,
                'feature' => $feature,
            ];

            $definedPermissions = array_values(array_map(
                'strval',
                (array) (($permissionIndex[$feature]['permissions'] ?? [])),
            ));

            foreach ((array) ($featureRow['auth']['permissions'] ?? []) as $permissionName) {
                $permissionName = (string) $permissionName;
                $permissions[] = [
                    'name' => $permissionName,
                    'id' => 'permission:' . $permissionName,
                    'definition' => in_array($permissionName, $definedPermissions, true)
                        ? [
                            'feature' => $feature,
                            'permission' => $permissionName,
                        ]
                        : null,
                ];
            }
        }

        $stages = [];
        foreach ((array) ($executionPlan['stages'] ?? $pipelineDefinition['order'] ?? []) as $index => $stageName) {
            $stages[] = [
                'id' => 'pipeline_stage:' . (string) $stageName,
                'kind' => 'pipeline_stage',
                'label' => (string) $stageName,
                'name' => (string) $stageName,
                'order' => $index,
            ];
        }

        $context->setPipeline([
            'feature' => $feature !== '' ? $feature : null,
            'route_signature' => $routeSignature !== '' ? $routeSignature : ($executionPlan['route_signature'] ?? null),
            'execution_plan' => $executionPlan,
            'stages' => $stages,
            'guards' => $guards,
            'interceptors' => $interceptors,
            'action' => $action,
            'jobs' => $jobs,
            'permissions' => $permissions,
            'definition' => $pipelineDefinition,
        ]);
    }
}
