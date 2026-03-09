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
            'extensions' => $state->extensions->inspectRows(),
            'extension_registration_sources' => $state->extensions->registrationSources(),
            'packs' => $state->extensions->packRegistry()->inspectRows(),
            'spec_formats' => array_values(array_map(
                static fn ($format): array => method_exists($format, 'toArray') ? $format->toArray() : [],
                $state->extensions->specFormats(),
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
        ];

        $manifestPath = $state->layout->compileManifestPath();
        file_put_contents($manifestPath, Json::encode($manifest, true) . "\n");
        $writtenFiles[] = $manifestPath;

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

        $integrityPath = $state->layout->integrityHashesPath();
        file_put_contents($integrityPath, Json::encode($integrityHashes, true) . "\n");
        $writtenFiles[] = $integrityPath;

        $state->manifest = $manifest;
        $state->integrityHashes = $integrityHashes;
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
 * Regenerate with: php vendor/bin/foundry compile graph
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
