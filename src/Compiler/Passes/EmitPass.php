<?php
declare(strict_types=1);

namespace Foundry\Compiler\Passes;

use Foundry\Compiler\CompilationState;
use Foundry\Compiler\CompilerPass;
use Foundry\Compiler\Projection\ProjectionEmitter;
use Foundry\Support\Json;

final class EmitPass implements CompilerPass
{
    public function name(): string
    {
        return 'emit';
    }

    public function run(CompilationState $state): void
    {
        if (!$state->options->emit) {
            return;
        }

        $state->layout->ensureDirectories();

        $writtenFiles = [];

        $graph = $state->graph->toArray($state->diagnostics);
        $graph['diagnostics_summary'] = $state->diagnostics->summary();
        $graph['analysis'] = $state->analysis;

        $graphJsonPath = $state->layout->graphJsonPath();
        file_put_contents($graphJsonPath, Json::encode($graph, true) . "\n");
        $writtenFiles[] = $graphJsonPath;

        $graphPhpPath = $state->layout->graphPhpPath();
        file_put_contents($graphPhpPath, $this->phpHeader() . 'return ' . var_export($graph, true) . ";\n");
        $writtenFiles[] = $graphPhpPath;

        $diagnosticsPath = $state->layout->diagnosticsPath();
        file_put_contents($diagnosticsPath, Json::encode([
            'summary' => $state->diagnostics->summary(),
            'diagnostics' => $state->diagnostics->toArray(),
        ], true) . "\n");
        $writtenFiles[] = $diagnosticsPath;

        $configValidationPath = $state->layout->configValidationPath();
        file_put_contents($configValidationPath, Json::encode(
            $state->configValidation !== [] ? $state->configValidation : [
                'summary' => ['error' => 0, 'warning' => 0, 'info' => 0, 'total' => 0],
                'items' => [],
                'schema_ids' => array_keys($state->configSchemas),
                'validated_sources' => [],
            ],
            true,
        ) . "\n");
        $writtenFiles[] = $configValidationPath;

        $projectionRows = [];
        foreach ($state->extensions->projectionEmitters() as $emitter) {
            if (!$emitter instanceof ProjectionEmitter) {
                continue;
            }

            $payload = $emitter->emit($state->graph);
            $projectionRows[$emitter->id()] = [
                'file' => $emitter->fileName(),
                'legacy_file' => $emitter->legacyFileName(),
                'payload' => $payload,
            ];

            $projectionPath = $state->layout->projectionPath($emitter->fileName());
            file_put_contents($projectionPath, $this->phpHeader() . 'return ' . var_export($payload, true) . ";\n");
            $writtenFiles[] = $projectionPath;

            $legacyFile = $emitter->legacyFileName();
            if ($legacyFile !== null && $legacyFile !== '') {
                $legacyPath = $state->layout->legacyProjectionPath($legacyFile);
                file_put_contents($legacyPath, $this->phpHeader() . 'return ' . var_export($payload, true) . ";\n");
                $writtenFiles[] = $legacyPath;
            }
        }

        ksort($projectionRows);
        $state->projections = $projectionRows;

        $configSchemasPath = $state->layout->configSchemasPath();
        file_put_contents($configSchemasPath, Json::encode([
            'schema_version' => 1,
            'generated_at' => $state->graph->compiledAt(),
            'schemas' => $state->configSchemas,
        ], true) . "\n");
        $writtenFiles[] = $configSchemasPath;

        $manifestPath = $state->layout->compileManifestPath();
        $cachePath = $state->layout->compileCachePath();
        $integrityPath = $state->layout->integrityHashesPath();
        $artifactPaths = array_values(array_unique(array_merge(
            $writtenFiles,
            [$manifestPath, $cachePath, $integrityPath],
        )));
        sort($artifactPaths);

        $cacheManifest = [
            'schema_version' => 1,
            'key' => (string) ($state->cache['key'] ?? ''),
            'inputs' => (array) ($state->cache['inputs'] ?? []),
            'stored_at' => $state->graph->compiledAt(),
            'last_compile' => [
                'status' => (string) ($state->cache['status'] ?? 'miss'),
                'reason' => (string) ($state->cache['reason'] ?? ''),
                'reasons' => array_values(array_map('strval', (array) ($state->cache['reasons'] ?? []))),
                'invalidated_inputs' => array_values(array_map('strval', (array) ($state->cache['invalidated_inputs'] ?? []))),
                'requires_full_recompile' => (bool) ($state->cache['requires_full_recompile'] ?? false),
            ],
            'artifacts' => [
                'required_count' => count($artifactPaths),
                'paths' => array_values(array_map(
                    fn (string $path): string => $this->relativePath($state, $path),
                    $artifactPaths,
                )),
            ],
        ];

        $manifest = [
            'graph_version' => $state->graph->graphVersion(),
            'framework_version' => $state->graph->frameworkVersion(),
            'compiled_at' => $state->graph->compiledAt(),
            'source_hash' => $state->graph->sourceHash(),
            'mode' => $state->plan->mode,
            'incremental' => $state->plan->incremental,
            'no_changes' => $state->plan->noChanges,
            'fallback_to_full' => $state->plan->fallbackToFull,
            'reason' => $state->plan->reason,
            'selected_features' => $state->plan->selectedFeatures,
            'changed_features' => $state->plan->changedFeatures,
            'changed_files' => $state->plan->changedFiles,
            'features' => $state->graph->features(),
            'source_files' => $state->sourceHashes,
            'diagnostics_summary' => $state->diagnostics->summary(),
            'analysis' => [
                'change_risk' => $state->analysis['change_risk'] ?? 'low',
                'changed_nodes' => array_keys((array) ($state->analysis['change_impact'] ?? [])),
                'compatibility' => $state->analysis['compatibility'] ?? [],
            ],
            'config_schemas' => [
                'path' => $this->relativePath($state, $configSchemasPath),
                'count' => count($state->configSchemas),
            ],
            'config_validation' => [
                'path' => $this->relativePath($state, $configValidationPath),
                'summary' => (array) ($state->configValidation['summary'] ?? []),
            ],
            'extensions' => $state->extensions->inspectRows(),
            'extension_registration_sources' => $state->extensions->registrationSources(),
            'packs' => $state->extensions->packRegistry()->inspectRows(),
            'definition_formats' => array_values(array_map(
                static fn ($format): array => method_exists($format, 'toArray') ? $format->toArray() : [],
                $state->extensions->definitionFormats(),
            )),
            'codemods' => array_values(array_map(
                static fn ($codemod): array => [
                    'id' => method_exists($codemod, 'id') ? (string) $codemod->id() : '',
                    'description' => method_exists($codemod, 'description') ? (string) $codemod->description() : '',
                    'source_type' => method_exists($codemod, 'sourceType') ? (string) $codemod->sourceType() : '',
                ],
                $state->extensions->codemods(),
            )),
            'projections' => array_values(array_map(
                static fn (array $row): string => (string) ($row['file'] ?? ''),
                $projectionRows,
            )),
            'cache' => $cacheManifest + [
                'path' => $this->relativePath($state, $cachePath),
            ],
        ];

        file_put_contents($manifestPath, Json::encode($manifest, true) . "\n");
        $writtenFiles[] = $manifestPath;

        file_put_contents($cachePath, Json::encode($cacheManifest, true) . "\n");
        $writtenFiles[] = $cachePath;

        $integrityHashes = [];
        sort($writtenFiles);
        foreach ($writtenFiles as $path) {
            if (!is_file($path)) {
                continue;
            }

            $hash = hash_file('sha256', $path);
            if ($hash === false) {
                continue;
            }

            $integrityHashes[$this->relativePath($state, $path)] = $hash;
        }
        ksort($integrityHashes);

        file_put_contents($integrityPath, Json::encode($integrityHashes, true) . "\n");
        $writtenFiles[] = $integrityPath;

        $state->manifest = $manifest;
        $state->integrityHashes = $integrityHashes;
        $state->cache = $cacheManifest + [
            'status' => (string) ($state->cache['status'] ?? 'miss'),
            'reason' => (string) ($state->cache['reason'] ?? ''),
            'reasons' => array_values(array_map('strval', (array) ($state->cache['reasons'] ?? []))),
            'invalidated_inputs' => array_values(array_map('strval', (array) ($state->cache['invalidated_inputs'] ?? []))),
            'requires_full_recompile' => (bool) ($state->cache['requires_full_recompile'] ?? false),
            'enabled' => (bool) ($state->cache['enabled'] ?? true),
            'stored_key' => is_string($state->cache['stored_key'] ?? null) ? (string) ($state->cache['stored_key'] ?? '') : null,
            'stored_inputs' => is_array($state->cache['stored_inputs'] ?? null) ? $state->cache['stored_inputs'] : [],
            'paths' => is_array($state->cache['paths'] ?? null) ? $state->cache['paths'] : [],
            'build' => is_array($state->cache['build'] ?? null) ? $state->cache['build'] : [],
        ];
        $state->analysis['written_files'] = array_values(array_unique(array_map(
            fn (string $path): string => $this->relativePath($state, $path),
            $writtenFiles,
        )));
    }

    private function phpHeader(): string
    {
        return <<<'PHP'
<?php
declare(strict_types=1);

/**
 * GENERATED FILE - DO NOT EDIT
 * Built by Foundry semantic compiler.
 * Regenerate with: foundry compile graph
 */

PHP;
    }

    private function relativePath(CompilationState $state, string $path): string
    {
        $root = rtrim($state->paths->root(), '/') . '/';

        return str_starts_with($path, $root)
            ? substr($path, strlen($root))
            : $path;
    }
}
