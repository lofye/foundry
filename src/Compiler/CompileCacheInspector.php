<?php
declare(strict_types=1);

namespace Foundry\Compiler;

use Foundry\Compiler\Extensions\ExtensionRegistry;
use Foundry\Support\Json;
use Foundry\Support\Paths;

final class CompileCacheInspector
{
    private const SCHEMA_VERSION = 1;

    /**
     * @var array<int,string>
     */
    private const FULL_REBUILD_INPUTS = [
        'cache_metadata',
        'cache_schema',
        'compiled_artifacts',
        'framework_version',
        'graph_version',
        'framework_source_hash',
        'extension_metadata_hash',
        'compatibility_hash',
    ];

    public function __construct(
        private readonly Paths $paths,
        private readonly BuildLayout $layout,
        private readonly SourceScanner $scanner,
    ) {
    }

    /**
     * @param array<string,string> $sourceHashes
     * @param array<string,mixed> $previousManifest
     * @param array<string,mixed> $compatibility
     * @return array<string,mixed>
     */
    public function inspect(
        CompileOptions $options,
        array $sourceHashes,
        array $previousManifest,
        ?ApplicationGraph $previousGraph,
        ExtensionRegistry $extensions,
        array $compatibility,
        string $frameworkVersion,
        int $graphVersion,
    ): array {
        $current = $this->currentState(
            sourceHashes: $sourceHashes,
            extensions: $extensions,
            compatibility: $compatibility,
            frameworkVersion: $frameworkVersion,
            graphVersion: $graphVersion,
        );

        $requiredArtifacts = $this->requiredArtifactPaths($extensions);
        $missingArtifacts = array_values(array_filter(
            $requiredArtifacts,
            static fn (string $path): bool => !is_file($path),
        ));
        sort($missingArtifacts);

        $storedCache = $this->readJson($this->layout->compileCachePath());
        $storedKey = is_string($storedCache['key'] ?? null) ? (string) $storedCache['key'] : null;
        $storedInputs = is_array($storedCache['inputs'] ?? null) ? $storedCache['inputs'] : [];

        $basePayload = [
            'schema_version' => self::SCHEMA_VERSION,
            'enabled' => $options->useCache,
            'key' => $current['key'],
            'stored_key' => $storedKey,
            'inputs' => $current['inputs'],
            'stored_inputs' => $storedInputs,
            'paths' => [
                'manifest' => $this->relativePath($this->layout->compileManifestPath()),
                'cache' => $this->relativePath($this->layout->compileCachePath()),
                'graph' => $this->relativePath($this->layout->graphJsonPath()),
            ],
            'build' => [
                'compiled_at' => is_string($previousManifest['compiled_at'] ?? null) ? (string) $previousManifest['compiled_at'] : null,
            ],
            'artifacts' => [
                'required_count' => count($requiredArtifacts),
                'missing' => array_values(array_map($this->relativePath(...), $missingArtifacts)),
            ],
        ];

        if (!$options->useCache) {
            return $basePayload + [
                'status' => 'disabled',
                'reason' => 'Compile cache disabled for this run.',
                'reasons' => ['Compile cache disabled for this run.'],
                'invalidated_inputs' => [],
                'requires_full_recompile' => false,
            ];
        }

        if ($previousGraph === null || $previousManifest === []) {
            return $basePayload + [
                'status' => 'miss',
                'reason' => 'No previous compile cache state found.',
                'reasons' => ['No previous compile cache state found.'],
                'invalidated_inputs' => ['compiled_artifacts'],
                'requires_full_recompile' => true,
            ];
        }

        if ($storedCache === null) {
            return $basePayload + [
                'status' => 'miss',
                'reason' => 'Previous build is missing compile cache metadata.',
                'reasons' => ['Previous build is missing compile cache metadata.'],
                'invalidated_inputs' => ['cache_metadata'],
                'requires_full_recompile' => true,
            ];
        }

        if ((int) ($storedCache['schema_version'] ?? 0) !== self::SCHEMA_VERSION) {
            return $basePayload + [
                'status' => 'miss',
                'reason' => 'Compile cache schema changed.',
                'reasons' => ['Compile cache schema changed.'],
                'invalidated_inputs' => ['cache_schema'],
                'requires_full_recompile' => true,
            ];
        }

        if ($missingArtifacts !== []) {
            return $basePayload + [
                'status' => 'miss',
                'reason' => 'Compiled artifacts are missing from the managed cache set.',
                'reasons' => ['Compiled artifacts are missing from the managed cache set.'],
                'invalidated_inputs' => ['compiled_artifacts'],
                'requires_full_recompile' => true,
            ];
        }

        $invalidatedInputs = $this->invalidatedInputs($storedInputs, $current['inputs']);
        if ($invalidatedInputs === []) {
            return $basePayload + [
                'status' => 'hit',
                'reason' => 'Stable compile inputs matched the existing build artifacts.',
                'reasons' => ['Stable compile inputs matched the existing build artifacts.'],
                'invalidated_inputs' => [],
                'requires_full_recompile' => false,
            ];
        }

        $reasons = $this->reasonsForInputs($invalidatedInputs);

        return $basePayload + [
            'status' => 'invalidated',
            'reason' => $this->summarizeReasons($reasons),
            'reasons' => $reasons,
            'invalidated_inputs' => $invalidatedInputs,
            'requires_full_recompile' => $this->requiresFullRecompile($invalidatedInputs),
        ];
    }

    /**
     * @param array<string,string> $sourceHashes
     * @param array<string,mixed> $compatibility
     * @return array{schema_version:int,key:string,inputs:array<string,string|int>}
     */
    public function currentState(
        array $sourceHashes,
        ExtensionRegistry $extensions,
        array $compatibility,
        string $frameworkVersion,
        int $graphVersion,
    ): array {
        $groups = $this->groupedSourceHashes($sourceHashes);

        $inputs = [
            'compatibility_hash' => $this->stableHash($compatibility),
            'definition_hash' => $this->scanner->aggregateHash($groups['definitions']),
            'extension_metadata_hash' => $this->stableHash([
                'extensions' => $extensions->inspectRows(),
                'packs' => $extensions->packRegistry()->inspectRows(),
                'registration_sources' => $extensions->registrationSources(),
                'load_order' => $extensions->loadOrder(),
                'diagnostics' => $extensions->diagnostics(),
            ]),
            'extension_registration_hash' => $this->scanner->aggregateHash($groups['extension_registrations']),
            'feature_manifest_hash' => $this->scanner->aggregateHash($groups['feature_manifests']),
            'feature_source_hash' => $this->scanner->aggregateHash($groups['feature_sources']),
            'framework_source_hash' => $this->frameworkSourceHash(),
            'framework_version' => $frameworkVersion,
            'graph_version' => $graphVersion,
            'platform_config_hash' => $this->scanner->aggregateHash($groups['platform_config']),
            'schema_hash' => $this->scanner->aggregateHash($groups['schemas']),
            'source_hash' => $this->scanner->aggregateHash($sourceHashes),
        ];
        ksort($inputs);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'key' => $this->stableHash($inputs),
            'inputs' => $inputs,
        ];
    }

    /**
     * @return array{cleared:bool,removed_count:int,removed_paths:array<int,string>}
     */
    public function clear(): array
    {
        $removed = [];

        $buildRoot = $this->layout->buildRoot();
        if (is_dir($buildRoot)) {
            $this->deleteDirectory($buildRoot);
            $removed[] = $this->relativePath($buildRoot);
        }

        foreach (glob($this->paths->generated() . '/*.php') ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }

            @unlink($file);
            $removed[] = $this->relativePath($file);
        }

        $removed = array_values(array_unique(array_map('strval', $removed)));
        sort($removed);

        return [
            'cleared' => $removed !== [],
            'removed_count' => count($removed),
            'removed_paths' => $removed,
        ];
    }

    /**
     * @param array<string,string|int> $storedInputs
     * @param array<string,string|int> $currentInputs
     * @return array<int,string>
     */
    private function invalidatedInputs(array $storedInputs, array $currentInputs): array
    {
        $keys = array_values(array_unique(array_merge(array_keys($storedInputs), array_keys($currentInputs))));
        sort($keys);

        $invalidated = [];
        foreach ($keys as $key) {
            $stored = $storedInputs[$key] ?? null;
            $current = $currentInputs[$key] ?? null;

            if ((string) $stored !== (string) $current) {
                $invalidated[] = (string) $key;
            }
        }

        return $invalidated;
    }

    private function requiresFullRecompile(array $invalidatedInputs): bool
    {
        return array_intersect($invalidatedInputs, self::FULL_REBUILD_INPUTS) !== [];
    }

    /**
     * @param array<int,string> $invalidatedInputs
     * @return array<int,string>
     */
    private function reasonsForInputs(array $invalidatedInputs): array
    {
        $reasons = [];

        foreach ($invalidatedInputs as $input) {
            $reasons[] = match ($input) {
                'feature_manifest_hash' => 'Feature manifest hashes changed.',
                'schema_hash' => 'Feature schema inputs changed.',
                'feature_source_hash' => 'Feature source files changed.',
                'platform_config_hash' => 'Platform configuration inputs changed.',
                'definition_hash' => 'Definition files changed.',
                'extension_registration_hash' => 'Extension registration inputs changed.',
                'extension_metadata_hash' => 'Extension metadata changed.',
                'compatibility_hash' => 'Extension compatibility markers changed.',
                'framework_source_hash' => 'Framework source inputs changed.',
                'framework_version' => 'Framework version marker changed.',
                'graph_version' => 'Compiler graph version changed.',
                'cache_metadata' => 'Previous build is missing compile cache metadata.',
                'cache_schema' => 'Compile cache schema changed.',
                'compiled_artifacts' => 'Compiled artifacts are missing from the managed cache set.',
                'source_hash' => 'Aggregate source hash changed.',
                default => sprintf('Compile cache input changed: %s.', $input),
            };
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @param array<int,string> $reasons
     */
    private function summarizeReasons(array $reasons): string
    {
        if ($reasons === []) {
            return 'Compile cache invalidated.';
        }

        if (count($reasons) === 1) {
            return $reasons[0];
        }

        return sprintf('%s (+%d more)', $reasons[0], count($reasons) - 1);
    }

    /**
     * @param array<string,string> $sourceHashes
     * @return array<string,array<string,string>>
     */
    private function groupedSourceHashes(array $sourceHashes): array
    {
        $groups = [
            'feature_manifests' => [],
            'schemas' => [],
            'feature_sources' => [],
            'platform_config' => [],
            'definitions' => [],
            'extension_registrations' => [],
        ];

        foreach ($sourceHashes as $path => $hash) {
            if ($path === 'foundry.extensions.php' || $path === 'config/foundry/extensions.php') {
                $groups['extension_registrations'][$path] = $hash;
                continue;
            }

            if (str_starts_with($path, 'config/') || str_starts_with($path, 'bootstrap/')) {
                $groups['platform_config'][$path] = $hash;
                continue;
            }

            if (str_starts_with($path, 'app/definitions/')) {
                $groups['definitions'][$path] = $hash;
                continue;
            }

            if (preg_match('#^app/features/[^/]+/feature\.yaml$#', $path) === 1) {
                $groups['feature_manifests'][$path] = $hash;
                continue;
            }

            if (preg_match('#^app/features/[^/]+/(input\.schema\.json|output\.schema\.json|context\.manifest\.json)$#', $path) === 1) {
                $groups['schemas'][$path] = $hash;
                continue;
            }

            if (str_starts_with($path, 'app/features/')) {
                $groups['feature_sources'][$path] = $hash;
            }
        }

        foreach ($groups as &$group) {
            ksort($group);
        }
        unset($group);

        return $groups;
    }

    /**
     * @return array<int,string>
     */
    private function requiredArtifactPaths(ExtensionRegistry $extensions): array
    {
        $paths = [
            $this->layout->graphJsonPath(),
            $this->layout->graphPhpPath(),
            $this->layout->compileManifestPath(),
            $this->layout->compileCachePath(),
            $this->layout->integrityHashesPath(),
            $this->layout->diagnosticsPath(),
            $this->layout->configValidationPath(),
            $this->layout->configSchemasPath(),
        ];

        foreach ($extensions->projectionEmitters() as $emitter) {
            $paths[] = $this->layout->projectionPath($emitter->fileName());

            $legacyFile = $emitter->legacyFileName();
            if ($legacyFile !== null && $legacyFile !== '') {
                $paths[] = $this->layout->legacyProjectionPath($legacyFile);
            }
        }

        $paths = array_values(array_unique(array_map('strval', $paths)));
        sort($paths);

        return $paths;
    }

    private function frameworkSourceHash(): string
    {
        $hashes = [];

        $composerPath = $this->paths->frameworkJoin('composer.json');
        if (is_file($composerPath)) {
            $hash = hash_file('sha256', $composerPath);
            if ($hash !== false) {
                $hashes['composer.json'] = $hash;
            }
        }

        $srcDir = $this->paths->frameworkJoin('src');
        if (is_dir($srcDir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $fileInfo) {
                if (!$fileInfo instanceof \SplFileInfo || !$fileInfo->isFile()) {
                    continue;
                }

                $pathname = $fileInfo->getPathname();
                if (pathinfo($pathname, PATHINFO_EXTENSION) !== 'php') {
                    continue;
                }

                $relative = $this->frameworkRelativePath($pathname);
                if ($relative === '') {
                    continue;
                }

                $hash = hash_file('sha256', $pathname);
                if ($hash === false) {
                    continue;
                }

                $hashes[$relative] = $hash;
            }
        }

        ksort($hashes);

        return $this->aggregateHashes($hashes);
    }

    /**
     * @param array<string,string> $hashes
     */
    private function aggregateHashes(array $hashes): string
    {
        $buffer = '';
        foreach ($hashes as $path => $hash) {
            $buffer .= $path . ':' . $hash . "\n";
        }

        return hash('sha256', $buffer);
    }

    private function stableHash(mixed $value): string
    {
        return hash('sha256', Json::encode($this->normalizeForHash($value)));
    }

    private function normalizeForHash(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_values(array_map($this->normalizeForHash(...), $value));
        }

        ksort($value);

        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[(string) $key] = $this->normalizeForHash($item);
        }

        return $normalized;
    }

    private function relativePath(string $absolutePath): string
    {
        $root = rtrim($this->paths->root(), '/') . '/';

        return str_starts_with($absolutePath, $root)
            ? substr($absolutePath, strlen($root))
            : $absolutePath;
    }

    private function frameworkRelativePath(string $absolutePath): string
    {
        $root = rtrim($this->paths->frameworkRoot(), '/') . '/';

        return str_starts_with($absolutePath, $root)
            ? substr($absolutePath, strlen($root))
            : '';
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return null;
        }

        try {
            return Json::decodeAssoc($content);
        } catch (\Throwable) {
            return null;
        }
    }

    private function deleteDirectory(string $path): void
    {
        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $fullPath = $path . '/' . $item;
            if (is_dir($fullPath)) {
                $this->deleteDirectory($fullPath);
                @rmdir($fullPath);
                continue;
            }

            @unlink($fullPath);
        }

        @rmdir($path);
    }
}
