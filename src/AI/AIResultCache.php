<?php
declare(strict_types=1);

namespace Foundry\AI;

use Foundry\Cache\CacheManager;

final class AIResultCache
{
    public function __construct(private readonly CacheManager $cache)
    {
    }

    public function get(string $key): ?AIResponse
    {
        $value = $this->cache->get($key);

        return $value instanceof AIResponse ? $value : null;
    }

    public function put(string $key, AIResponse $response, int $ttlSeconds): void
    {
        $this->cache->put($key, $response, [], $ttlSeconds);
    }

    public function keyFor(AIRequest $request): string
    {
        $data = [
            'provider' => $request->provider,
            'model' => $request->model,
            'prompt' => $request->prompt,
            'input' => $request->input,
            'response_schema' => $request->responseSchema,
            'max_tokens' => $request->maxTokens,
            'temperature' => $request->temperature,
        ];

        return 'ai:result:' . hash('sha256', json_encode($data, JSON_UNESCAPED_SLASHES));
    }
}
