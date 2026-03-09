<?php
declare(strict_types=1);

namespace Foundry\Billing;

use Foundry\Support\Paths;

final class BillingPlanRegistry
{
    /**
     * @var array<string,mixed>|null
     */
    private ?array $plans = null;

    public function __construct(private readonly Paths $paths)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function all(): array
    {
        if ($this->plans !== null) {
            return $this->plans;
        }

        $path = $this->paths->join('app/.foundry/build/projections/billing_index.php');
        if (!is_file($path)) {
            $this->plans = [];

            return $this->plans;
        }

        /** @var mixed $raw */
        $raw = require $path;
        if (!is_array($raw)) {
            $this->plans = [];

            return $this->plans;
        }

        $this->plans = $raw;

        return $this->plans;
    }

    /**
     * @return array<string,mixed>
     */
    public function provider(string $provider): array
    {
        $row = $this->all()[strtolower($provider)] ?? [];

        return is_array($row) ? $row : [];
    }
}
