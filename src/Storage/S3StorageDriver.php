<?php

declare(strict_types=1);

namespace Foundry\Storage;

use Foundry\Support\FoundryError;

final class S3StorageDriver implements StorageDriver
{
    /**
     * @var array<string,string>
     */
    private array $memory = [];

    public function __construct(private readonly string $bucket) {}

    #[\Override]
    public function write(string $path, string $content): FileDescriptor
    {
        $this->memory[$path] = $content;

        return new FileDescriptor($path, strlen($content), 'application/octet-stream');
    }

    #[\Override]
    public function read(string $path): string
    {
        if (!isset($this->memory[$path])) {
            throw new FoundryError('S3_OBJECT_NOT_FOUND', 'not_found', ['bucket' => $this->bucket, 'path' => $path], 'Object not found.');
        }

        return $this->memory[$path];
    }

    #[\Override]
    public function exists(string $path): bool
    {
        return isset($this->memory[$path]);
    }

    #[\Override]
    public function delete(string $path): void
    {
        unset($this->memory[$path]);
    }
}
