<?php
declare(strict_types=1);

namespace Foundry\AI;

interface ProviderFactory
{
    /**
     * @param array<string,mixed> $config
     */
    public function create(string $providerName, array $config): AIProvider;
}
