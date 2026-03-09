<?php
declare(strict_types=1);

namespace Foundry\Compiler\Passes;

use Foundry\Compiler\CompilationState;
use Foundry\Compiler\CompilerPass;

final class EnrichPass implements CompilerPass
{
    public function name(): string
    {
        return 'enrich';
    }

    public function run(CompilationState $state): void
    {
        $featureSummaries = [];
        $authMatrix = [];
        $routeSummaries = [];

        foreach ($state->graph->nodesByType('feature') as $featureNode) {
            $payload = $featureNode->payload();
            $feature = (string) ($payload['feature'] ?? '');
            if ($feature === '') {
                continue;
            }

            $auth = is_array($payload['auth'] ?? null) ? $payload['auth'] : [];
            foreach ((array) ($auth['permissions'] ?? []) as $permission) {
                $name = (string) $permission;
                if ($name === '') {
                    continue;
                }

                $authMatrix[$name] ??= [];
                $authMatrix[$name][] = $feature;
            }

            $route = is_array($payload['route'] ?? null) ? $payload['route'] : null;
            if ($route !== null) {
                $routeSummaries[] = [
                    'feature' => $feature,
                    'method' => strtoupper((string) ($route['method'] ?? 'GET')),
                    'path' => (string) ($route['path'] ?? '/'),
                ];
            }

            $featureSummaries[$feature] = [
                'feature' => $feature,
                'kind' => (string) ($payload['kind'] ?? ''),
                'route' => $route,
                'auth_required' => (bool) (($auth['required'] ?? false)),
                'permission_count' => count((array) ($auth['permissions'] ?? [])),
                'query_count' => count((array) ($payload['queries'] ?? [])),
                'event_emit_count' => count((array) ($payload['events']['emit'] ?? [])),
                'event_subscribe_count' => count((array) ($payload['events']['subscribe'] ?? [])),
                'job_dispatch_count' => count((array) ($payload['jobs']['dispatch'] ?? [])),
                'cache_invalidation_count' => count((array) ($payload['cache']['invalidate'] ?? [])),
                'required_tests' => array_values(array_map('strval', (array) ($payload['tests']['required'] ?? []))),
                'risk_hint' => $this->riskHint($payload),
            ];
        }

        ksort($featureSummaries);
        foreach ($authMatrix as &$features) {
            sort($features);
            $features = array_values(array_unique($features));
        }
        unset($features);
        ksort($authMatrix);

        usort(
            $routeSummaries,
            static fn (array $a, array $b): int => strcmp(($a['method'] . ' ' . $a['path']), ($b['method'] . ' ' . $b['path'])),
        );

        $state->analysis['feature_summaries'] = $featureSummaries;
        $state->analysis['auth_matrix'] = $authMatrix;
        $state->analysis['route_summaries'] = $routeSummaries;

        $state->graph->setMetadata([
            'feature_summaries' => $featureSummaries,
            'auth_matrix' => $authMatrix,
            'route_summaries' => $routeSummaries,
            'graph_stats' => [
                'node_counts' => $state->graph->nodeCountsByType(),
                'edge_counts' => $state->graph->edgeCountsByType(),
            ],
            'impact_hints' => [
                'changed_features' => $state->plan->changedFeatures,
                'changed_files' => $state->plan->changedFiles,
                'recommended_scope' => $state->plan->mode,
            ],
        ]);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function riskHint(array $payload): string
    {
        $risk = 'low';

        if (is_array($payload['route'] ?? null)) {
            $risk = 'medium';
        }

        if ((bool) (($payload['auth']['required'] ?? false)) || (array) ($payload['database']['writes'] ?? []) !== []) {
            $risk = 'high';
        }

        if ((array) ($payload['jobs']['dispatch'] ?? []) !== [] || (array) ($payload['events']['emit'] ?? []) !== []) {
            $risk = $risk === 'low' ? 'medium' : $risk;
        }

        return $risk;
    }
}
