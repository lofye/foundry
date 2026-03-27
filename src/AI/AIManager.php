<?php

declare(strict_types=1);

namespace Foundry\AI;

use Foundry\Observability\TraceRecorder;
use Foundry\Schema\JsonSchemaValidator;
use Foundry\Support\FoundryError;

final class AIManager
{
    /**
     * @param array<string,AIProvider> $providers
     */
    public function __construct(
        private readonly array $providers,
        private readonly ?AIResultCache $cache = null,
        private readonly ?TraceRecorder $traceRecorder = null,
    ) {}

    public function complete(AIRequest $request): AIResponse
    {
        $provider = $this->providers[$request->provider] ?? null;
        if ($provider === null) {
            throw new FoundryError('AI_PROVIDER_NOT_FOUND', 'not_found', ['provider' => $request->provider], 'AI provider not found.');
        }

        $cacheKey = $this->cache?->keyFor($request);
        if ($request->cacheable && $cacheKey !== null) {
            $cached = $this->cache?->get($cacheKey);
            if ($cached !== null) {
                $response = new AIResponse(
                    provider: $cached->provider,
                    model: $cached->model,
                    content: $cached->content,
                    parsed: $cached->parsed,
                    inputTokens: $cached->inputTokens,
                    outputTokens: $cached->outputTokens,
                    costEstimate: $cached->costEstimate,
                    cacheHit: true,
                    metadata: $cached->metadata,
                );
                $this->trace($response, 'cache_hit');

                return $response;
            }
        }

        $response = $provider->complete($request);
        $this->assertResponseSchema($request, $response);

        if ($request->cacheable && $cacheKey !== null && $request->cacheTtlSeconds !== null && $request->cacheTtlSeconds > 0) {
            $this->cache?->put($cacheKey, $response, $request->cacheTtlSeconds);
        }

        $this->trace($response, 'provider_call');

        return $response;
    }

    private function assertResponseSchema(AIRequest $request, AIResponse $response): void
    {
        if ($request->responseSchema === null) {
            return;
        }

        if ($response->parsed === []) {
            throw new FoundryError('AI_RESPONSE_PARSE_REQUIRED', 'validation', [], 'Response schema provided but parsed response is empty.');
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'foundry-ai-schema-');
        if ($tmpFile === false) {
            throw new FoundryError('AI_SCHEMA_TEMPFILE_FAILED', 'runtime', [], 'Failed to create AI schema tempfile.');
        }

        file_put_contents($tmpFile, json_encode($request->responseSchema, JSON_UNESCAPED_SLASHES));
        $validator = new JsonSchemaValidator();
        $result = $validator->validate($response->parsed, $tmpFile);
        @unlink($tmpFile);

        if (!$result->isValid) {
            throw new FoundryError('AI_RESPONSE_SCHEMA_VIOLATION', 'validation', ['errors' => array_map(static fn($e): array => $e->toArray(), $result->errors)], 'AI response schema violation.');
        }
    }

    private function trace(AIResponse $response, string $action): void
    {
        $this->traceRecorder?->record(
            'ai',
            'ai',
            $action,
            [
                'provider' => $response->provider,
                'model' => $response->model,
                'input_tokens' => $response->inputTokens,
                'output_tokens' => $response->outputTokens,
                'cost_estimate' => $response->costEstimate,
                'cache_hit' => $response->cacheHit,
            ],
        );
    }
}
