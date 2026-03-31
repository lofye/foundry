<?php

declare(strict_types=1);

namespace Foundry\Packs;

use Foundry\Compiler\Extensions\CompilerExtension;
use Foundry\Support\FoundryError;
use Foundry\Support\Paths;

final class LocalPackLoader
{
    public function __construct(private readonly Paths $paths) {}

    /**
     * @return array{
     *   source_paths:array<int,string>,
     *   entries:array<int,array{extension:CompilerExtension,class:string,source_path:string}>,
     *   diagnostics:array<int,array<string,mixed>>,
     *   active_packs:array<int,array<string,mixed>>
     * }
     */
    public function load(): array
    {
        $registry = new InstalledPackRegistry($this->paths);
        $sourcePaths = [];
        $entries = [];
        $diagnostics = [];
        $activePacks = [];

        if (is_file($registry->registryPath())) {
            $sourcePaths[] = $this->relativePath($registry->registryPath());
        }

        try {
            $installed = $registry->read();
        } catch (FoundryError $error) {
            $diagnostics[] = $this->diagnostic(
                code: $error->errorCode,
                message: $error->getMessage(),
                sourcePath: $this->relativePath($registry->registryPath()),
                details: $error->details,
            );

            return [
                'source_paths' => $sourcePaths,
                'entries' => [],
                'diagnostics' => $diagnostics,
                'active_packs' => [],
            ];
        }

        $active = [];
        foreach ($installed as $name => $row) {
            $activeVersion = is_string($row['active_version'] ?? null) ? $row['active_version'] : null;
            if ($activeVersion === null || $activeVersion === '') {
                continue;
            }

            $active[] = ['name' => $name, 'version' => $activeVersion];
        }

        usort(
            $active,
            static fn(array $a, array $b): int => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''))
                ?: version_compare((string) ($a['version'] ?? '0.0.0'), (string) ($b['version'] ?? '0.0.0')),
        );

        $commandOwners = [];
        $schemaOwners = [];

        foreach ($active as $row) {
            $name = (string) ($row['name'] ?? '');
            $version = (string) ($row['version'] ?? '');
            $manifestPath = $registry->manifestPath($name, $version);
            $installPath = $registry->installPath($name, $version);

            if (!is_dir($installPath)) {
                $diagnostics[] = $this->diagnostic(
                    code: 'PACK_SOURCE_MISSING',
                    message: 'Installed pack files are missing.',
                    sourcePath: $this->relativePath($installPath),
                    pack: $name,
                    details: ['path' => $installPath, 'version' => $version],
                );
                continue;
            }

            try {
                $manifest = PackManifest::fromFile($manifestPath);
            } catch (FoundryError $error) {
                $diagnostics[] = $this->diagnostic(
                    code: $error->errorCode,
                    message: $error->getMessage(),
                    sourcePath: $this->relativePath($manifestPath),
                    pack: $name,
                    details: $error->details + ['version' => $version],
                );
                continue;
            }

            $sourcePaths[] = $this->relativePath($manifestPath);

            if ($manifest->name !== $name || $manifest->version !== $version) {
                $diagnostics[] = $this->diagnostic(
                    code: 'PACK_MANIFEST_MISMATCH',
                    message: 'Installed pack manifest does not match the active registry entry.',
                    sourcePath: $this->relativePath($manifestPath),
                    pack: $name,
                    details: [
                        'registry_name' => $name,
                        'registry_version' => $version,
                        'manifest' => $manifest->toArray(),
                    ],
                );
                continue;
            }

            try {
                [$extension, $context] = $this->activatePack($manifest, $installPath);
            } catch (FoundryError $error) {
                $diagnostics[] = $this->diagnostic(
                    code: $error->errorCode,
                    message: $error->getMessage(),
                    sourcePath: $this->relativePath($manifestPath),
                    pack: $name,
                    details: $error->details + ['version' => $version],
                );
                continue;
            }

            foreach ((array) ($context->contributions()['commands'] ?? []) as $command) {
                if (!isset($commandOwners[$command])) {
                    $commandOwners[$command] = $name;
                    continue;
                }

                $diagnostics[] = $this->diagnostic(
                    code: 'PACK_COMMAND_CONFLICT',
                    message: sprintf('Pack command %s is declared by both %s and %s.', $command, $commandOwners[$command], $name),
                    sourcePath: $this->relativePath($manifestPath),
                    pack: $name,
                    details: ['command' => $command, 'conflicts_with' => $commandOwners[$command]],
                );
            }

            foreach ((array) ($context->contributions()['schemas'] ?? []) as $schema) {
                if (!isset($schemaOwners[$schema])) {
                    $schemaOwners[$schema] = $name;
                    continue;
                }

                $diagnostics[] = $this->diagnostic(
                    code: 'PACK_SCHEMA_CONFLICT',
                    message: sprintf('Pack schema %s is declared by both %s and %s.', $schema, $schemaOwners[$schema], $name),
                    sourcePath: $this->relativePath($manifestPath),
                    pack: $name,
                    details: ['schema' => $schema, 'conflicts_with' => $schemaOwners[$schema]],
                );
            }

            $entries[] = [
                'extension' => $extension,
                'class' => $extension::class,
                'source_path' => $this->relativePath($manifestPath),
            ];
            $activePacks[] = [
                'name' => $manifest->name,
                'version' => $manifest->version,
                'install_path' => $this->relativePath($installPath),
                'manifest' => $manifest->toArray(),
                'declared_contributions' => $context->contributions(),
            ];
        }

        $sourcePaths = array_values(array_unique($sourcePaths));
        sort($sourcePaths);
        usort(
            $activePacks,
            static fn(array $a, array $b): int => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''))
                ?: version_compare((string) ($a['version'] ?? '0.0.0'), (string) ($b['version'] ?? '0.0.0')),
        );

        return [
            'source_paths' => $sourcePaths,
            'entries' => $entries,
            'diagnostics' => $diagnostics,
            'active_packs' => $activePacks,
        ];
    }

    /**
     * @return array{0:CompilerExtension,1:PackContext}
     */
    private function activatePack(PackManifest $manifest, string $installPath): array
    {
        if (!class_exists($manifest->entry)) {
            $this->loadPhpFiles($installPath);
        }

        if (!class_exists($manifest->entry)) {
            throw new FoundryError(
                'PACK_ENTRY_CLASS_NOT_FOUND',
                'not_found',
                ['entry' => $manifest->entry, 'install_path' => $installPath],
                'Pack entry class was not found after loading the installed pack.',
            );
        }

        try {
            $provider = new ($manifest->entry)();
        } catch (\Throwable $error) {
            throw new FoundryError(
                'PACK_ENTRY_INSTANTIATION_FAILED',
                'runtime',
                [
                    'entry' => $manifest->entry,
                    'install_path' => $installPath,
                    'exception' => $error::class,
                ],
                'Pack entry class could not be instantiated.',
                0,
                $error,
            );
        }

        if (!$provider instanceof PackServiceProvider) {
            throw new FoundryError(
                'PACK_ENTRY_INVALID',
                'validation',
                ['entry' => $manifest->entry, 'install_path' => $installPath],
                'Pack entry class must implement Foundry\\Packs\\PackServiceProvider.',
            );
        }

        $context = new PackContext($manifest, $installPath);
        $provider->register($context);

        $extension = $context->extension();
        if ($extension === null && $provider instanceof CompilerExtension) {
            $context->registerExtension($provider);
            $extension = $provider;
        }

        return [new InstalledPackExtension($manifest, $context, $extension), $context];
    }

    private function loadPhpFiles(string $installPath): void
    {
        foreach ([$installPath . '/vendor/autoload.php', $installPath . '/autoload.php'] as $autoload) {
            if (is_file($autoload)) {
                require_once $autoload;
            }
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($installPath, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof \SplFileInfo || !$fileInfo->isFile()) {
                continue;
            }

            $path = $fileInfo->getPathname();
            if (pathinfo($path, PATHINFO_EXTENSION) !== 'php') {
                continue;
            }

            if (str_contains(str_replace('\\', '/', $path), '/vendor/')) {
                continue;
            }

            $files[] = $path;
        }

        sort($files);
        foreach ($files as $file) {
            require_once $file;
        }
    }

    /**
     * @param array<string,mixed> $details
     * @return array<string,mixed>
     */
    private function diagnostic(
        string $code,
        string $message,
        string $sourcePath,
        ?string $pack = null,
        array $details = [],
    ): array {
        return [
            'code' => $code,
            'severity' => 'error',
            'category' => 'extensions',
            'message' => $message,
            'source_path' => $sourcePath,
            'pack' => $pack,
            'details' => $details,
        ];
    }

    private function relativePath(string $absolute): string
    {
        $root = rtrim($this->paths->root(), '/') . '/';

        return str_starts_with($absolute, $root)
            ? substr($absolute, strlen($root))
            : $absolute;
    }
}
