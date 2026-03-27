<?php

declare(strict_types=1);

namespace Foundry\Core;

final class Environment
{
    /**
     * @param array<string,string> $vars
     */
    public function __construct(
        private readonly string $name = 'production',
        private readonly bool $debug = false,
        private readonly array $vars = [],
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function isDebug(): bool
    {
        return $this->debug;
    }

    public function var(string $key, ?string $default = null): ?string
    {
        return $this->vars[$key] ?? $_ENV[$key] ?? $default;
    }
}
