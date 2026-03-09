<?php
declare(strict_types=1);

namespace Foundry\Compiler\Passes;

use Foundry\Compiler\CompilationState;
use Foundry\Compiler\CompilerPass;
use Foundry\DB\SqlFileLoader;
use Foundry\Support\Json;
use Foundry\Support\Yaml;

final class DiscoveryPass implements CompilerPass
{
    public function __construct(private readonly SqlFileLoader $sqlLoader = new SqlFileLoader())
    {
    }

    public function name(): string
    {
        return 'discovery';
    }

    public function run(CompilationState $state): void
    {
        $selected = $state->plan->selectedFeatures;
        sort($selected);

        foreach ($selected as $feature) {
            $base = $state->paths->join('app/features/' . $feature);
            if (!is_dir($base)) {
                $state->discoveredFeatures[$feature] = [
                    'feature' => $feature,
                    'removed' => true,
                ];
                continue;
            }

            $manifestPath = $base . '/feature.yaml';
            if (!is_file($manifestPath)) {
                $state->diagnostics->error(
                    code: 'FDY0101_MANIFEST_MISSING',
                    category: 'discovery',
                    message: 'Feature is missing required feature.yaml manifest.',
                    nodeId: 'feature:' . $feature,
                    sourcePath: 'app/features/' . $feature,
                    suggestedFix: 'Create app/features/' . $feature . '/feature.yaml.',
                    pass: $this->name(),
                );
                continue;
            }

            $manifest = $this->safeYaml($state, $manifestPath, 'FDY0102_MANIFEST_INVALID', 'discovery');
            if ($manifest === null) {
                continue;
            }

            $inputSchemaPath = $this->normalizeSchemaPath($feature, (string) ($manifest['input']['schema'] ?? 'app/features/' . $feature . '/input.schema.json'));
            $outputSchemaPath = $this->normalizeSchemaPath($feature, (string) ($manifest['output']['schema'] ?? 'app/features/' . $feature . '/output.schema.json'));

            $inputSchema = $this->safeJson($state, $state->paths->join($inputSchemaPath), 'FDY0103_INPUT_SCHEMA_INVALID', 'schemas', false);
            $outputSchema = $this->safeJson($state, $state->paths->join($outputSchemaPath), 'FDY0104_OUTPUT_SCHEMA_INVALID', 'schemas', false);

            $permissions = $this->safeYaml($state, $base . '/permissions.yaml', 'FDY0105_PERMISSIONS_INVALID', 'permissions', true) ?? [];
            $events = $this->safeYaml($state, $base . '/events.yaml', 'FDY0106_EVENTS_INVALID', 'events', true) ?? [];
            $jobs = $this->safeYaml($state, $base . '/jobs.yaml', 'FDY0107_JOBS_INVALID', 'jobs', true) ?? [];
            $cache = $this->safeYaml($state, $base . '/cache.yaml', 'FDY0108_CACHE_INVALID', 'cache', true) ?? [];
            $scheduler = $this->safeYaml($state, $base . '/scheduler.yaml', 'FDY0109_SCHEDULER_INVALID', 'scheduler', true) ?? [];
            $webhooks = $this->safeYaml($state, $base . '/webhooks.yaml', 'FDY0110_WEBHOOKS_INVALID', 'webhooks', true) ?? [];

            $contextManifest = $this->safeJson($state, $base . '/context.manifest.json', 'FDY0111_CONTEXT_MANIFEST_INVALID', 'discovery', true);

            $queries = [];
            $queriesPath = $base . '/queries.sql';
            if (is_file($queriesPath)) {
                $sql = file_get_contents($queriesPath);
                if ($sql === false) {
                    $state->diagnostics->error(
                        code: 'FDY0112_QUERY_FILE_READ_ERROR',
                        category: 'queries',
                        message: 'Unable to read queries.sql.',
                        nodeId: 'feature:' . $feature,
                        sourcePath: 'app/features/' . $feature . '/queries.sql',
                        pass: $this->name(),
                    );
                } else {
                    try {
                        $definitions = $this->sqlLoader->parse($feature, $sql);
                        foreach ($definitions as $definition) {
                            $queries[] = [
                                'name' => $definition->name,
                                'sql' => $definition->sql,
                                'placeholders' => $definition->placeholders,
                            ];
                        }
                    } catch (\Throwable $error) {
                        $state->diagnostics->error(
                            code: 'FDY0113_QUERY_PARSE_ERROR',
                            category: 'queries',
                            message: $error->getMessage(),
                            nodeId: 'feature:' . $feature,
                            sourcePath: 'app/features/' . $feature . '/queries.sql',
                            pass: $this->name(),
                        );
                    }
                }
            }

            $sourceFiles = [];
            foreach ($state->sourceHashes as $path => $hash) {
                if (str_starts_with($path, 'app/features/' . $feature . '/')) {
                    $sourceFiles[] = $path;
                }
            }
            sort($sourceFiles);

            $testsPath = $base . '/tests';
            $testFiles = [];
            if (is_dir($testsPath)) {
                foreach (glob($testsPath . '/*.php') ?: [] as $file) {
                    $testFiles[] = $this->relativePath($state, $file);
                }
            }
            sort($testFiles);

            $state->discoveredFeatures[$feature] = [
                'feature' => $feature,
                'removed' => false,
                'base_path' => 'app/features/' . $feature,
                'manifest_path' => $this->relativePath($state, $manifestPath),
                'manifest' => $manifest,
                'input_schema_path' => $inputSchemaPath,
                'input_schema' => $inputSchema,
                'output_schema_path' => $outputSchemaPath,
                'output_schema' => $outputSchema,
                'permissions' => $permissions,
                'events' => $events,
                'jobs' => $jobs,
                'cache' => $cache,
                'scheduler' => $scheduler,
                'webhooks' => $webhooks,
                'queries' => $queries,
                'context_manifest' => $contextManifest,
                'test_files' => $testFiles,
                'source_files' => $sourceFiles,
            ];
        }

        ksort($state->discoveredFeatures);
        $state->discoveredSpecs = $this->discoverPhaseOneSpecs($state);
        $state->analysis['discovered_specs'] = $state->discoveredSpecs;
    }

    private function normalizeSchemaPath(string $feature, string $path): string
    {
        if ($path === '') {
            return 'app/features/' . $feature . '/input.schema.json';
        }

        if (str_starts_with($path, 'app/')) {
            return $path;
        }

        return 'app/features/' . $feature . '/' . ltrim($path, '/');
    }

    /**
     * @return array<string,mixed>|null
     */
    private function safeYaml(
        CompilationState $state,
        string $path,
        string $code,
        string $category,
        bool $optional = false,
    ): ?array {
        if (!is_file($path)) {
            return $optional ? null : [];
        }

        try {
            return Yaml::parseFile($path);
        } catch (\Throwable $error) {
            $state->diagnostics->error(
                code: $code,
                category: $category,
                message: $error->getMessage(),
                sourcePath: $this->relativePath($state, $path),
                pass: $this->name(),
            );

            return null;
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function safeJson(
        CompilationState $state,
        string $path,
        string $code,
        string $category,
        bool $optional = false,
    ): ?array {
        if (!is_file($path)) {
            if (!$optional) {
                $state->diagnostics->error(
                    code: $code,
                    category: $category,
                    message: 'File not found.',
                    sourcePath: $this->relativePath($state, $path),
                    pass: $this->name(),
                );
            }

            return null;
        }

        $json = file_get_contents($path);
        if ($json === false) {
            $state->diagnostics->error(
                code: $code,
                category: $category,
                message: 'Unable to read JSON file.',
                sourcePath: $this->relativePath($state, $path),
                pass: $this->name(),
            );

            return null;
        }

        try {
            return Json::decodeAssoc($json);
        } catch (\Throwable $error) {
            $state->diagnostics->error(
                code: $code,
                category: $category,
                message: $error->getMessage(),
                sourcePath: $this->relativePath($state, $path),
                pass: $this->name(),
            );

            return null;
        }
    }

    private function relativePath(CompilationState $state, string $path): string
    {
        $root = rtrim($state->paths->root(), '/') . '/';

        return str_starts_with($path, $root)
            ? substr($path, strlen($root))
            : $path;
    }

    /**
     * @return array<string,array<string,array<string,mixed>>>
     */
    private function discoverPhaseOneSpecs(CompilationState $state): array
    {
        $map = [
            'starter' => 'app/specs/starters/*.starter.yaml',
            'resource' => 'app/specs/resources/*.resource.yaml',
            'admin_resource' => 'app/specs/admin/*.admin.yaml',
            'upload_profile' => 'app/specs/uploads/*.uploads.yaml',
            'listing_config' => 'app/specs/listing/*.list.yaml',
        ];

        $discovered = [];
        foreach ($map as $type => $pattern) {
            $paths = glob($state->paths->join($pattern)) ?: [];
            sort($paths);

            foreach ($paths as $path) {
                $relative = $this->relativePath($state, $path);
                $document = $this->safeYaml(
                    state: $state,
                    path: $path,
                    code: 'FDY2100_SPEC_PARSE_ERROR',
                    category: 'discovery',
                    optional: false,
                );
                if ($document === null) {
                    continue;
                }

                $name = $this->specName($type, $document, $path);
                if ($name === '') {
                    continue;
                }

                $discovered[$type][$name] = [
                    'type' => $type,
                    'name' => $name,
                    'path' => $relative,
                    'document' => $document,
                ];
            }
        }

        ksort($discovered);
        foreach ($discovered as &$rows) {
            ksort($rows);
        }
        unset($rows);

        return $discovered;
    }

    /**
     * @param array<string,mixed> $document
     */
    private function specName(string $type, array $document, string $path): string
    {
        $name = match ($type) {
            'starter' => (string) ($document['starter'] ?? ''),
            'resource', 'admin_resource', 'listing_config' => (string) ($document['resource'] ?? ''),
            'upload_profile' => (string) ($document['profile'] ?? ''),
            default => '',
        };

        if ($name === '') {
            $base = basename($path);
            $name = preg_replace('/\.[a-z]+\.yaml$/', '', $base) ?? '';
        }

        $name = trim($name);

        return $name;
    }
}
