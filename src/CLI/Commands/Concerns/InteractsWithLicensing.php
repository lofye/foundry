<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands\Concerns;

use Foundry\Monetization\LicenseStore;
use Foundry\Monetization\MonetizationService;

trait InteractsWithLicensing
{
    /**
     * @param array<int,string> $requiredFeatures
     * @return array<string,mixed>
     */
    protected function monetizationContext(string $command, array $requiredFeatures = []): array
    {
        return $this->monetizationService()->trackUsage($command, $requiredFeatures);
    }

    /**
     * @return array<string,mixed>
     */
    protected function licenseStatus(): array
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
