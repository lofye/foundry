<?php
declare(strict_types=1);

namespace Foundry\Storage;

final readonly class FileDescriptor
{
    public function __construct(
        public readonly string $path,
        public readonly int $size,
        public readonly ?string $mimeType = null,
    ) {
    }
}
