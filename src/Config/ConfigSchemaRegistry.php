<?php
declare(strict_types=1);

namespace Foundry\Config;

final class ConfigSchemaRegistry
{
    /**
     * @var array<string,array<string,mixed>>
     */
    private array $schemas = [];

    /**
     * @param array<string,mixed> $schema
     */
    public function register(string $name, array $schema): void
    {
        $this->schemas[$name] = $schema;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function get(string $name): ?array
    {
        return $this->schemas[$name] ?? null;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function all(): array
    {
        ksort($this->schemas);

        return $this->schemas;
    }
}
