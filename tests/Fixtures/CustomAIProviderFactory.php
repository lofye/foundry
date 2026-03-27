<?php

declare(strict_types=1);

namespace Foundry\Tests\Fixtures;

use Foundry\AI\AIProvider;
use Foundry\AI\ProviderFactory;
use Foundry\AI\StaticAIProvider;

final class CustomAIProviderFactory implements ProviderFactory
{
    /**
     * @param array<string,mixed> $config
     */
    public function create(string $providerName, array $config): AIProvider
    {
        $parsed = is_array($config['parsed'] ?? null) ? $config['parsed'] : [];
        $parsed['factory_source'] = 'custom';

        return new StaticAIProvider($providerName, [
            'content' => (string) ($config['content'] ?? ''),
            'parsed' => $parsed,
            'input_tokens' => (int) ($config['input_tokens'] ?? 0),
            'output_tokens' => (int) ($config['output_tokens'] ?? 0),
            'cost_estimate' => (float) ($config['cost_estimate'] ?? 0.0),
            'metadata' => ['factory' => self::class],
        ]);
    }
}
