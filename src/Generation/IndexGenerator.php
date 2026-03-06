<?php
declare(strict_types=1);

namespace Foundry\Generation;

use Foundry\Support\Paths;
use Foundry\Support\Str;
use Foundry\Support\Yaml;

final class IndexGenerator
{
    public function __construct(private readonly Paths $paths)
    {
    }

    /**
     * @return array<int,string>
     */
    public function generate(): array
    {
        $featuresRoot = $this->paths->features();
        if (!is_dir($featuresRoot)) {
            mkdir($featuresRoot, 0777, true);
        }

        $featureIndex = [];
        $routes = [];
        $schemaIndex = [];
        $permissionIndex = [];
        $eventIndex = ['emit' => [], 'subscribe' => []];
        $jobIndex = [];
        $cacheIndex = [];
        $schedulerIndex = [];
        $webhookIndex = ['incoming' => [], 'outgoing' => []];

        $dirs = glob($featuresRoot . '/*', GLOB_ONLYDIR) ?: [];
        sort($dirs);

        foreach ($dirs as $dir) {
            $feature = basename($dir);
            $manifestPath = $dir . '/feature.yaml';
            if (!is_file($manifestPath)) {
                continue;
            }

            $manifest = Yaml::parseFile($manifestPath);
            $inputSchema = 'app/features/' . $feature . '/input.schema.json';
            $outputSchema = 'app/features/' . $feature . '/output.schema.json';

            $featureIndex[$feature] = [
                'kind' => (string) ($manifest['kind'] ?? 'http'),
                'description' => (string) ($manifest['description'] ?? ''),
                'route' => is_array($manifest['route'] ?? null) ? $manifest['route'] : null,
                'input_schema' => (string) (($manifest['input']['schema'] ?? $inputSchema)),
                'output_schema' => (string) (($manifest['output']['schema'] ?? $outputSchema)),
                'auth' => (array) ($manifest['auth'] ?? []),
                'database' => (array) ($manifest['database'] ?? []),
                'cache' => (array) ($manifest['cache'] ?? []),
                'events' => (array) ($manifest['events'] ?? []),
                'jobs' => (array) ($manifest['jobs'] ?? []),
                'rate_limit' => (array) ($manifest['rate_limit'] ?? []),
                'tests' => (array) ($manifest['tests'] ?? []),
                'llm' => (array) ($manifest['llm'] ?? []),
                'base_path' => 'app/features/' . $feature,
                'action_class' => 'App\\Features\\' . Str::studly($feature) . '\\Action',
            ];

            if (($manifest['kind'] ?? null) === 'http' && is_array($manifest['route'] ?? null)) {
                $method = strtoupper((string) ($manifest['route']['method'] ?? 'GET'));
                $path = (string) ($manifest['route']['path'] ?? '/');
                $routes[$method . ' ' . $path] = [
                    'feature' => $feature,
                    'kind' => 'http',
                    'input_schema' => $inputSchema,
                    'output_schema' => $outputSchema,
                ];
            }

            $schemaIndex[$feature] = [
                'input' => $inputSchema,
                'output' => $outputSchema,
            ];

            $permissions = $this->loadPermissions($dir . '/permissions.yaml');
            $permissionIndex[$feature] = [
                'permissions' => $permissions,
            ];

            $events = $this->loadEvents($dir . '/events.yaml');
            foreach ($events['emit'] as $eventName => $schema) {
                $eventIndex['emit'][$eventName] = [
                    'feature' => $feature,
                    'schema' => $schema,
                ];
            }

            foreach ($events['subscribe'] as $eventName) {
                $eventIndex['subscribe'][$eventName] ??= [];
                $eventIndex['subscribe'][$eventName][] = $feature;
                sort($eventIndex['subscribe'][$eventName]);
            }

            $jobs = $this->loadJobs($dir . '/jobs.yaml');
            foreach ($jobs as $jobName => $jobDef) {
                $jobIndex[$jobName] = array_merge(['feature' => $feature], $jobDef);
            }

            $caches = $this->loadCache($dir . '/cache.yaml');
            foreach ($caches as $entryKey => $entryDef) {
                $cacheIndex[$entryKey] = array_merge(['feature' => $feature], $entryDef);
            }
        }

        ksort($featureIndex);
        ksort($routes);
        ksort($schemaIndex);
        ksort($permissionIndex);
        ksort($eventIndex['emit']);
        ksort($eventIndex['subscribe']);
        ksort($jobIndex);
        ksort($cacheIndex);

        $generatedDir = $this->paths->generated();
        if (!is_dir($generatedDir)) {
            mkdir($generatedDir, 0777, true);
        }

        $files = [];
        $files[] = $this->writeIndex('feature_index.php', $featureIndex);
        $files[] = $this->writeIndex('routes.php', $routes);
        $files[] = $this->writeIndex('schema_index.php', $schemaIndex);
        $files[] = $this->writeIndex('permission_index.php', $permissionIndex);
        $files[] = $this->writeIndex('event_index.php', $eventIndex);
        $files[] = $this->writeIndex('job_index.php', $jobIndex);
        $files[] = $this->writeIndex('cache_index.php', $cacheIndex);
        $files[] = $this->writeIndex('scheduler_index.php', $schedulerIndex);
        $files[] = $this->writeIndex('webhook_index.php', $webhookIndex);

        return $files;
    }

    /**
     * @return array<int,string>
     */
    private function loadPermissions(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $yaml = Yaml::parseFile($path);

        return array_values(array_map('strval', (array) ($yaml['permissions'] ?? [])));
    }

    /**
     * @return array{emit:array<string,array<string,mixed>>,subscribe:array<int,string>}
     */
    private function loadEvents(string $path): array
    {
        if (!is_file($path)) {
            return ['emit' => [], 'subscribe' => []];
        }

        $yaml = Yaml::parseFile($path);
        $emit = [];
        foreach ((array) ($yaml['emit'] ?? []) as $eventDef) {
            if (!is_array($eventDef)) {
                continue;
            }

            $name = (string) ($eventDef['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $emit[$name] = (array) ($eventDef['schema'] ?? []);
        }

        $subscribe = array_values(array_map('strval', (array) ($yaml['subscribe'] ?? [])));

        return ['emit' => $emit, 'subscribe' => $subscribe];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function loadJobs(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $yaml = Yaml::parseFile($path);
        $jobs = [];
        foreach ((array) ($yaml['dispatch'] ?? []) as $jobDef) {
            if (!is_array($jobDef)) {
                continue;
            }

            $name = (string) ($jobDef['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $jobs[$name] = [
                'input_schema' => (array) ($jobDef['input_schema'] ?? []),
                'queue' => (string) ($jobDef['queue'] ?? 'default'),
                'retry' => (array) ($jobDef['retry'] ?? []),
                'timeout_seconds' => (int) ($jobDef['timeout_seconds'] ?? 60),
                'idempotency_key' => isset($jobDef['idempotency_key']) ? (string) $jobDef['idempotency_key'] : null,
            ];
        }

        ksort($jobs);

        return $jobs;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function loadCache(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $yaml = Yaml::parseFile($path);
        $entries = [];
        foreach ((array) ($yaml['entries'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $key = (string) ($entry['key'] ?? '');
            if ($key === '') {
                continue;
            }

            $entries[$key] = [
                'kind' => (string) ($entry['kind'] ?? 'computed'),
                'ttl_seconds' => (int) ($entry['ttl_seconds'] ?? 300),
                'invalidated_by' => array_values(array_map('strval', (array) ($entry['invalidated_by'] ?? []))),
            ];
        }

        ksort($entries);

        return $entries;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function writeIndex(string $name, array $data): string
    {
        $path = $this->paths->join('app/generated/' . $name);
        $source = 'app/features/<feature>/feature.yaml';
        $content = "<?php\ndeclare(strict_types=1);\n\n/**\n * GENERATED FILE - DO NOT EDIT DIRECTLY\n * Source: {$source}\n * Regenerate with: foundry generate indexes\n */\n\nreturn " . var_export($data, true) . ";\n";
        file_put_contents($path, $content);

        return $path;
    }
}
