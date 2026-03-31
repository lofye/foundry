<?php

declare(strict_types=1);

namespace Foundry\Packs;

use Foundry\Support\FoundryError;

final class PackArchiveExtractor
{
    public function extract(string $archivePath, string $targetDirectory): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new FoundryError(
                'PACK_ARCHIVE_UNSUPPORTED',
                'runtime',
                [],
                'ZIP archive support is not available in this PHP runtime.',
            );
        }

        $zip = new \ZipArchive();
        $status = $zip->open($archivePath);
        if ($status !== true) {
            throw new FoundryError(
                'PACK_ARCHIVE_INVALID',
                'validation',
                ['archive' => $archivePath, 'status' => $status],
                'Pack archive could not be opened as a ZIP file.',
            );
        }

        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0777, true) && !is_dir($targetDirectory)) {
            $zip->close();

            throw new FoundryError(
                'PACK_ARCHIVE_EXTRACTION_FAILED',
                'io',
                ['target' => $targetDirectory],
                'Pack archive could not be extracted.',
            );
        }

        $foundManifest = false;
        $foundSourceDirectory = false;

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $rawName = $zip->getNameIndex($index);
                if (!is_string($rawName) || trim($rawName) === '') {
                    throw $this->archiveError($archivePath, 'Pack archive contains an invalid entry name.');
                }

                $normalized = $this->normalizeEntryName($archivePath, $rawName);
                if ($normalized === 'foundry.json') {
                    $foundManifest = true;
                }

                if ($normalized === 'src' || str_starts_with($normalized, 'src/')) {
                    $foundSourceDirectory = true;
                }

                if (str_ends_with($rawName, '/')) {
                    if (!is_dir($targetDirectory . '/' . $normalized)) {
                        mkdir($targetDirectory . '/' . $normalized, 0777, true);
                    }
                    continue;
                }

                $directory = dirname($targetDirectory . '/' . $normalized);
                if (!is_dir($directory)) {
                    mkdir($directory, 0777, true);
                }

                $input = $zip->getStream($rawName);
                if (!is_resource($input)) {
                    throw $this->archiveError($archivePath, 'Pack archive entry could not be read.', ['entry' => $rawName]);
                }

                $output = fopen($targetDirectory . '/' . $normalized, 'wb');
                if (!is_resource($output)) {
                    fclose($input);

                    throw new FoundryError(
                        'PACK_ARCHIVE_EXTRACTION_FAILED',
                        'io',
                        ['target' => $targetDirectory . '/' . $normalized],
                        'Pack archive could not be extracted.',
                    );
                }

                stream_copy_to_stream($input, $output);
                fclose($input);
                fclose($output);
            }
        } finally {
            $zip->close();
        }

        if (
            !$foundManifest
            || !is_file($targetDirectory . '/foundry.json')
            || !$foundSourceDirectory
            || !is_dir($targetDirectory . '/src')
        ) {
            throw $this->archiveError(
                $archivePath,
                'Pack archive must contain foundry.json and src/ at the archive root.',
            );
        }
    }

    /**
     * @param array<string,mixed> $details
     */
    private function archiveError(string $archivePath, string $message, array $details = []): FoundryError
    {
        return new FoundryError(
            'PACK_ARCHIVE_INVALID',
            'validation',
            ['archive' => $archivePath] + $details,
            $message,
        );
    }

    private function normalizeEntryName(string $archivePath, string $value): string
    {
        $normalized = str_replace('\\', '/', trim($value));
        if ($normalized === '' || str_starts_with($normalized, '/')) {
            throw $this->archiveError($archivePath, 'Pack archive contains an unsafe path.', ['entry' => $value]);
        }

        $trimmed = rtrim($normalized, '/');
        $parts = explode('/', $trimmed);

        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw $this->archiveError($archivePath, 'Pack archive contains an unsafe path.', ['entry' => $value]);
            }
        }

        return $trimmed;
    }
}
