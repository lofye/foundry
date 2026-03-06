<?php
declare(strict_types=1);

namespace Forge\AI;

final class AITrace
{
    /**
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly float $costEstimate,
        public readonly bool $cacheHit,
        public readonly array $metadata = [],
    ) {
    }
}
