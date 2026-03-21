<?php
declare(strict_types=1);

namespace Foundry\Pro;

use Foundry\Support\FoundryError;

final readonly class FeatureGate
{
    public function __construct(private LicenseStore $licenses)
    {
    }

    /**
     * @param array<int,string> $requiredFeatures
     * @return array<string,mixed>
     */
    public function require(string $command, array $requiredFeatures = []): array
    {
        $status = $this->licenses->status();

        if (($status['valid'] ?? false) !== true) {
            $code = ($status['status'] ?? 'missing') === 'invalid'
                ? 'PRO_LICENSE_INVALID'
                : 'PRO_LICENSE_REQUIRED';

            throw new FoundryError(
                $code,
                'authorization',
                [
                    'command' => $command,
                    'required_features' => $requiredFeatures,
                    'license_path' => $status['license_path'] ?? $this->licenses->path(),
                ],
                ($status['message'] ?? 'Foundry Pro is not enabled.')
                    . ' Run `foundry pro enable <license-key>` to enable Pro features.',
            );
        }

        $availableFeatures = array_values(array_map('strval', (array) ($status['features'] ?? [])));
        $missingFeatures = array_values(array_diff($requiredFeatures, $availableFeatures));

        if ($missingFeatures !== []) {
            throw new FoundryError(
                'PRO_FEATURE_NOT_ENABLED',
                'authorization',
                [
                    'command' => $command,
                    'required_features' => $requiredFeatures,
                    'missing_features' => $missingFeatures,
                    'license_path' => $status['license_path'] ?? $this->licenses->path(),
                ],
                'The current Foundry Pro license does not enable the requested feature set.',
            );
        }

        return $status;
    }
}
