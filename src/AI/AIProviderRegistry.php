<?php

declare(strict_types=1);

namespace Foundry\AI;

use Foundry\Support\FoundryError;

final class AIProviderRegistry
{
    /**
     * @param array<string,ProviderFactory> $factories
     */
    public function __construct(
        private readonly array $factories = ['static' => new StaticAIProviderFactory()],
    ) {}

    /**
     * @param array<string,mixed> $config
     */
    public function managerForConfig(array $config): AIManager
    {
        return new AIManager($this->providersFromConfig($config));
    }

    /**
     * @param array<string,mixed> $config
     * @return array<string,AIProvider>
     */
    public function providersFromConfig(array $config): array
    {
        $providers = [];
        $providerConfig = is_array($config['providers'] ?? null) ? $config['providers'] : [];
        ksort($providerConfig);

        foreach ($providerConfig as $name => $definition) {
            if (!is_string($name) || !is_array($definition)) {
                continue;
            }

            $providers[$name] = $this->provider($name, $definition);
        }

        return $providers;
    }

    /**
     * @param array<string,mixed> $config
     */
    public function provider(string $providerName, array $config): AIProvider
    {
        $factoryClass = trim((string) ($config['factory'] ?? ''));
        if ($factoryClass !== '') {
            if (!class_exists($factoryClass)) {
                throw new FoundryError(
                    'AI_PROVIDER_FACTORY_NOT_FOUND',
                    'not_found',
                    ['provider' => $providerName, 'factory' => $factoryClass],
                    'AI provider factory class not found.',
                );
            }

            $factory = new $factoryClass();
            if (!$factory instanceof ProviderFactory) {
                throw new FoundryError(
                    'AI_PROVIDER_FACTORY_INVALID',
                    'validation',
                    ['provider' => $providerName, 'factory' => $factoryClass],
                    'AI provider factory must implement Foundry\\AI\\ProviderFactory.',
                );
            }

            return $factory->create($providerName, $config);
        }

        $driver = trim((string) ($config['driver'] ?? $providerName));
        $factory = $this->factories[$driver] ?? null;
        if (!$factory instanceof ProviderFactory) {
            throw new FoundryError(
                'AI_PROVIDER_DRIVER_NOT_SUPPORTED',
                'not_found',
                ['provider' => $providerName, 'driver' => $driver],
                'AI provider driver is not supported.',
            );
        }

        return $factory->create($providerName, $config);
    }
}
