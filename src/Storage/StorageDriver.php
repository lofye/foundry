<?php

declare(strict_types=1);

namespace Foundry\Storage;

interface StorageDriver
{
    public function write(string $path, string $content): FileDescriptor;

    public function read(string $path): string;

    public function exists(string $path): bool;

    public function delete(string $path): void;
}
