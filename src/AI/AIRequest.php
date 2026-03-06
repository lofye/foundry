<?php
declare(strict_types=1);

namespace Forge\AI;

final class AIRequest
{
    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed>|null $responseSchema
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly string $prompt,
        public readonly array $input = [],
        public readonly ?array $responseSchema = null,
        public readonly ?int $maxTokens = null,
        public readonly ?float $temperature = null,
        public readonly bool $cacheable = false,
        public readonly ?int $cacheTtlSeconds = null,
        public readonly array $metadata = [],
    ) {
    }
}
