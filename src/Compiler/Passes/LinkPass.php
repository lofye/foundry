<?php
declare(strict_types=1);

namespace Foundry\Compiler\Passes;

use Foundry\Compiler\CompilationState;
use Foundry\Compiler\CompilerPass;
use Foundry\Compiler\GraphEdge;
use Foundry\Compiler\IR\AuthNode;
use Foundry\Compiler\IR\CacheNode;
use Foundry\Compiler\IR\ContextManifestNode;
use Foundry\Compiler\IR\EventNode;
use Foundry\Compiler\IR\JobNode;
use Foundry\Compiler\IR\PermissionNode;
use Foundry\Compiler\IR\QueryNode;
use Foundry\Compiler\IR\RateLimitNode;
use Foundry\Compiler\IR\RouteNode;
use Foundry\Compiler\IR\SchemaNode;
use Foundry\Compiler\IR\SchedulerNode;
use Foundry\Compiler\IR\TestNode;
use Foundry\Compiler\IR\WebhookNode;

final class LinkPass implements CompilerPass
{
    public function name(): string
    {
        return 'link';
    }

    public function run(CompilationState $state): void
    {
        $state->graph->retainOnlyFeatureNodes();

        $routeNodes = [];
        $schemaNodes = [];
        $permissionNodes = [];
        $queryNodes = [];
        $eventNodes = [];
        $jobNodes = [];
        $cacheNodes = [];
        $schedulerNodes = [];
        $webhookNodes = [];
        $testNodes = [];
        $contextNodes = [];
        $authNodes = [];
        $rateLimitNodes = [];

        foreach ($state->graph->nodesByType('feature') as $featureNode) {
            $payload = $featureNode->payload();
            $feature = (string) ($payload['feature'] ?? '');
            if ($feature === '') {
                continue;
            }

            $featureId = 'feature:' . $feature;
            $manifestPath = (string) ($payload['manifest_path'] ?? 'app/features/' . $feature . '/feature.yaml');
            $basePath = (string) ($payload['base_path'] ?? ('app/features/' . $feature));

            $route = is_array($payload['route'] ?? null) ? $payload['route'] : null;
            if ($route !== null) {
                $method = strtoupper((string) ($route['method'] ?? 'GET'));
                $path = (string) ($route['path'] ?? '/');
                $routeId = 'route:' . $method . ':' . $path;

                $routeNodes[$routeId] ??= [
                    'id' => $routeId,
                    'source_path' => $manifestPath,
                    'payload' => [
                        'method' => $method,
                        'path' => $path,
                        'signature' => $method . ' ' . $path,
                        'features' => [],
                    ],
                ];
                $routeNodes[$routeId]['payload']['features'][] = $feature;

                $state->graph->addEdge(GraphEdge::make('feature_to_route', $featureId, $routeId));
            }

            $inputSchemaPath = (string) ($payload['input_schema_path'] ?? '');
            if ($inputSchemaPath !== '') {
                $inputSchemaId = 'schema:' . $inputSchemaPath;
                $schemaNodes[$inputSchemaId] ??= [
                    'id' => $inputSchemaId,
                    'source_path' => $inputSchemaPath,
                    'payload' => [
                        'path' => $inputSchemaPath,
                        'role' => 'input',
                        'feature' => $feature,
                        'document' => is_array($payload['input_schema'] ?? null) ? $payload['input_schema'] : null,
                    ],
                ];
                $state->graph->addEdge(GraphEdge::make('feature_to_input_schema', $featureId, $inputSchemaId));
            }

            $outputSchemaPath = (string) ($payload['output_schema_path'] ?? '');
            if ($outputSchemaPath !== '') {
                $outputSchemaId = 'schema:' . $outputSchemaPath;
                $schemaNodes[$outputSchemaId] ??= [
                    'id' => $outputSchemaId,
                    'source_path' => $outputSchemaPath,
                    'payload' => [
                        'path' => $outputSchemaPath,
                        'role' => 'output',
                        'feature' => $feature,
                        'document' => is_array($payload['output_schema'] ?? null) ? $payload['output_schema'] : null,
                    ],
                ];
                $state->graph->addEdge(GraphEdge::make('feature_to_output_schema', $featureId, $outputSchemaId));
            }

            $auth = is_array($payload['auth'] ?? null) ? $payload['auth'] : [];
            $declaredPermissions = array_values(array_map('strval', (array) ($payload['permissions']['declared'] ?? [])));
            $referencedPermissions = array_values(array_map('strval', (array) ($auth['permissions'] ?? [])));

            foreach (array_values(array_unique(array_merge($declaredPermissions, $referencedPermissions))) as $permission) {
                if ($permission === '') {
                    continue;
                }

                $permissionId = 'permission:' . $permission;
                $permissionNodes[$permissionId] ??= [
                    'id' => $permissionId,
                    'source_path' => $basePath . '/permissions.yaml',
                    'payload' => [
                        'name' => $permission,
                        'features' => [],
                        'declared_by' => [],
                        'referenced_by' => [],
                    ],
                ];
                $permissionNodes[$permissionId]['payload']['features'][] = $feature;
                if (in_array($permission, $declaredPermissions, true)) {
                    $permissionNodes[$permissionId]['payload']['declared_by'][] = $feature;
                }
                if (in_array($permission, $referencedPermissions, true)) {
                    $permissionNodes[$permissionId]['payload']['referenced_by'][] = $feature;
                    $state->graph->addEdge(GraphEdge::make('feature_to_permission', $featureId, $permissionId));
                }
            }

            $queryDefinitions = [];
            foreach ((array) ($payload['queries'] ?? []) as $query) {
                if (!is_array($query)) {
                    continue;
                }

                $name = (string) ($query['name'] ?? '');
                if ($name === '') {
                    continue;
                }

                $queryDefinitions[$name] = $query;
            }
            $queryReferences = array_values(array_map('strval', (array) ($payload['database']['queries'] ?? [])));

            foreach (array_values(array_unique(array_merge(array_keys($queryDefinitions), $queryReferences))) as $queryName) {
                if ($queryName === '') {
                    continue;
                }

                $queryId = 'query:' . $feature . ':' . $queryName;
                $definition = is_array($queryDefinitions[$queryName] ?? null) ? $queryDefinitions[$queryName] : [];

                $queryNodes[$queryId] = [
                    'id' => $queryId,
                    'source_path' => $basePath . '/queries.sql',
                    'payload' => [
                        'feature' => $feature,
                        'name' => $queryName,
                        'sql' => (string) ($definition['sql'] ?? ''),
                        'placeholders' => array_values(array_map('strval', (array) ($definition['placeholders'] ?? []))),
                        'defined' => isset($queryDefinitions[$queryName]),
                        'referenced' => in_array($queryName, $queryReferences, true),
                    ],
                ];

                $state->graph->addEdge(GraphEdge::make('feature_to_query', $featureId, $queryId));
            }

            $events = is_array($payload['events'] ?? null) ? $payload['events'] : [];
            $emitDefinitions = is_array($events['emit_definitions'] ?? null) ? $events['emit_definitions'] : [];
            $emitNames = array_values(array_unique(array_merge(
                array_keys($emitDefinitions),
                array_values(array_map('strval', (array) ($events['emit'] ?? []))),
            )));
            sort($emitNames);

            foreach ($emitNames as $eventName) {
                if ($eventName === '') {
                    continue;
                }

                $eventId = 'event:' . $eventName;
                $eventNodes[$eventId] ??= [
                    'id' => $eventId,
                    'source_path' => $basePath . '/events.yaml',
                    'payload' => [
                        'name' => $eventName,
                        'emitters' => [],
                        'subscribers' => [],
                        'schemas' => [],
                    ],
                ];

                $eventNodes[$eventId]['payload']['emitters'][] = $feature;
                $eventNodes[$eventId]['payload']['schemas'][$feature] = is_array($emitDefinitions[$eventName] ?? null)
                    ? $emitDefinitions[$eventName]
                    : [];

                $state->graph->addEdge(GraphEdge::make('feature_to_event_emit', $featureId, $eventId));
            }

            foreach ((array) ($events['subscribe'] ?? []) as $row) {
                $eventName = (string) $row;
                if ($eventName === '') {
                    continue;
                }

                $eventId = 'event:' . $eventName;
                $eventNodes[$eventId] ??= [
                    'id' => $eventId,
                    'source_path' => $basePath . '/events.yaml',
                    'payload' => [
                        'name' => $eventName,
                        'emitters' => [],
                        'subscribers' => [],
                        'schemas' => [],
                    ],
                ];
                $eventNodes[$eventId]['payload']['subscribers'][] = $feature;

                $state->graph->addEdge(GraphEdge::make('feature_to_event_subscribe', $featureId, $eventId));
            }

            $jobs = is_array($payload['jobs'] ?? null) ? $payload['jobs'] : [];
            $dispatch = array_values(array_map('strval', (array) ($jobs['dispatch'] ?? [])));
            $definitions = is_array($jobs['definitions'] ?? null) ? $jobs['definitions'] : [];
            $jobNames = array_values(array_unique(array_merge($dispatch, array_keys($definitions))));
            sort($jobNames);

            foreach ($jobNames as $jobName) {
                if ($jobName === '') {
                    continue;
                }

                $jobId = 'job:' . $jobName;
                $jobNodes[$jobId] ??= [
                    'id' => $jobId,
                    'source_path' => $basePath . '/jobs.yaml',
                    'payload' => [
                        'name' => $jobName,
                        'features' => [],
                        'definitions' => [],
                    ],
                ];
                $jobNodes[$jobId]['payload']['features'][] = $feature;
                if (isset($definitions[$jobName]) && is_array($definitions[$jobName])) {
                    $jobNodes[$jobId]['payload']['definitions'][$feature] = $definitions[$jobName];
                }

                if (in_array($jobName, $dispatch, true)) {
                    $state->graph->addEdge(GraphEdge::make('feature_to_job_dispatch', $featureId, $jobId));
                }
            }

            $cache = is_array($payload['cache'] ?? null) ? $payload['cache'] : [];
            $invalidations = array_values(array_map('strval', (array) ($cache['invalidate'] ?? [])));
            $cacheEntries = is_array($cache['entries'] ?? null) ? $cache['entries'] : [];
            $cacheKeys = array_values(array_unique(array_merge($invalidations, array_keys($cacheEntries))));
            sort($cacheKeys);

            foreach ($cacheKeys as $cacheKey) {
                if ($cacheKey === '') {
                    continue;
                }

                $cacheId = 'cache:' . $cacheKey;
                $cacheNodes[$cacheId] ??= [
                    'id' => $cacheId,
                    'source_path' => $basePath . '/cache.yaml',
                    'payload' => [
                        'key' => $cacheKey,
                        'features' => [],
                        'entries' => [],
                        'invalidated_by' => [],
                    ],
                ];
                $cacheNodes[$cacheId]['payload']['features'][] = $feature;
                if (isset($cacheEntries[$cacheKey]) && is_array($cacheEntries[$cacheKey])) {
                    $cacheNodes[$cacheId]['payload']['entries'][$feature] = $cacheEntries[$cacheKey];
                }
                if (in_array($cacheKey, $invalidations, true)) {
                    $cacheNodes[$cacheId]['payload']['invalidated_by'][] = $feature;
                    $state->graph->addEdge(GraphEdge::make('feature_to_cache_invalidation', $featureId, $cacheId));
                }
            }

            foreach ((array) ($payload['scheduler']['tasks'] ?? []) as $task) {
                if (!is_array($task)) {
                    continue;
                }

                $name = (string) ($task['name'] ?? '');
                if ($name === '') {
                    continue;
                }

                $schedulerId = 'scheduler:' . $feature . ':' . $name;
                $schedulerNodes[$schedulerId] = [
                    'id' => $schedulerId,
                    'source_path' => $basePath . '/scheduler.yaml',
                    'payload' => [
                        'feature' => $feature,
                        'name' => $name,
                        'cron' => (string) ($task['cron'] ?? ''),
                        'job' => (string) ($task['job'] ?? ''),
                    ],
                ];
                $state->graph->addEdge(GraphEdge::make('feature_to_scheduler_task', $featureId, $schedulerId));
            }

            foreach (['incoming', 'outgoing'] as $direction) {
                foreach ((array) ($payload['webhooks'][$direction] ?? []) as $hook) {
                    if (!is_array($hook)) {
                        continue;
                    }

                    $name = (string) ($hook['name'] ?? '');
                    if ($name === '') {
                        continue;
                    }

                    $webhookId = 'webhook:' . $direction . ':' . $feature . ':' . $name;
                    $webhookNodes[$webhookId] = [
                        'id' => $webhookId,
                        'source_path' => $basePath . '/webhooks.yaml',
                        'payload' => array_merge(
                            ['feature' => $feature, 'direction' => $direction, 'name' => $name],
                            $hook,
                        ),
                    ];
                    $state->graph->addEdge(GraphEdge::make('feature_to_webhook', $featureId, $webhookId));
                }
            }

            foreach ((array) ($payload['tests']['required'] ?? []) as $testKind) {
                $kind = (string) $testKind;
                if ($kind === '') {
                    continue;
                }

                $testId = 'test:' . $feature . ':' . $kind;
                $testNodes[$testId] = [
                    'id' => $testId,
                    'source_path' => $manifestPath,
                    'payload' => [
                        'feature' => $feature,
                        'kind' => $kind,
                        'name' => $feature . '_' . $kind . '_test',
                    ],
                ];

                $state->graph->addEdge(GraphEdge::make('feature_to_test', $featureId, $testId));
            }

            $contextId = 'context_manifest:' . $feature;
            $contextNodes[$contextId] = [
                'id' => $contextId,
                'source_path' => $basePath . '/context.manifest.json',
                'payload' => [
                    'feature' => $feature,
                    'document' => is_array($payload['context_manifest'] ?? null) ? $payload['context_manifest'] : null,
                ],
            ];
            $state->graph->addEdge(GraphEdge::make('feature_to_context_manifest', $featureId, $contextId));

            $authId = 'auth:' . $feature;
            $authNodes[$authId] = [
                'id' => $authId,
                'source_path' => $manifestPath,
                'payload' => array_merge(['feature' => $feature], $auth),
            ];
            $state->graph->addEdge(GraphEdge::make('feature_to_auth_config', $featureId, $authId));

            $rateLimit = is_array($payload['rate_limit'] ?? null) ? $payload['rate_limit'] : [];
            $rateLimitId = 'rate_limit:' . $feature;
            $rateLimitNodes[$rateLimitId] = [
                'id' => $rateLimitId,
                'source_path' => $manifestPath,
                'payload' => array_merge(['feature' => $feature], $rateLimit),
            ];
            $state->graph->addEdge(GraphEdge::make('feature_to_rate_limit', $featureId, $rateLimitId));
        }

        foreach ($routeNodes as $row) {
            $features = array_values(array_unique(array_map('strval', (array) ($row['payload']['features'] ?? []))));
            sort($features);
            $row['payload']['features'] = $features;
            $state->graph->addNode(new RouteNode($row['id'], $row['source_path'], $row['payload'], ['line_start' => 1, 'line_end' => null], [1]));
        }

        foreach ($schemaNodes as $row) {
            $state->graph->addNode(new SchemaNode($row['id'], $row['source_path'], $row['payload'], ['line_start' => 1, 'line_end' => null], [1]));
        }

        foreach ($permissionNodes as $row) {
            $row['payload']['features'] = $this->sortedUniqueStrings((array) ($row['payload']['features'] ?? []));
            $row['payload']['declared_by'] = $this->sortedUniqueStrings((array) ($row['payload']['declared_by'] ?? []));
            $row['payload']['referenced_by'] = $this->sortedUniqueStrings((array) ($row['payload']['referenced_by'] ?? []));
            $state->graph->addNode(new PermissionNode($row['id'], $row['source_path'], $row['payload'], ['line_start' => 1, 'line_end' => null], [1]));
        }

        foreach ($queryNodes as $row) {
            $state->graph->addNode(new QueryNode($row['id'], $row['source_path'], $row['payload'], ['line_start' => 1, 'line_end' => null], [1]));
        }

        foreach ($eventNodes as $row) {
            $row['payload']['emitters'] = $this->sortedUniqueStrings((array) ($row['payload']['emitters'] ?? []));
            $row['payload']['subscribers'] = $this->sortedUniqueStrings((array) ($row['payload']['subscribers'] ?? []));
            $state->graph->addNode(new EventNode($row['id'], $row['source_path'], $row['payload'], ['line_start' => 1, 'line_end' => null], [1]));
        }

        foreach ($eventNodes as $row) {
            $eventName = (string) ($row['payload']['name'] ?? '');
            $emitters = array_values(array_map('strval', (array) ($row['payload']['emitters'] ?? [])));
            $subscribers = array_values(array_map('strval', (array) ($row['payload']['subscribers'] ?? [])));

            foreach ($emitters as $emitter) {
                foreach ($subscribers as $subscriber) {
                    $state->graph->addEdge(GraphEdge::make(
                        'event_publisher_to_subscriber',
                        'feature:' . $emitter,
                        'feature:' . $subscriber,
                        ['event' => $eventName],
                    ));
                }
            }
        }

        foreach ($jobNodes as $row) {
            $row['payload']['features'] = $this->sortedUniqueStrings((array) ($row['payload']['features'] ?? []));
            $state->graph->addNode(new JobNode($row['id'], $row['source_path'], $row['payload'], ['line_start' => 1, 'line_end' => null], [1]));
        }

        foreach ($cacheNodes as $row) {
            $row['payload']['features'] = $this->sortedUniqueStrings((array) ($row['payload']['features'] ?? []));
            $row['payload']['invalidated_by'] = $this->sortedUniqueStrings((array) ($row['payload']['invalidated_by'] ?? []));
            $state->graph->addNode(new CacheNode($row['id'], $row['source_path'], $row['payload'], ['line_start' => 1, 'line_end' => null], [1]));
        }

        foreach ($schedulerNodes as $row) {
            $state->graph->addNode(new SchedulerNode($row['id'], $row['source_path'], $row['payload'], ['line_start' => 1, 'line_end' => null], [1]));
        }

        foreach ($webhookNodes as $row) {
            $state->graph->addNode(new WebhookNode($row['id'], $row['source_path'], $row['payload'], ['line_start' => 1, 'line_end' => null], [1]));
        }

        foreach ($testNodes as $row) {
            $state->graph->addNode(new TestNode($row['id'], $row['source_path'], $row['payload'], ['line_start' => 1, 'line_end' => null], [1]));
        }

        foreach ($contextNodes as $row) {
            $state->graph->addNode(new ContextManifestNode($row['id'], $row['source_path'], $row['payload'], ['line_start' => 1, 'line_end' => null], [1]));
        }

        foreach ($authNodes as $row) {
            $state->graph->addNode(new AuthNode($row['id'], $row['source_path'], $row['payload'], ['line_start' => 1, 'line_end' => null], [1]));
        }

        foreach ($rateLimitNodes as $row) {
            $state->graph->addNode(new RateLimitNode($row['id'], $row['source_path'], $row['payload'], ['line_start' => 1, 'line_end' => null], [1]));
        }
    }

    /**
     * @param array<int,mixed> $values
     * @return array<int,string>
     */
    private function sortedUniqueStrings(array $values): array
    {
        $values = array_values(array_unique(array_map('strval', $values)));
        $values = array_values(array_filter($values, static fn (string $value): bool => $value !== ''));
        sort($values);

        return $values;
    }
}
