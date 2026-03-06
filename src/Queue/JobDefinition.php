<?php
declare(strict_types=1);

namespace Forge\Queue;

final class JobDefinition
{
    /**
     * @param array<string,mixed> $inputSchema
     */
    public function __construct(
        public readonly string $name,
        public readonly array $inputSchema,
        public readonly string $queue,
        public readonly RetryPolicy $retry,
        public readonly int $timeoutSeconds,
        public readonly ?string $idempotencyKey = null,
    ) {
    }
}
