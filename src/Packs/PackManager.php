<?php

declare(strict_types=1);

namespace Foundry\Packs;

use Foundry\CLI\CommandContext;
use Foundry\Compiler\CompileOptions;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainTarget;
use Foundry\Pro\ArchitectureExplainer;
use Foundry\Support\FoundryError;
use Foundry\Support\Paths;

final class PackManager
{
    private readonly InstalledPackRegistry $registry;
    private readonly HostedPackRegistry $hostedRegistry;
    private readonly PackArchiveExtractor $archiveExtractor;

    public function __construct(
        private readonly Paths $paths,
        ?HostedPackRegistry $hostedRegistry = null,
        ?PackArchiveExtractor $archiveExtractor = null,
    ) {
        $this->registry = new InstalledPackRegistry($paths);
        $this->hostedRegistry = $hostedRegistry ?? new HostedPackRegistry($paths);
        $this->archiveExtractor = $archiveExtractor ?? new PackArchiveExtractor();
    }

    /**
     * @return array<string,mixed>
     */
    public function install(string $source, ?CommandContext $context = null): array
    {
        $source = trim($source);
        if ($source === '') {
            throw new FoundryError(
                'PACK_SOURCE_REQUIRED',
                'validation',
                [],
                'Pack source path or hosted pack name is required.',
            );
        }

        $localSource = $this->tryResolveExistingLocalSource($source);
        if ($localSource !== null) {
            return $this->installResolvedSource(
                $localSource,
                $context,
                [
                    'source' => [
                        'type' => 'local',
                        'path' => $this->relativePath($localSource),
                    ],
                ],
            );
        }

        if (PackManifest::isValidName($source)) {
            return $this->installFromRegistry($source, null, $context);
        }

        if (preg_match('/^([^@]+)@([^@]+)$/', $source, $matches) === 1) {
            $name = trim((string) ($matches[1] ?? ''));
            $version = trim((string) ($matches[2] ?? ''));

            if (!PackManifest::isValidName($name) || !PackManifest::isValidVersion($version)) {
                throw new FoundryError(
                    'PACK_REFERENCE_INVALID',
                    'validation',
                    ['source' => $source],
                    'Pack reference must use vendor/pack or vendor/pack@1.2.3 format.',
                );
            }

            return $this->installFromRegistry($name, $version, $context);
        }

        $this->resolveLocalSource($source);

        throw new FoundryError(
            'PACK_SOURCE_MISSING',
            'not_found',
            ['source' => $source],
            'Pack source directory was not found.',
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function search(string $query): array
    {
        return [
            'query' => trim($query),
            'registry_url' => $this->hostedRegistry->registryUrl(),
            'cache_path' => $this->relativePath($this->hostedRegistry->cachePath()),
            'packs' => $this->hostedRegistry->search($query),
        ];
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    private function installResolvedSource(string $resolved, ?CommandContext $context, array $metadata = []): array
    {
        $manifest = PackManifest::fromFile($resolved . '/foundry.json');
        $this->assertChecksumMatches($resolved, $manifest);
        $target = $this->registry->installPath($manifest->name, $manifest->version);

        if (is_dir($target)) {
            throw new FoundryError(
                'PACK_VERSION_ALREADY_INSTALLED',
                'conflict',
                [
                    'pack' => $manifest->name,
                    'version' => $manifest->version,
                    'path' => $target,
                ],
                'That pack version is already installed.',
            );
        }

        $this->copyDirectory($resolved, $target);
        $packSource = is_array($metadata['source'] ?? null) ? $metadata['source'] : null;
        $this->registry->activate($manifest, $packSource);

        $result = [
            'pack' => $manifest->name,
            'version' => $manifest->version,
            'install_path' => $this->relativePath($target),
            'active' => true,
            'manifest' => $manifest->toArray(),
            'checksum' => $manifest->checksum,
        ] + $metadata;

        if ($context !== null) {
            $compile = $context->graphCompiler()->compile(new CompileOptions(emit: true));
            $result['graph_refresh'] = $compile->toArray();

            if ($compile->diagnostics->hasErrors()) {
                throw new FoundryError(
                    'PACK_ACTIVATION_FAILED',
                    'extensions',
                    $result,
                    'Pack installed, but activation failed during graph rebuild.',
                );
            }
        }

        return $result;
    }

    /**
     * @return array<string,mixed>
     */
    public function remove(string $name, ?CommandContext $context = null): array
    {
        $entry = $this->registry->entry($name);
        if ($entry === null) {
            throw new FoundryError(
                'PACK_NOT_INSTALLED',
                'not_found',
                ['pack' => $name],
                'Pack is not installed.',
            );
        }

        $this->registry->deactivate($name);

        $result = [
            'pack' => $name,
            'active' => false,
            'active_version' => null,
            'installed_versions' => $entry['installed_versions'],
            'source_kind' => $this->sourceKind(
                is_array($entry['sources'] ?? null) ? $entry['sources'] : [],
                $this->latestInstalledVersion($entry['installed_versions']),
            ),
        ];

        if ($context !== null) {
            $compile = $context->graphCompiler()->compile(new CompileOptions(emit: true));
            $result['graph_refresh'] = $compile->toArray();

            if ($compile->diagnostics->hasErrors()) {
                throw new FoundryError(
                    'PACK_DEACTIVATION_FAILED',
                    'extensions',
                    $result,
                    'Pack was deactivated, but the graph rebuild failed.',
                );
            }
        }

        return $result;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function list(): array
    {
        $rows = [];
        foreach ($this->registry->read() as $name => $entry) {
            $rows[] = [
                'name' => $name,
                'active_version' => $entry['active_version'],
                'installed_versions' => $entry['installed_versions'],
                'active' => $entry['active_version'] !== null,
                'source_kind' => $this->sourceKind(
                    is_array($entry['sources'] ?? null) ? $entry['sources'] : [],
                    $entry['active_version'] ?? $this->latestInstalledVersion($entry['installed_versions']),
                ),
            ];
        }

        usort(
            $rows,
            static fn(array $a, array $b): int => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')),
        );

        return $rows;
    }

    /**
     * @return array<string,mixed>
     */
    public function info(string $name, ?CommandContext $context = null): array
    {
        $entry = $this->registry->entry($name);
        if ($entry === null) {
            throw new FoundryError(
                'PACK_NOT_INSTALLED',
                'not_found',
                ['pack' => $name],
                'Pack is not installed.',
            );
        }

        $selectedVersion = $entry['active_version'] ?? $this->latestInstalledVersion($entry['installed_versions']);
        if ($selectedVersion === null) {
            throw new FoundryError(
                'PACK_VERSION_MISSING',
                'validation',
                ['pack' => $name],
                'Pack has no installed versions.',
            );
        }

        $manifest = PackManifest::fromFile($this->registry->manifestPath($name, $selectedVersion));
        $source = is_array($entry['sources'][$selectedVersion] ?? null) ? $entry['sources'][$selectedVersion] : null;

        $payload = [
            'name' => $manifest->name,
            'version' => $selectedVersion,
            'active' => $entry['active_version'] === $selectedVersion,
            'active_version' => $entry['active_version'],
            'installed_versions' => $entry['installed_versions'],
            'install_path' => $this->relativePath($this->registry->installPath($name, $selectedVersion)),
            'manifest' => $manifest->toArray(),
            'capabilities' => $manifest->capabilities,
            'source' => $source,
            'source_kind' => $this->normalizeSourceKind($source),
        ];

        if ($context !== null && $payload['active'] === true) {
            $payload['explain'] = $this->packExplainSummary($name, $context);
        }

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function installFromRegistry(string $name, ?string $version, ?CommandContext $context): array
    {
        $entry = $this->hostedRegistry->resolve($name, $version);
        $archiveBytes = $this->hostedRegistry->downloadArchive((string) ($entry['download_url'] ?? ''));
        $archivePath = $this->createTempFile('foundry-pack-archive-');
        $extractPath = $this->createTempDirectory('foundry-pack-src-');

        try {
            file_put_contents($archivePath, $archiveBytes);
            $this->archiveExtractor->extract($archivePath, $extractPath);

            $manifest = PackManifest::fromFile($extractPath . '/foundry.json');
            if ($manifest->name !== (string) ($entry['name'] ?? '') || $manifest->version !== (string) ($entry['version'] ?? '')) {
                throw new FoundryError(
                    'PACK_DOWNLOAD_MANIFEST_MISMATCH',
                    'validation',
                    [
                        'registry_entry' => $entry,
                        'manifest' => $manifest->toArray(),
                    ],
                    'Downloaded pack manifest does not match the hosted registry entry.',
                );
            }

            if ($manifest->checksum !== (string) ($entry['checksum'] ?? '')) {
                throw new FoundryError(
                    'PACK_DOWNLOAD_CHECKSUM_MISMATCH',
                    'validation',
                    [
                        'registry_entry' => $entry,
                        'manifest' => $manifest->toArray(),
                    ],
                    'Downloaded pack checksum does not match the hosted registry entry.',
                );
            }

            return $this->installResolvedSource(
                $extractPath,
                $context,
                [
                    'source' => [
                        'type' => 'registry',
                        'registry_url' => $this->hostedRegistry->registryUrl(),
                        'download_url' => (string) ($entry['download_url'] ?? ''),
                        'verified' => (bool) ($entry['verified'] ?? false),
                    ],
                ],
            );
        } finally {
            @unlink($archivePath);
            $this->deleteDirectory($extractPath);
        }
    }

    private function tryResolveExistingLocalSource(string $source): ?string
    {
        $candidate = str_starts_with($source, '/')
            ? $source
            : $this->paths->join($source);

        if (!is_dir($candidate)) {
            return null;
        }

        if (!is_file($candidate . '/foundry.json')) {
            throw new FoundryError(
                'PACK_MANIFEST_MISSING',
                'not_found',
                ['source' => $source, 'resolved_path' => $candidate],
                'Pack source is missing foundry.json.',
            );
        }

        return rtrim($candidate, '/');
    }

    private function resolveLocalSource(string $source): string
    {
        $candidate = str_starts_with($source, '/')
            ? $source
            : $this->paths->join($source);

        if (!is_dir($candidate)) {
            throw new FoundryError(
                'PACK_SOURCE_MISSING',
                'not_found',
                ['source' => $source, 'resolved_path' => $candidate],
                'Pack source directory was not found.',
            );
        }

        if (!is_file($candidate . '/foundry.json')) {
            throw new FoundryError(
                'PACK_MANIFEST_MISSING',
                'not_found',
                ['source' => $source, 'resolved_path' => $candidate],
                'Pack source is missing foundry.json.',
            );
        }

        return rtrim($candidate, '/');
    }

    /**
     * @param array<int,string> $versions
     */
    private function latestInstalledVersion(array $versions): ?string
    {
        $versions = array_values(array_filter(array_map('strval', $versions), static fn(string $value): bool => $value !== ''));
        if ($versions === []) {
            return null;
        }

        usort($versions, 'version_compare');

        return $versions[count($versions) - 1] ?? null;
    }

    private function assertChecksumMatches(string $directory, PackManifest $manifest): void
    {
        $actual = PackChecksum::forDirectory($directory);
        if ($actual === $manifest->checksum) {
            return;
        }

        throw new FoundryError(
            'PACK_CHECKSUM_MISMATCH',
            'validation',
            [
                'pack' => $manifest->name,
                'version' => $manifest->version,
                'path' => $directory,
                'expected_checksum' => $manifest->checksum,
                'actual_checksum' => $actual,
            ],
            'Pack checksum does not match the manifest.',
        );
    }

    /**
     * @param array<string,mixed>|null $source
     */
    private function normalizeSourceKind(?array $source): string
    {
        $type = trim((string) ($source['type'] ?? ''));

        return $type === 'registry' ? 'remote' : 'local';
    }

    /**
     * @param array<string,array<string,mixed>> $sources
     */
    private function sourceKind(array $sources, ?string $version): string
    {
        if ($version === null) {
            return 'local';
        }

        $source = is_array($sources[$version] ?? null) ? $sources[$version] : null;

        return $this->normalizeSourceKind($source);
    }

    /**
     * @return array<string,mixed>
     */
    private function packExplainSummary(string $name, CommandContext $context): array
    {
        $compiler = $context->graphCompiler();
        $graph = $compiler->loadGraph() ?? $compiler->compile(new CompileOptions())->graph;
        $response = (new ArchitectureExplainer(
            paths: $context->paths(),
            impactAnalyzer: $compiler->impactAnalyzer(),
            apiSurfaceRegistry: $context->apiSurfaceRegistry(),
            extensionRows: $context->extensionRegistry()->inspectRows(),
        ))->explain($graph, ExplainTarget::parse('pack:' . $name), new ExplainOptions());
        $payload = $response->toArray();

        return [
            'subject' => is_array($payload['subject'] ?? null) ? $payload['subject'] : [],
            'summary' => is_array($payload['summary'] ?? null) ? $payload['summary'] : [],
            'responsibilities' => is_array($payload['responsibilities'] ?? null) ? $payload['responsibilities'] : [],
            'impact' => is_array($payload['impact'] ?? null) ? $payload['impact'] : [],
            'commands' => is_array($payload['commands'] ?? null) ? $payload['commands'] : [],
            'schemas' => is_array($payload['schemas'] ?? null) ? $payload['schemas'] : [],
            'extensions' => is_array($payload['extensions'] ?? null) ? $payload['extensions'] : [],
        ];
    }

    private function copyDirectory(string $source, string $target): void
    {
        if (is_dir($target)) {
            throw new FoundryError(
                'PACK_TARGET_EXISTS',
                'conflict',
                ['path' => $target],
                'Pack target path already exists.',
            );
        }

        mkdir($target, 0777, true);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof \SplFileInfo) {
                continue;
            }

            $pathname = $fileInfo->getPathname();
            $relative = substr($pathname, strlen(rtrim($source, '/') . '/'));
            $destination = $target . '/' . $relative;

            if ($fileInfo->isDir()) {
                if (!is_dir($destination)) {
                    mkdir($destination, 0777, true);
                }
                continue;
            }

            $directory = dirname($destination);
            if (!is_dir($directory)) {
                mkdir($directory, 0777, true);
            }

            copy($pathname, $destination);
        }
    }

    private function createTempFile(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if (!is_string($path) || $path === '') {
            throw new FoundryError(
                'PACK_TEMP_PATH_UNAVAILABLE',
                'io',
                [],
                'Temporary pack path could not be created.',
            );
        }

        return $path;
    }

    private function createTempDirectory(string $prefix): string
    {
        $path = $this->createTempFile($prefix);
        @unlink($path);

        if (!mkdir($path, 0777, true) && !is_dir($path)) {
            throw new FoundryError(
                'PACK_TEMP_PATH_UNAVAILABLE',
                'io',
                ['path' => $path],
                'Temporary pack directory could not be created.',
            );
        }

        return $path;
    }

    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path . '/' . $item;
            if (is_dir($child)) {
                $this->deleteDirectory($child);
            } else {
                @unlink($child);
            }
        }

        @rmdir($path);
    }

    private function relativePath(string $absolute): string
    {
        $root = rtrim($this->paths->root(), '/') . '/';

        return str_starts_with($absolute, $root)
            ? substr($absolute, strlen($root))
            : $absolute;
    }
}
