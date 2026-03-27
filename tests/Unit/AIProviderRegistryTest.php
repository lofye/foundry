<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\AI\AIProviderRegistry;
use Foundry\AI\AIRequest;
use Foundry\Support\FoundryError;
use Foundry\Tests\Fixtures\CustomAIProviderFactory;
use PHPUnit\Framework\TestCase;

final class AIProviderRegistryTest extends TestCase
{
    public function test_registry_builds_static_driver_provider_from_config(): void
    {
        $registry = new AIProviderRegistry();
        $providers = $registry->providersFromConfig([
            'providers' => [
                'static' => [
                    'driver' => 'static',
                    'parsed' => ['summary' => 'ok'],
                    'input_tokens' => 11,
                    'output_tokens' => 7,
                ],
            ],
        ]);

        $this->assertArrayHasKey('static', $providers);

        $response = $providers['static']->complete(new AIRequest(
            provider: 'static',
            model: 'fixture-model',
            prompt: 'summarize',
        ));

        $this->assertSame('static', $response->provider);
        $this->assertSame('fixture-model', $response->model);
        $this->assertSame('ok', $response->parsed['summary']);
        $this->assertSame(11, $response->inputTokens);
        $this->assertSame(7, $response->outputTokens);
    }

    public function test_registry_supports_custom_factory_classes(): void
    {
        $registry = new AIProviderRegistry();
        $provider = $registry->provider('custom', [
            'factory' => CustomAIProviderFactory::class,
            'parsed' => ['summary' => 'custom'],
        ]);

        $response = $provider->complete(new AIRequest(
            provider: 'custom',
            model: 'fixture-model',
            prompt: 'summarize',
        ));

        $this->assertSame('custom', $response->provider);
        $this->assertSame('custom', $response->parsed['summary']);
        $this->assertSame('custom', $response->parsed['factory_source']);
        $this->assertSame(CustomAIProviderFactory::class, $response->metadata['factory']);
    }

    public function test_registry_rejects_unknown_driver(): void
    {
        $registry = new AIProviderRegistry();

        try {
            $registry->provider('example', ['driver' => 'missing']);
            self::fail('Expected unsupported driver failure.');
        } catch (FoundryError $error) {
            $this->assertSame('AI_PROVIDER_DRIVER_NOT_SUPPORTED', $error->errorCode);
            $this->assertSame('missing', $error->details['driver']);
        }
    }

    public function test_registry_rejects_missing_factory_classes(): void
    {
        $registry = new AIProviderRegistry();

        try {
            $registry->provider('custom', ['factory' => 'Foundry\\Tests\\Fixtures\\MissingProviderFactory']);
            self::fail('Expected missing factory failure.');
        } catch (FoundryError $error) {
            $this->assertSame('AI_PROVIDER_FACTORY_NOT_FOUND', $error->errorCode);
        }
    }

    public function test_registry_rejects_invalid_factory_classes(): void
    {
        $registry = new AIProviderRegistry();

        try {
            $registry->provider('custom', ['factory' => InvalidAIProviderFactoryMarker::class]);
            self::fail('Expected invalid factory failure.');
        } catch (FoundryError $error) {
            $this->assertSame('AI_PROVIDER_FACTORY_INVALID', $error->errorCode);
        }
    }
}

final class InvalidAIProviderFactoryMarker {}
