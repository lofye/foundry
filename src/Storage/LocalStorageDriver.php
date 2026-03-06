<?php
declare(strict_types=1);

namespace Forge\Storage;

use Forge\Support\ForgeError;

final class LocalStorageDriver implements StorageDriver
{
    public function __construct(private readonly string $root)
    {
    }

    public function write(string $path, string $content): FileDescriptor
    {
        $fullPath = $this->fullPath($path);
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $bytes = file_put_contents($fullPath, $content);
        if ($bytes === false) {
            throw new ForgeError('STORAGE_WRITE_FAILED', 'io', ['path' => $path], 'Failed to write file.');
        }

        return new FileDescriptor($path, $bytes, 'application/octet-stream');
    }

    public function read(string $path): string
    {
        $fullPath = $this->fullPath($path);
        $content = file_get_contents($fullPath);
        if ($content === false) {
            throw new ForgeError('STORAGE_READ_FAILED', 'io', ['path' => $path], 'Failed to read file.');
        }

        return $content;
    }

    public function exists(string $path): bool
    {
        return is_file($this->fullPath($path));
    }

    public function delete(string $path): void
    {
        $fullPath = $this->fullPath($path);
        if (is_file($fullPath)) {
            unlink($fullPath);
        }
    }

    private function fullPath(string $path): string
    {
        return rtrim($this->root, '/') . '/' . ltrim($path, '/');
    }
}
