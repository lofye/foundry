<?php
declare(strict_types=1);

namespace Forge\Cache;

final class CacheDefinition
{
    /**
     * @param array<int,string> $invalidatedBy
     */
    public function __construct(
        public readonly string $key,
        public readonly string $kind,
        public readonly int $ttlSeconds,
        public readonly array $invalidatedBy = [],
    ) {
    }
}
