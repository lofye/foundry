<?php

declare(strict_types=1);

namespace Foundry\Explain;

use Foundry\Packs\PackManifest;

final class ExplainOrigin
{
    /**
     * @param array<string,mixed> $metadata
     * @return array{origin:string,extension:?string}
     */
    public static function subject(array $metadata, ?string $fallbackLabel = null): array
    {
        $source = self::rowSource($metadata, self::fallbackSource($fallbackLabel));

        return [
            'origin' => $source['type'] === 'extension' ? 'extension' : 'core',
            'extension' => $source['type'] === 'extension'
                ? trim((string) ($source['name'] ?? '')) ?: null
                : null,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed>|null $fallback
     * @return array{type:string,name?:string}
     */
    public static function rowSource(array $row, ?array $fallback = null): array
    {
        $structured = $row['source'] ?? null;
        if (is_array($structured)) {
            return self::normalizeContributionSource($structured, $fallback);
        }

        $packName = self::packNameFromRow($row);
        if ($packName !== null) {
            return ['type' => 'extension', 'name' => $packName];
        }

        $extension = trim((string) ($row['extension'] ?? ''));
        if ($extension !== '' && strtolower($extension) !== 'core') {
            return ['type' => 'extension', 'name' => $extension];
        }

        foreach (self::sourcePathCandidates($row) as $path) {
            $pack = self::packNameFromSourcePath($path);
            if ($pack !== null) {
                return ['type' => 'extension', 'name' => $pack];
            }
        }

        return self::normalizeContributionSource($fallback ?? ['type' => 'core'], null);
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed>|null $fallback
     * @return array<string,mixed>
     */
    public static function applyToRow(array $row, ?array $fallback = null): array
    {
        if ($row === []) {
            return $row;
        }

        $source = self::rowSource($row, $fallback);
        $row['source'] = $source;
        $row['origin'] = $source['type'] === 'extension' ? 'extension' : 'core';

        if (($row['origin'] ?? 'core') === 'extension' && !isset($row['extension'])) {
            $name = trim((string) ($source['name'] ?? ''));
            if ($name !== '') {
                $row['extension'] = $name;
            }
        }

        return $row;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    public static function sortAttributedRows(array $rows): array
    {
        usort($rows, static function (array $left, array $right): int {
            $leftSource = self::rowSource($left);
            $rightSource = self::rowSource($right);
            $leftPriority = $leftSource['type'] === 'extension' ? 1 : 0;
            $rightPriority = $rightSource['type'] === 'extension' ? 1 : 0;

            return ($leftPriority <=> $rightPriority)
                ?: strcmp((string) ($leftSource['name'] ?? ''), (string) ($rightSource['name'] ?? ''))
                ?: strcmp((string) ($left['kind'] ?? ''), (string) ($right['kind'] ?? ''))
                ?: strcmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''))
                ?: strcmp((string) ($left['id'] ?? ''), (string) ($right['id'] ?? ''));
        });

        return array_values($rows);
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function installSource(array $row): string
    {
        $source = is_array($row['pack_source'] ?? null) ? $row['pack_source'] : [];
        $type = trim((string) ($source['type'] ?? ''));

        return $type === 'registry' ? 'marketplace' : 'local';
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function packNameFromRow(array $row): ?string
    {
        $manifest = $row['pack_manifest'] ?? null;
        if (is_array($manifest)) {
            $name = trim((string) ($manifest['name'] ?? ''));
            if (PackManifest::isValidName($name)) {
                return $name;
            }
        }

        $pack = trim((string) ($row['pack'] ?? ''));
        if (PackManifest::isValidName($pack)) {
            return $pack;
        }

        $name = trim((string) ($row['name'] ?? ''));
        if (PackManifest::isValidName($name)) {
            return $name;
        }

        $packs = array_values(array_filter(array_map('strval', (array) ($row['packs'] ?? []))));
        if (count($packs) === 1 && PackManifest::isValidName($packs[0])) {
            return $packs[0];
        }

        foreach (self::sourcePathCandidates($row) as $path) {
            $candidate = self::packNameFromSourcePath($path);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    public static function packNameFromSourcePath(string $path): ?string
    {
        $normalized = str_replace('\\', '/', trim($path));
        if ($normalized === '') {
            return null;
        }

        if (preg_match('#(?:^|/)\.foundry/packs/([^/]+)/([^/]+)/[^/]+(?:/|$)#', $normalized, $matches) === 1) {
            $name = trim((string) ($matches[1] ?? '')) . '/' . trim((string) ($matches[2] ?? ''));

            return PackManifest::isValidName($name) ? $name : null;
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private static function fallbackSource(?string $label): array
    {
        $label = trim((string) $label);
        if (PackManifest::isValidName($label)) {
            return ['type' => 'extension', 'name' => $label];
        }

        return ['type' => 'core'];
    }

    /**
     * @param array<string,mixed> $source
     * @param array<string,mixed>|null $fallback
     * @return array{type:string,name?:string}
     */
    private static function normalizeContributionSource(array $source, ?array $fallback): array
    {
        $type = strtolower(trim((string) ($source['type'] ?? '')));
        if (in_array($type, ['extension', 'pack'], true)) {
            $name = trim((string) ($source['name'] ?? $source['pack'] ?? ''));
            if ($name !== '') {
                return ['type' => 'extension', 'name' => $name];
            }
        }

        if ($type === 'core') {
            return ['type' => 'core'];
        }

        if ($fallback !== null) {
            return self::normalizeContributionSource($fallback, null);
        }

        return ['type' => 'core'];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<int,string>
     */
    private static function sourcePathCandidates(array $row): array
    {
        $paths = [];

        foreach ([
            $row['source_path'] ?? null,
            $row['path'] ?? null,
            is_array($row['manifest'] ?? null) ? ($row['manifest']['path'] ?? null) : null,
            is_array($row['pack_manifest'] ?? null) ? ($row['pack_manifest']['path'] ?? null) : null,
        ] as $candidate) {
            $path = trim((string) $candidate);
            if ($path !== '') {
                $paths[] = $path;
            }
        }

        return array_values(array_unique($paths));
    }
}
