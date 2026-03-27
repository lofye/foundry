<?php

declare(strict_types=1);

namespace Foundry\Schema;

final class Schema
{
    /**
     * @param array<string,mixed> $definition
     */
    public function __construct(
        public readonly string $path,
        public readonly array $definition,
    ) {}
}
