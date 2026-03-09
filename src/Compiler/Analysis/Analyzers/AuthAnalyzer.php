<?php
declare(strict_types=1);

namespace Foundry\Compiler\Analysis\Analyzers;

use Foundry\Compiler\Analysis\AnalyzerContext;
use Foundry\Compiler\Analysis\GraphAnalyzer;
use Foundry\Compiler\ApplicationGraph;
use Foundry\Compiler\Diagnostics\DiagnosticBag;

final class AuthAnalyzer implements GraphAnalyzer
{
    public function id(): string
    {
        return 'auth_coverage';
    }

    public function description(): string
    {
        return 'Detects missing auth guards and permission grant gaps.';
    }

    /**
     * @return array<string,mixed>
     */
    public function analyze(ApplicationGraph $graph, AnalyzerContext $context, DiagnosticBag $diagnostics): array
    {
        $unguardedRoutes = [];
        $permissionGaps = [];

        foreach ($graph->nodesByType('route') as $routeNode) {
            if (!$context->includesNode($routeNode)) {
                continue;
            }

            $payload = $routeNode->payload();
            $signature = (string) ($payload['signature'] ?? $routeNode->id());
            $features = array_values(array_map('strval', (array) ($payload['features'] ?? [])));
            sort($features);

            foreach ($features as $feature) {
                if (!$context->includesFeature($feature)) {
                    continue;
                }

                $authNode = $graph->node('auth:' . $feature);
                $authPayload = $authNode?->payload() ?? [];
                $required = (bool) ($authPayload['required'] ?? false);
                $public = (bool) ($authPayload['public'] ?? false);

                if ($required || $public) {
                    continue;
                }

                $unguardedRoutes[] = [
                    'route' => $signature,
                    'feature' => $feature,
                ];

                $diagnostics->warning(
                    code: 'FDY9002_ROUTE_AUTH_MISSING',
                    category: 'auth',
                    message: sprintf('Route %s has no authentication guard.', $signature),
                    nodeId: $routeNode->id(),
                    relatedNodes: ['feature:' . $feature],
                    suggestedFix: 'Set auth.required: true or auth.public: true in feature.yaml.',
                    pass: 'doctor.' . $this->id(),
                );
            }
        }

        foreach ($graph->nodesByType('feature') as $featureNode) {
            $payload = $featureNode->payload();
            $feature = (string) ($payload['feature'] ?? '');
            if ($feature === '' || !$context->includesFeature($feature)) {
                continue;
            }

            $auth = is_array($payload['auth'] ?? null) ? $payload['auth'] : [];
            if (!(bool) ($auth['required'] ?? false)) {
                continue;
            }

            $permissions = array_values(array_filter(array_map('strval', (array) ($auth['permissions'] ?? []))));
            if ($permissions === []) {
                continue;
            }

            $rules = is_array($payload['permissions']['rules'] ?? null) ? $payload['permissions']['rules'] : [];
            $granted = $this->flattenRulePermissions($rules);
            sort($granted);

            $missing = array_values(array_diff($permissions, $granted));
            sort($missing);
            if ($missing === []) {
                continue;
            }

            $permissionGaps[] = [
                'feature' => $feature,
                'missing_permissions' => $missing,
            ];

            $diagnostics->warning(
                code: 'FDY9003_PERMISSION_WITHOUT_ROLE_GRANT',
                category: 'permissions',
                message: sprintf(
                    'Feature %s requires permissions without role grants: %s.',
                    $feature,
                    implode(', ', $missing),
                ),
                nodeId: $featureNode->id(),
                suggestedFix: 'Add permission grants under permissions.rules in permissions.yaml.',
                pass: 'doctor.' . $this->id(),
            );
        }

        usort(
            $unguardedRoutes,
            static fn (array $a, array $b): int => strcmp(($a['route'] . ':' . $a['feature']), ($b['route'] . ':' . $b['feature'])),
        );
        usort(
            $permissionGaps,
            static fn (array $a, array $b): int => strcmp((string) ($a['feature'] ?? ''), (string) ($b['feature'] ?? '')),
        );

        return [
            'unguarded_routes' => $unguardedRoutes,
            'permission_gaps' => $permissionGaps,
        ];
    }

    /**
     * @param array<string,mixed> $rules
     * @return array<int,string>
     */
    private function flattenRulePermissions(array $rules): array
    {
        $granted = [];

        foreach ($rules as $value) {
            if (!is_array($value)) {
                continue;
            }

            foreach ($value as $permission) {
                $name = (string) $permission;
                if ($name === '') {
                    continue;
                }
                $granted[] = $name;
            }
        }

        $granted = array_values(array_unique($granted));
        sort($granted);

        return $granted;
    }
}

