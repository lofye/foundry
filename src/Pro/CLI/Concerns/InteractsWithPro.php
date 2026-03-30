<?php

declare(strict_types=1);

namespace Foundry\Pro\CLI\Concerns;

use Foundry\Monetization\MonetizationService;
use Foundry\Pro\FeatureGate;
use Foundry\Pro\LicenseStore;

trait InteractsWithPro
{
    /**
     * @param array<int,string> $requiredFeatures
     * @return array<string,mixed>
     */
    protected function requirePro(string $command, array $requiredFeatures = []): array
    {
        return (new FeatureGate($this->licenseStore()))->require($command, $requiredFeatures);
    }

    /**
     * @return array<string,mixed>
     */
    protected function proStatus(): array
    {
        return $this->monetizationService()->status();
    }

    protected function licenseStore(): LicenseStore
    {
        return new LicenseStore();
    }

    protected function monetizationService(): MonetizationService
    {
        return new MonetizationService($this->licenseStore());
    }
}
