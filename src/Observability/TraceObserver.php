<?php

declare(strict_types=1);

namespace Foundry\Observability;

final class TraceObserver
{
    /**
     * @param array<int,array<string,mixed>> $executionPaths
     * @return array<string,mixed>
     */
    public function observe(array $executionPaths, string $sourceHash, ?string $feature = null, ?string $routeSignature = null): array
    {
        $paths = array_map(function (array $row): array {
            $stages = array_values(array_map('strval', (array) ($row['pipeline_stages'] ?? [])));
            $guards = array_values(array_filter((array) ($row['guards'] ?? []), 'is_array'));
            $interceptors = array_values(array_filter((array) ($row['interceptors'] ?? []), 'is_array'));

            $lifecycle = [[
                'event' => 'request_start',
                'component' => 'http',
                'stage' => 'request_received',
                'guards' => [],
                'interceptors' => [],
            ]];

            foreach ($stages as $stage) {
                $stageGuards = array_values(array_map(
                    static fn(array $guard): string => (string) ($guard['id'] ?? ''),
                    array_values(array_filter(
                        $guards,
                        static fn(array $guard): bool => (string) ($guard['stage'] ?? '') === $stage,
                    )),
                ));
                $stageInterceptors = array_values(array_map(
                    static fn(array $interceptor): string => (string) ($interceptor['id'] ?? ''),
                    array_values(array_filter(
                        $interceptors,
                        static fn(array $interceptor): bool => (string) ($interceptor['stage'] ?? '') === $stage,
                    )),
                ));

                $lifecycle[] = [
                    'event' => 'pipeline_stage',
                    'component' => 'pipeline',
                    'stage' => $stage,
                    'guards' => $stageGuards,
                    'interceptors' => $stageInterceptors,
                ];
            }

            $lifecycle[] = [
                'event' => 'response_emit',
                'component' => 'http',
                'stage' => 'response_send',
                'guards' => [],
                'interceptors' => [],
            ];

            return [
                'feature' => $row['feature'] ?? '',
                'execution_plan' => $row['execution_plan'] ?? '',
                'route_signature' => $row['route_signature'] ?? null,
                'request_lifecycle' => $lifecycle,
                'pipeline_stages' => $stages,
                'guards' => $guards,
                'interceptors' => $interceptors,
                'graph_mapping' => $row['graph_mapping'] ?? [],
            ];
        }, $executionPaths);

        $guardCount = 0;
        $interceptorCount = 0;
        foreach ($paths as $path) {
            $guardCount += count((array) ($path['guards'] ?? []));
            $interceptorCount += count((array) ($path['interceptors'] ?? []));
        }

        return [
            'source_hash' => $sourceHash,
            'target' => [
                'feature' => $feature,
                'route_signature' => $routeSignature,
            ],
            'summary' => [
                'execution_paths' => count($paths),
                'guards' => $guardCount,
                'interceptors' => $interceptorCount,
            ],
            'execution_paths' => $paths,
        ];
    }
}
