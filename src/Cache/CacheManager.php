<?php
declare(strict_types=1);

namespace Forge\Cache;

final class CacheManager
{
    public function __construct(
        private readonly CacheStore $store,
        private readonly CacheRegistry $registry,
        private readonly CacheKeyBuilder $keys = new CacheKeyBuilder(),
    ) {
    }

    /**
     * @param array<string,mixed> $params
     */
    public function get(string $keyTemplate, array $params = []): mixed
    {
        $key = $this->keys->build($keyTemplate, $params);

        return $this->store->get($key);
    }

    /**
     * @param array<string,mixed> $params
     */
    public function put(string $keyTemplate, mixed $value, array $params = [], ?int $ttl = null): void
    {
        $key = $this->keys->build($keyTemplate, $params);
        $ttl ??= $this->registry->has($keyTemplate) ? $this->registry->get($keyTemplate)->ttlSeconds : 60;
        $this->store->put($key, $value, $ttl);
    }

    /**
     * @param array<string,mixed> $params
     */
    public function forget(string $keyTemplate, array $params = []): void
    {
        $key = $this->keys->build($keyTemplate, $params);
        $this->store->forget($key);
    }

    public function invalidateByFeature(string $feature): void
    {
        foreach ($this->registry->invalidatedBy($feature) as $entry) {
            if (!str_contains($entry->key, '{')) {
                $this->store->forget($entry->key);
            }
        }
    }
}
