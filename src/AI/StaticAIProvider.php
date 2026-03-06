<?php
declare(strict_types=1);

namespace Forge\AI;

final class StaticAIProvider implements AIProvider
{
    /**
     * @param array<string,mixed> $response
     */
    public function __construct(
        private readonly string $providerName,
        private readonly array $response,
    ) {
    }

    public function name(): string
    {
        return $this->providerName;
    }

    public function complete(AIRequest $request): AIResponse
    {
        return new AIResponse(
            provider: $this->providerName,
            model: $request->model,
            content: (string) ($this->response['content'] ?? ''),
            parsed: is_array($this->response['parsed'] ?? null) ? $this->response['parsed'] : [],
            inputTokens: (int) ($this->response['input_tokens'] ?? 0),
            outputTokens: (int) ($this->response['output_tokens'] ?? 0),
            costEstimate: (float) ($this->response['cost_estimate'] ?? 0.0),
            cacheHit: false,
            metadata: is_array($this->response['metadata'] ?? null) ? $this->response['metadata'] : [],
        );
    }
}
