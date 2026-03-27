<?php

declare(strict_types=1);

namespace Foundry\Observability;

final class ProfileObserver
{
    /**
     * @param array<int,array<string,mixed>> $executionPaths
     * @param array<string,int> $memory
     * @return array<string,mixed>
     */
    public function observe(
        array $executionPaths,
        string $sourceHash,
        float $compileMs,
        float $mappingMs,
        array $memory,
        ?string $feature = null,
        ?string $routeSignature = null,
    ): array {
        $profiles = [];

        foreach ($executionPaths as $row) {
            $stageCount = count((array) ($row['pipeline_stages'] ?? []));
            $guardCount = count((array) ($row['guards'] ?? []));
            $interceptorCount = count((array) ($row['interceptors'] ?? []));
            $score = $stageCount + $guardCount + ($interceptorCount * 2);

            $profiles[] = [
                'feature' => $row['feature'] ?? '',
                'execution_plan' => $row['execution_plan'] ?? '',
                'route_signature' => $row['route_signature'] ?? null,
                'stage_count' => $stageCount,
                'guard_count' => $guardCount,
                'interceptor_count' => $interceptorCount,
                'hotspot_score' => $score,
                'graph_mapping' => $row['graph_mapping'] ?? [],
                'pipeline_stages' => $row['pipeline_stages'] ?? [],
                'guards' => array_values(array_map(
                    static fn(array $guard): string => (string) ($guard['id'] ?? ''),
                    array_values(array_filter((array) ($row['guards'] ?? []), 'is_array')),
                )),
                'interceptors' => array_values(array_map(
                    static fn(array $interceptor): string => (string) ($interceptor['id'] ?? ''),
                    array_values(array_filter((array) ($row['interceptors'] ?? []), 'is_array')),
                )),
            ];
        }

        usort(
            $profiles,
            static fn(array $a, array $b): int => ((int) ($b['hotspot_score'] ?? 0)) <=> ((int) ($a['hotspot_score'] ?? 0)),
        );

        return [
            'source_hash' => $sourceHash,
            'target' => [
                'feature' => $feature,
                'route_signature' => $routeSignature,
            ],
            'timings' => [
                'compile_ms' => round($compileMs, 3),
                'mapping_ms' => round($mappingMs, 3),
                'total_ms' => round($compileMs + $mappingMs, 3),
            ],
            'memory' => [
                'start_bytes' => (int) ($memory['start'] ?? 0),
                'end_bytes' => (int) ($memory['end'] ?? 0),
                'peak_bytes' => (int) ($memory['peak'] ?? 0),
            ],
            'execution_profiles' => $profiles,
            'hotspots' => array_slice($profiles, 0, 5),
        ];
    }
}
