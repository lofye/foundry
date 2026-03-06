<?php
declare(strict_types=1);

namespace Forge\Http;

final class Route
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly string $feature,
        public readonly string $kind,
        public readonly string $inputSchema,
        public readonly string $outputSchema,
    ) {
    }

    public function key(): string
    {
        return strtoupper($this->method) . ' ' . $this->path;
    }
}
