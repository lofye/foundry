<?php
declare(strict_types=1);

namespace Forge\Tests\Unit;

use Forge\AI\AIManager;
use Forge\AI\AIRequest;
use Forge\AI\AIResponse;
use Forge\AI\AIResultCache;
use Forge\AI\StaticAIProvider;
use Forge\Cache\ArrayCacheStore;
use Forge\Cache\CacheManager;
use Forge\Cache\CacheRegistry;
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
