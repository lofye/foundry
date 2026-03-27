<?php

declare(strict_types=1);

namespace Foundry\AI;

final class StaticAIProviderFactory implements ProviderFactory
{
    /**
     * @param array<string,mixed> $config
     */
    public function create(string $providerName, array $config): AIProvider
    {
        return new StaticAIProvider($providerName, [
            'content' => (string) ($config['content'] ?? ''),
            'parsed' => is_array($config['parsed'] ?? null) ? $config['parsed'] : [],
            'input_tokens' => (int) ($config['input_tokens'] ?? 0),
            'output_tokens' => (int) ($config['output_tokens'] ?? 0),
            'cost_estimate' => (float) ($config['cost_estimate'] ?? 0.0),
            'metadata' => is_array($config['metadata'] ?? null) ? $config['metadata'] : [],
        ]);
    }
}
