<?php

declare(strict_types=1);

namespace Foundry\Storage;

use Foundry\Support\FoundryError;

final class InMemoryStorageDriver implements StorageDriver
{
    /**
     * @var array<string,string>
     */
    private array $memory = [];

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
            throw new FoundryError('IN_MEMORY_OBJECT_NOT_FOUND', 'not_found', ['path' => $path], 'Object not found.');
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
