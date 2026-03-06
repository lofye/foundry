<?php
declare(strict_types=1);

namespace Forge\DB;

final class QueryTrace
{
    /**
     * @param array<string,mixed> $params
     */
    public function __construct(
        public readonly string $feature,
        public readonly string $query,
        public readonly array $params,
        public readonly float $durationMs,
        public readonly int $rows,
    ) {
    }
}
