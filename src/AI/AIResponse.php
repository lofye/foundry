<?php
declare(strict_types=1);

namespace Forge\AI;

final class AIResponse
{
    /**
     * @param array<string,mixed> $parsed
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly string $content,
        public readonly array $parsed = [],
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly float $costEstimate = 0.0,
        public readonly bool $cacheHit = false,
        public readonly array $metadata = [],
    ) {
    }
}
