<?php
declare(strict_types=1);

namespace Forge\Cache;

use Forge\Support\ForgeError;

final class CacheRegistry
{
    /**
     * @var array<string,CacheDefinition>
     */
    private array $entries = [];

    public function register(CacheDefinition $definition): void
    {
        $this->entries[$definition->key] = $definition;
    }

    public function has(string $key): bool
    {
        return isset($this->entries[$key]);
    }

    public function get(string $key): CacheDefinition
    {
        if (!isset($this->entries[$key])) {
            throw new ForgeError('CACHE_ENTRY_NOT_FOUND', 'not_found', ['key' => $key], 'Cache entry not found.');
        }

        return $this->entries[$key];
    }

    /**
     * @return array<string,CacheDefinition>
     */
    public function all(): array
    {
        ksort($this->entries);

        return $this->entries;
    }

    /**
     * @return array<int,CacheDefinition>
     */
    public function invalidatedBy(string $feature): array
    {
        return array_values(
            array_filter(
                $this->entries,
                static fn (CacheDefinition $entry): bool => in_array($feature, $entry->invalidatedBy, true)
            )
        );
    }
}
