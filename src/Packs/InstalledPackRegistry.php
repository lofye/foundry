<?php

declare(strict_types=1);

namespace Foundry\Packs;

use Foundry\Support\FoundryError;
use Foundry\Support\Json;
use Foundry\Support\Paths;

final class InstalledPackRegistry
{
    public function __construct(private readonly Paths $paths) {}

    public function registryPath(): string
    {
        return $this->paths->join('.foundry/packs/installed.json');
    }

    public function storageRoot(): string
    {
        return $this->paths->join('.foundry/packs');
    }

    public function installPath(string $name, string $version): string
    {
        [$vendor, $pack] = $this->splitName($name);

        return $this->storageRoot() . '/' . $vendor . '/' . $pack . '/' . $version;
    }

    public function manifestPath(string $name, string $version): string
    {
        return $this->installPath($name, $version) . '/foundry.json';
    }

    /**
     * @return array<string,array{active_version:?string,installed_versions:array<int,string>}>
     */
    public function read(): array
    {
        $path = $this->registryPath();
        if (!is_file($path)) {
            return [];
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new FoundryError(
                'PACK_REGISTRY_UNREADABLE',
                'io',
                ['path' => $path],
                'Installed pack registry could not be read.',
            );
        }

        try {
            $payload = Json::decodeAssoc($content);
        } catch (FoundryError $error) {
            throw new FoundryError(
                'PACK_REGISTRY_CORRUPT',
                'parsing',
                ['path' => $path, 'error' => $error->errorCode],
                'Installed pack registry is corrupt.',
                0,
                $error,
            );
        }

        return $this->normalizeRegistry($payload, $path);
    }

    /**
     * @param array<string,array{active_version:?string,installed_versions:array<int,string>}> $registry
     */
    public function write(array $registry): void
    {
        $normalized = $this->normalizeRegistry($registry, $this->registryPath());
        $directory = dirname($this->registryPath());
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($this->registryPath(), Json::encode($normalized, true));
    }

    /**
     * @return array{active_version:?string,installed_versions:array<int,string>}|null
     */
    public function entry(string $name): ?array
    {
        $registry = $this->read();

        return $registry[$name] ?? null;
    }

    public function isInstalled(string $name): bool
    {
        return $this->entry($name) !== null;
    }

    public function activate(PackManifest $manifest): void
    {
        $registry = $this->read();
        $entry = $registry[$manifest->name] ?? [
            'active_version' => null,
            'installed_versions' => [],
        ];

        $versions = array_values(array_unique(array_merge(
            array_values(array_map('strval', $entry['installed_versions'] ?? [])),
            [$manifest->version],
        )));
        usort($versions, 'version_compare');

        $registry[$manifest->name] = [
            'active_version' => $manifest->version,
            'installed_versions' => $versions,
        ];

        $this->write($registry);
    }

    public function deactivate(string $name): void
    {
        $registry = $this->read();
        if (!isset($registry[$name])) {
            throw new FoundryError(
                'PACK_NOT_INSTALLED',
                'not_found',
                ['pack' => $name],
                'Pack is not installed.',
            );
        }

        $registry[$name]['active_version'] = null;
        $this->write($registry);
    }

    /**
     * @return array<string,array{active_version:?string,installed_versions:array<int,string>}>
     */
    private function normalizeRegistry(array $payload, string $path): array
    {
        $normalized = [];
        $errors = [];

        foreach ($payload as $name => $row) {
            $packName = trim((string) $name);
            if (!PackManifest::isValidName($packName)) {
                $errors[$packName !== '' ? $packName : '<unknown>'] = 'Pack registry keys must use vendor/pack-name format.';
                continue;
            }

            if (!is_array($row)) {
                $errors[$packName] = 'Pack registry entries must be objects.';
                continue;
            }

            $activeVersion = $row['active_version'] ?? null;
            if ($activeVersion !== null) {
                $activeVersion = trim((string) $activeVersion);
                if ($activeVersion === '') {
                    $activeVersion = null;
                }
            }

            $installedVersions = $row['installed_versions'] ?? null;
            if (!is_array($installedVersions)) {
                $errors[$packName] = 'installed_versions must be an array.';
                continue;
            }

            $versions = [];
            foreach ($installedVersions as $index => $version) {
                $candidate = trim((string) $version);
                if (!PackManifest::isValidVersion($candidate)) {
                    $errors[$packName . '.installed_versions.' . $index] = 'installed_versions must contain semantic versions.';
                    continue;
                }

                $versions[] = $candidate;
            }

            $versions = array_values(array_unique($versions));
            usort($versions, 'version_compare');

            if ($activeVersion !== null && !PackManifest::isValidVersion($activeVersion)) {
                $errors[$packName . '.active_version'] = 'active_version must be a semantic version or null.';
                continue;
            }

            if ($activeVersion !== null && !in_array($activeVersion, $versions, true)) {
                $errors[$packName . '.active_version'] = 'active_version must be listed in installed_versions.';
                continue;
            }

            $normalized[$packName] = [
                'active_version' => $activeVersion,
                'installed_versions' => $versions,
            ];
        }

        ksort($normalized);

        if ($errors !== []) {
            throw new FoundryError(
                'PACK_REGISTRY_INVALID',
                'validation',
                ['path' => $path, 'errors' => $errors],
                'Installed pack registry is invalid.',
            );
        }

        return $normalized;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitName(string $name): array
    {
        if (!PackManifest::isValidName($name)) {
            throw new FoundryError(
                'PACK_NAME_INVALID',
                'validation',
                ['pack' => $name],
                'Pack name must use vendor/pack-name format.',
            );
        }

        $parts = explode('/', $name, 2);

        return [$parts[0], $parts[1] ?? ''];
    }
}
