<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\AI\AIManager;
use Foundry\AI\AIRequest;
use Foundry\AI\AIResponse;
use Foundry\AI\AIResultCache;
use Foundry\AI\StaticAIProvider;
use Foundry\Cache\ArrayCacheStore;
use Foundry\Cache\CacheManager;
use Foundry\Cache\CacheRegistry;
use PHPUnit\Framework\TestCase;

final class AIManagerTest extends TestCase
{
    public function test_complete_returns_provider_response_and_can_cache(): void
    {
        $provider = new StaticAIProvider('static', [
            'content' => '{"summary":"ok"}',
            'parsed' => ['summary' => 'ok'],
            'input_tokens' => 10,
            'output_tokens' => 5,
            'cost_estimate' => 0.01,
        ]);

        $cache = new AIResultCache(new CacheManager(new ArrayCacheStore(), new CacheRegistry()));
        $manager = new AIManager(['static' => $provider], $cache);

        $request = new AIRequest(
            provider: 'static',
            model: 'gpt-x',
            prompt: 'summarize',
            responseSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['summary'],
                'properties' => ['summary' => ['type' => 'string']],
            ],
            cacheable: true,
            cacheTtlSeconds: 60,
        );

        $first = $manager->complete($request);
        $second = $manager->complete($request);

        $this->assertInstanceOf(AIResponse::class, $first);
        $this->assertTrue($second->cacheHit);
    }
}
