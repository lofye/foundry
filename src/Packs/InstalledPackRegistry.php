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
     * @return array<string,array{active_version:?string,installed_versions:array<int,string>,sources:array<string,array<string,mixed>>}>
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
     * @param array<string,array{active_version:?string,installed_versions:array<int,string>,sources?:array<string,array<string,mixed>>}> $registry
     */
    public function write(array $registry): void
    {
        $normalized = $this->normalizeRegistry($registry, $this->registryPath());
        $directory = dirname($this->registryPath());
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $temporaryPath = $this->registryPath() . '.tmp';
        file_put_contents($temporaryPath, Json::encode($normalized, true));

        if (!rename($temporaryPath, $this->registryPath())) {
            @unlink($temporaryPath);

            throw new FoundryError(
                'PACK_REGISTRY_WRITE_FAILED',
                'io',
                ['path' => $this->registryPath()],
                'Installed pack registry could not be written.',
            );
        }
    }

    /**
     * @return array{active_version:?string,installed_versions:array<int,string>,sources:array<string,array<string,mixed>>}|null
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

    /**
     * @param array<string,mixed>|null $source
     */
    public function activate(PackManifest $manifest, ?array $source = null): void
    {
        $registry = $this->read();
        $entry = $registry[$manifest->name] ?? [
            'active_version' => null,
            'installed_versions' => [],
            'sources' => [],
        ];

        $versions = array_values(array_unique(array_merge(
            array_values(array_map('strval', $entry['installed_versions'] ?? [])),
            [$manifest->version],
        )));
        usort($versions, 'version_compare');

        $sources = is_array($entry['sources'] ?? null) ? $entry['sources'] : [];
        if (is_array($source) && $source !== []) {
            $sources[$manifest->version] = $source;
        }

        $registry[$manifest->name] = [
            'active_version' => $manifest->version,
            'installed_versions' => $versions,
            'sources' => $sources,
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
     * @return array<string,array{active_version:?string,installed_versions:array<int,string>,sources:array<string,array<string,mixed>>}>
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
                'sources' => $this->normalizeSources($row['sources'] ?? [], $versions, $packName, $errors),
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
     * @param mixed $payload
     * @param array<int,string> $versions
     * @param array<string,string> $errors
     * @return array<string,array<string,mixed>>
     */
    private function normalizeSources(mixed $payload, array $versions, string $packName, array &$errors): array
    {
        if ($payload === [] || $payload === null) {
            return [];
        }

        if (!is_array($payload)) {
            $errors[$packName . '.sources'] = 'sources must be an object keyed by installed version.';

            return [];
        }

        $sources = [];
        foreach ($payload as $version => $source) {
            $version = trim((string) $version);
            if (!in_array($version, $versions, true)) {
                $errors[$packName . '.sources.' . $version] = 'sources keys must reference installed versions.';
                continue;
            }

            if (!is_array($source)) {
                $errors[$packName . '.sources.' . $version] = 'sources entries must be objects.';
                continue;
            }

            $type = trim((string) ($source['type'] ?? ''));
            if (!in_array($type, ['local', 'registry'], true)) {
                $errors[$packName . '.sources.' . $version . '.type'] = 'sources.type must be local or registry.';
                continue;
            }

            $normalized = ['type' => $type];
            foreach (['path', 'registry_url', 'download_url'] as $field) {
                $value = trim((string) ($source[$field] ?? ''));
                if ($value !== '') {
                    $normalized[$field] = $value;
                }
            }

            $sources[$version] = $normalized;
        }

        uksort($sources, 'version_compare');

        return $sources;
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
