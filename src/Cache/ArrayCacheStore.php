<?php
declare(strict_types=1);

namespace Foundry\Cache;

final class ArrayCacheStore implements CacheStore
{
    /**
     * @var array<string,array{value:mixed,expires_at:int}>
     */
    private array $items = [];

    #[\Override]
    public function get(string $key): mixed
    {
        if (!$this->has($key)) {
            return null;
        }

        return $this->items[$key]['value'];
    }

    #[\Override]
    public function put(string $key, mixed $value, int $ttlSeconds): void
    {
        $this->items[$key] = [
            'value' => $value,
            'expires_at' => time() + $ttlSeconds,
        ];
    }

    #[\Override]
    public function forget(string $key): void
    {
        unset($this->items[$key]);
    }

    #[\Override]
    public function has(string $key): bool
    {
        if (!isset($this->items[$key])) {
            return false;
        }

        if ($this->items[$key]['expires_at'] < time()) {
            unset($this->items[$key]);

            return false;
        }

        return true;
    }
}
