<?php

declare(strict_types=1);

namespace Foundry\Schema;

use Foundry\Support\FoundryError;

final class SchemaRegistry
{
    /**
     * @var array<string,Schema>
     */
    private array $schemas = [];

    public function register(Schema $schema): void
    {
        $this->schemas[$schema->path] = $schema;
    }

    public function has(string $path): bool
    {
        return isset($this->schemas[$path]);
    }

    public function get(string $path): Schema
    {
        if (!isset($this->schemas[$path])) {
            throw new FoundryError('SCHEMA_NOT_REGISTERED', 'not_found', ['schema' => $path], 'Schema not registered.');
        }

        return $this->schemas[$path];
    }

    /**
     * @return array<string,Schema>
     */
    public function all(): array
    {
        ksort($this->schemas);

        return $this->schemas;
    }
}
