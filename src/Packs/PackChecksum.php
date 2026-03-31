<?php

declare(strict_types=1);

namespace Foundry\Packs;

use Foundry\Support\FoundryError;
use Foundry\Support\Json;

final class PackChecksum
{
    public static function forDirectory(string $directory): string
    {
        if (!is_dir($directory)) {
            throw new FoundryError(
                'PACK_SOURCE_MISSING',
                'not_found',
                ['path' => $directory],
                'Pack source directory was not found.',
            );
        }

        $manifestPath = rtrim($directory, '/') . '/foundry.json';
        if (!is_file($manifestPath)) {
            throw new FoundryError(
                'PACK_MANIFEST_MISSING',
                'not_found',
                ['path' => $manifestPath],
                'Pack manifest not found.',
            );
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof \SplFileInfo || !$fileInfo->isFile()) {
                continue;
            }

            $relative = substr($fileInfo->getPathname(), strlen(rtrim($directory, '/') . '/'));
            if (!is_string($relative) || $relative === '') {
                continue;
            }

            $files[] = str_replace('\\', '/', $relative);
        }

        sort($files);

        $buffer = '';
        foreach ($files as $relative) {
            $buffer .= $relative . "\n" . self::hashFile($directory . '/' . $relative) . "\n";
        }

        return hash('sha256', $buffer);
    }

    private static function hashFile(string $path): string
    {
        if (basename($path) !== 'foundry.json') {
            $hash = hash_file('sha256', $path);
            if ($hash === false) {
                throw new FoundryError(
                    'PACK_CHECKSUM_UNAVAILABLE',
                    'io',
                    ['path' => $path],
                    'Pack checksum could not be computed.',
                );
            }

            return $hash;
        }

        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new FoundryError(
                'PACK_CHECKSUM_UNAVAILABLE',
                'io',
                ['path' => $path],
                'Pack checksum could not be computed.',
            );
        }

        $manifest = Json::decodeAssoc($contents);
        unset($manifest['checksum'], $manifest['signature']);

        return hash('sha256', Json::encode(self::sortRecursive($manifest)));
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private static function sortRecursive(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value) && !array_is_list($value)) {
                $payload[$key] = self::sortRecursive($value);
            }
        }

        ksort($payload);

        return $payload;
    }
}
