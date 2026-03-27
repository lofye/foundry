<?php

declare(strict_types=1);

namespace Foundry\Compiler\Passes;

use Foundry\Compiler\CompilationState;
use Foundry\Compiler\CompilerPass;
use Foundry\Compiler\IR\FeatureNode;
use Foundry\Support\Str;

final class NormalizePass implements CompilerPass
{
    public function name(): string
    {
        return 'normalize';
    }

    public function run(CompilationState $state): void
    {
        foreach ($state->plan->selectedFeatures as $feature) {
            $state->graph->removeFeature($feature);
        }

        foreach ($state->discoveredFeatures as $feature => $discovered) {
            if ((bool) ($discovered['removed'] ?? false)) {
                continue;
            }

            $manifest = is_array($discovered['manifest'] ?? null) ? $discovered['manifest'] : [];
            $manifestFeature = (string) ($manifest['feature'] ?? $feature);
            if ($manifestFeature !== $feature) {
                $state->diagnostics->error(
                    code: 'FDY0201_FEATURE_NAME_MISMATCH',
                    category: 'normalization',
                    message: 'Manifest feature name does not match folder name.',
                    nodeId: 'feature:' . $feature,
                    sourcePath: (string) ($discovered['manifest_path'] ?? ''),
                    relatedNodes: ['feature:' . $manifestFeature],
                    suggestedFix: 'Set feature: ' . $feature . ' in feature.yaml.',
                    pass: $this->name(),
                );
            }

            $kind = (string) ($manifest['kind'] ?? 'http');
            $route = is_array($manifest['route'] ?? null) ? $manifest['route'] : null;
            if ($route !== null) {
                $route = [
                    'method' => strtoupper((string) ($route['method'] ?? 'GET')),
                    'path' => (string) ($route['path'] ?? '/'),
                ];
            }

            $auth = is_array($manifest['auth'] ?? null) ? $manifest['auth'] : [];
            $auth['required'] = (bool) ($auth['required'] ?? false);
            $auth['public'] = (bool) ($auth['public'] ?? false);
            $auth['strategies'] = array_values(array_unique(array_map('strval', (array) ($auth['strategies'] ?? []))));
            sort($auth['strategies']);
            $auth['permissions'] = array_values(array_unique(array_map('strval', (array) ($auth['permissions'] ?? []))));
            sort($auth['permissions']);

            $database = is_array($manifest['database'] ?? null) ? $manifest['database'] : [];
            $database['reads'] = $this->sortedStrings((array) ($database['reads'] ?? []));
            $database['writes'] = $this->sortedStrings((array) ($database['writes'] ?? []));
            $database['queries'] = $this->sortedStrings((array) ($database['queries'] ?? []));
            $database['transactions'] = (string) ($database['transactions'] ?? 'required');

            $cacheManifest = is_array($manifest['cache'] ?? null) ? $manifest['cache'] : [];
            $cacheManifest['reads'] = $this->sortedStrings((array) ($cacheManifest['reads'] ?? []));
            $cacheManifest['writes'] = $this->sortedStrings((array) ($cacheManifest['writes'] ?? []));
            $cacheManifest['invalidate'] = $this->sortedStrings((array) ($cacheManifest['invalidate'] ?? []));

            $eventsManifest = is_array($manifest['events'] ?? null) ? $manifest['events'] : [];
            $eventsEmit = $this->sortedStrings((array) ($eventsManifest['emit'] ?? []));
            $eventsSubscribe = $this->sortedStrings((array) ($eventsManifest['subscribe'] ?? []));

            $jobsManifest = is_array($manifest['jobs'] ?? null) ? $manifest['jobs'] : [];
            $jobsDispatch = $this->sortedStrings((array) ($jobsManifest['dispatch'] ?? []));

            $rateLimit = is_array($manifest['rate_limit'] ?? null) ? $manifest['rate_limit'] : [];
            if ($rateLimit !== []) {
                ksort($rateLimit);
            }

            $tests = is_array($manifest['tests'] ?? null) ? $manifest['tests'] : [];
            $tests['required'] = $this->sortedStrings((array) ($tests['required'] ?? []));

            $llm = is_array($manifest['llm'] ?? null) ? $manifest['llm'] : [];
            if (isset($llm['risk']) && !isset($llm['risk_level'])) {
                $llm['risk_level'] = (string) $llm['risk'];
            }

            $csrf = is_array($manifest['csrf'] ?? null) ? $manifest['csrf'] : [];
            if ($csrf !== []) {
                $csrf['required'] = (bool) ($csrf['required'] ?? false);
            }

            $resourceMeta = is_array($manifest['resource'] ?? null) ? $manifest['resource'] : [];
            if ($resourceMeta !== []) {
                ksort($resourceMeta);
            }

            $listingMeta = is_array($manifest['listing'] ?? null) ? $manifest['listing'] : [];
            if ($listingMeta !== []) {
                ksort($listingMeta);
            }

            $uploadsMeta = is_array($manifest['uploads'] ?? null) ? $manifest['uploads'] : [];
            if ($uploadsMeta !== []) {
                ksort($uploadsMeta);
            }

            $uiMeta = is_array($manifest['ui'] ?? null) ? $manifest['ui'] : [];
            if ($uiMeta !== []) {
                ksort($uiMeta);
            }

            $permissionsYaml = is_array($discovered['permissions'] ?? null) ? $discovered['permissions'] : [];
            $declaredPermissions = $this->sortedStrings((array) ($permissionsYaml['permissions'] ?? []));

            $eventsYaml = is_array($discovered['events'] ?? null) ? $discovered['events'] : [];
            $emitDefinitions = [];
            foreach ((array) ($eventsYaml['emit'] ?? []) as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $name = (string) ($row['name'] ?? '');
                if ($name === '') {
                    continue;
                }

                $emitDefinitions[$name] = is_array($row['schema'] ?? null) ? $row['schema'] : [];
            }
            ksort($emitDefinitions);

            $eventsSubscribe = array_values(array_unique(array_merge(
                $eventsSubscribe,
                $this->sortedStrings((array) ($eventsYaml['subscribe'] ?? [])),
            )));
            sort($eventsSubscribe);

            $jobsYaml = is_array($discovered['jobs'] ?? null) ? $discovered['jobs'] : [];
            $jobDefinitions = [];
            foreach ((array) ($jobsYaml['dispatch'] ?? []) as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $name = (string) ($row['name'] ?? '');
                if ($name === '') {
                    continue;
                }

                $jobDefinitions[$name] = [
                    'input_schema' => is_array($row['input_schema'] ?? null) ? $row['input_schema'] : [],
                    'queue' => (string) ($row['queue'] ?? 'default'),
                    'retry' => is_array($row['retry'] ?? null) ? $row['retry'] : [],
                    'timeout_seconds' => (int) ($row['timeout_seconds'] ?? 60),
                    'idempotency_key' => isset($row['idempotency_key']) ? (string) $row['idempotency_key'] : null,
                ];
            }
            ksort($jobDefinitions);

            $cacheYaml = is_array($discovered['cache'] ?? null) ? $discovered['cache'] : [];
            $cacheEntries = [];
            foreach ((array) ($cacheYaml['entries'] ?? []) as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $key = (string) ($row['key'] ?? '');
                if ($key === '') {
                    continue;
                }

                $cacheEntries[$key] = [
                    'kind' => (string) ($row['kind'] ?? 'computed'),
                    'ttl_seconds' => (int) ($row['ttl_seconds'] ?? 300),
                    'invalidated_by' => $this->sortedStrings((array) ($row['invalidated_by'] ?? [])),
                ];
            }
            ksort($cacheEntries);

            $schedulerYaml = is_array($discovered['scheduler'] ?? null) ? $discovered['scheduler'] : [];
            $schedulerTasks = [];
            foreach ((array) ($schedulerYaml['tasks'] ?? []) as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $name = (string) ($row['name'] ?? '');
                if ($name === '') {
                    continue;
                }

                $schedulerTasks[] = [
                    'name' => $name,
                    'cron' => (string) ($row['cron'] ?? ''),
                    'job' => (string) ($row['job'] ?? ''),
                ];
            }
            usort($schedulerTasks, static fn(array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

            $webhooksYaml = is_array($discovered['webhooks'] ?? null) ? $discovered['webhooks'] : [];
            $webhooks = [
                'incoming' => $this->normalizeHooks((array) ($webhooksYaml['incoming'] ?? []), 'incoming'),
                'outgoing' => $this->normalizeHooks((array) ($webhooksYaml['outgoing'] ?? []), 'outgoing'),
            ];

            $queries = [];
            foreach ((array) ($discovered['queries'] ?? []) as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $name = (string) ($row['name'] ?? '');
                if ($name === '') {
                    continue;
                }

                $queries[] = [
                    'name' => $name,
                    'sql' => (string) ($row['sql'] ?? ''),
                    'placeholders' => $this->sortedStrings((array) ($row['placeholders'] ?? [])),
                ];
            }
            usort($queries, static fn(array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

            $inputSchemaPath = (string) ($discovered['input_schema_path'] ?? 'app/features/' . $feature . '/input.schema.json');
            $outputSchemaPath = (string) ($discovered['output_schema_path'] ?? 'app/features/' . $feature . '/output.schema.json');

            $payload = [
                'feature' => $feature,
                'kind' => $kind,
                'description' => (string) ($manifest['description'] ?? ''),
                'manifest_version' => (int) ($manifest['version'] ?? 1),
                'manifest_path' => (string) ($discovered['manifest_path'] ?? ''),
                'base_path' => (string) ($discovered['base_path'] ?? ('app/features/' . $feature)),
                'route' => $route,
                'input_schema_path' => $inputSchemaPath,
                'output_schema_path' => $outputSchemaPath,
                'input_schema' => is_array($discovered['input_schema'] ?? null) ? $discovered['input_schema'] : null,
                'output_schema' => is_array($discovered['output_schema'] ?? null) ? $discovered['output_schema'] : null,
                'auth' => $auth,
                'database' => $database,
                'cache' => [
                    'reads' => $cacheManifest['reads'],
                    'writes' => $cacheManifest['writes'],
                    'invalidate' => $cacheManifest['invalidate'],
                    'entries' => $cacheEntries,
                ],
                'events' => [
                    'emit' => $eventsEmit,
                    'emit_definitions' => $emitDefinitions,
                    'subscribe' => $eventsSubscribe,
                ],
                'jobs' => [
                    'dispatch' => $jobsDispatch,
                    'definitions' => $jobDefinitions,
                ],
                'tests' => [
                    'required' => $tests['required'],
                    'files' => array_values(array_map('strval', (array) ($discovered['test_files'] ?? []))),
                ],
                'llm' => $llm,
                'permissions' => [
                    'declared' => $declaredPermissions,
                    'rules' => is_array($permissionsYaml['rules'] ?? null) ? $permissionsYaml['rules'] : [],
                ],
                'queries' => $queries,
                'scheduler' => ['tasks' => $schedulerTasks],
                'webhooks' => $webhooks,
                'context_manifest' => is_array($discovered['context_manifest'] ?? null) ? $discovered['context_manifest'] : null,
                'rate_limit' => $rateLimit,
                'csrf' => $csrf,
                'resource' => $resourceMeta,
                'listing' => $listingMeta,
                'uploads' => $uploadsMeta,
                'ui' => $uiMeta,
                'action_class' => 'App\\Features\\' . Str::studly($feature) . '\\Action',
                'source_files' => array_values(array_map('strval', (array) ($discovered['source_files'] ?? []))),
            ];

            $state->graph->addNode(new FeatureNode(
                id: 'feature:' . $feature,
                sourcePath: (string) ($discovered['manifest_path'] ?? ''),
                payload: $payload,
                sourceRegion: ['line_start' => 1, 'line_end' => null],
                graphCompatibility: [1],
            ));
        }
    }

    /**
     * @param array<int,mixed> $values
     * @return array<int,string>
     */
    private function sortedStrings(array $values): array
    {
        $values = array_values(array_map('strval', $values));
        $values = array_values(array_filter($values, static fn(string $value): bool => $value !== ''));
        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }

    /**
     * @param array<int,mixed> $rows
     * @return array<int,array<string,mixed>>
     */
    private function normalizeHooks(array $rows, string $direction): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $name = (string) ($row['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $normalized[] = [
                'name' => $name,
                'direction' => $direction,
                'path' => (string) ($row['path'] ?? ''),
                'method' => strtoupper((string) ($row['method'] ?? 'POST')),
                'event' => (string) ($row['event'] ?? ''),
                'schema' => is_array($row['schema'] ?? null) ? $row['schema'] : [],
            ];
        }

        usort($normalized, static fn(array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        return $normalized;
    }
}
