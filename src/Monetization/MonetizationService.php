<?php

declare(strict_types=1);

namespace Foundry\Monetization;

use Foundry\Pro\LicenseStore;
use Foundry\Pro\LicenseValidator;
use Foundry\Support\FoundryError;
use Foundry\Support\Json;

final class MonetizationService
{
    public function __construct(
        private readonly ?LicenseStore $licenses = null,
        private readonly ?LicenseValidator $validator = null,
        private readonly ?UsageTracker $usageTracker = null,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function activate(string $licenseKey): array
    {
        $license = $this->validator()->validate($licenseKey);
        $featureFlags = array_values(array_map('strval', (array) ($license['features'] ?? [])));

        $metadata = [
            'activated_via' => 'license:activate',
            'feature_flags' => $featureFlags,
            'features' => $featureFlags,
        ];

        $remoteValidation = $this->remoteValidationMetadata($license);
        if ($remoteValidation !== null) {
            $remoteFeatureFlags = array_values(array_map('strval', (array) ($remoteValidation['feature_flags'] ?? [])));
            $metadata['remote_validation'] = $remoteValidation;
            $metadata['product'] = (string) ($remoteValidation['product'] ?? ($license['product'] ?? 'foundry-pro'));
            $metadata['plan'] = (string) ($remoteValidation['plan'] ?? ($license['plan'] ?? 'pro'));
            $metadata['feature_flags'] = $remoteFeatureFlags !== [] ? $remoteFeatureFlags : $featureFlags;
            $metadata['features'] = $metadata['feature_flags'];
        }

        return $this->decorateStatus($this->licenses()->enable($licenseKey, $metadata));
    }

    /**
     * @return array<string,mixed>
     */
    public function deactivate(): array
    {
        $removed = $this->licenses()->disable();
        $status = $this->status();
        $status['deactivated'] = $removed;

        if (($status['source'] ?? null) === 'environment' && ($status['valid'] ?? false) === true) {
            $status['message'] = $removed
                ? 'Local license file removed. Environment-based licensing remains active until FOUNDRY_LICENSE_KEY or FOUNDRY_PRO_LICENSE_KEY is cleared.'
                : 'Environment-based licensing remains active. Clear FOUNDRY_LICENSE_KEY or FOUNDRY_PRO_LICENSE_KEY to fully deactivate.';

            return $status;
        }

        $status['message'] = $removed
            ? 'Foundry Pro license deactivated.'
            : 'No local Foundry license file was active.';

        return $status;
    }

    /**
     * @param array<int,string> $requiredFeatures
     * @return array<string,mixed>
     */
    public function require(string $command, array $requiredFeatures = []): array
    {
        $status = $this->status();

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
                    'license_path' => $status['license_path'] ?? $this->licenses()->path(),
                    'source' => $status['source'] ?? 'none',
                ],
                ($status['message'] ?? 'Foundry Pro is not enabled.')
                    . ' Run `foundry license:activate <license-key>` to enable Pro features. `foundry pro enable <license-key>` remains supported as a legacy alias.',
            );
        }

        $availableFeatures = array_values(array_map('strval', (array) ($status['feature_flags'] ?? $status['features'] ?? [])));
        $missingFeatures = array_values(array_diff($requiredFeatures, $availableFeatures));

        if ($missingFeatures !== []) {
            throw new FoundryError(
                'PRO_FEATURE_NOT_ENABLED',
                'authorization',
                [
                    'command' => $command,
                    'required_features' => $requiredFeatures,
                    'missing_features' => $missingFeatures,
                    'license_path' => $status['license_path'] ?? $this->licenses()->path(),
                    'source' => $status['source'] ?? 'none',
                ],
                'The current Foundry Pro license does not enable the requested feature set.',
            );
        }

        $this->usageTracker()->record([
            'command' => $command,
            'product' => (string) ($status['product'] ?? 'foundry-pro'),
            'plan' => (string) ($status['plan'] ?? 'pro'),
            'source' => (string) ($status['source'] ?? 'file'),
            'fingerprint' => (string) ($status['fingerprint'] ?? ''),
            'feature_flags' => array_values($requiredFeatures),
        ]);

        return $status;
    }

    /**
     * @return array<string,mixed>
     */
    public function status(): array
    {
        $environmentKey = $this->environmentLicenseKey();
        if ($environmentKey !== null) {
            return $this->decorateStatus($this->statusFromEnvironment($environmentKey));
        }

        return $this->decorateStatus($this->licenses()->status());
    }

    /**
     * @return array<string,mixed>
     */
    private function statusFromEnvironment(string $licenseKey): array
    {
        try {
            $validated = $this->validator()->validate($licenseKey);
            $featureFlags = array_values(array_map('strval', (array) ($validated['features'] ?? [])));

            return [
                'enabled' => true,
                'valid' => true,
                'status' => 'enabled',
                'source' => 'environment',
                'license_path' => $this->licenses()->path(),
                'product' => (string) ($validated['product'] ?? 'foundry-pro'),
                'plan' => (string) ($validated['plan'] ?? 'pro'),
                'key_hint' => (string) ($validated['key_hint'] ?? ''),
                'fingerprint' => (string) ($validated['fingerprint'] ?? ''),
                'feature_flags' => $featureFlags,
                'features' => $featureFlags,
                'enabled_at' => null,
                'validated_at' => (string) ($validated['validated_at'] ?? ''),
                'remote_validation' => null,
                'activated_via' => 'environment',
                'message' => 'Foundry Pro is enabled via the environment.',
            ];
        } catch (\Throwable $error) {
            return [
                'enabled' => false,
                'valid' => false,
                'status' => 'invalid',
                'source' => 'environment',
                'license_path' => $this->licenses()->path(),
                'product' => 'foundry-pro',
                'plan' => null,
                'key_hint' => $this->validator()->keyHint($licenseKey),
                'fingerprint' => null,
                'feature_flags' => [],
                'features' => [],
                'enabled_at' => null,
                'validated_at' => null,
                'remote_validation' => null,
                'activated_via' => 'environment',
                'message' => 'The environment-provided Foundry license is invalid: ' . $error->getMessage(),
            ];
        }
    }

    /**
     * @param array<string,mixed> $license
     * @return array<string,mixed>|null
     */
    private function remoteValidationMetadata(array $license): ?array
    {
        $url = $this->remoteValidationUrl();
        if ($url === null) {
            return null;
        }

        $response = $this->requestRemoteValidation($url, [
            'product' => (string) ($license['product'] ?? 'foundry-pro'),
            'plan' => (string) ($license['plan'] ?? 'pro'),
            'license_key' => (string) ($license['license_key'] ?? ''),
            'fingerprint' => (string) ($license['fingerprint'] ?? ''),
            'feature_flags' => array_values(array_map('strval', (array) ($license['features'] ?? []))),
        ]);

        if (($response['valid'] ?? false) !== true) {
            $message = trim((string) ($response['message'] ?? ''));

            throw new FoundryError(
                'PRO_LICENSE_REMOTE_VALIDATION_FAILED',
                'authorization',
                ['validation_url' => $this->redactValidationUrl($url)],
                $message !== '' ? $message : 'Remote license validation rejected the supplied key.',
            );
        }

        return [
            'enabled' => true,
            'mode' => $this->remoteValidationMode($url),
            'endpoint' => $this->redactValidationUrl($url),
            'validated_at' => gmdate(DATE_ATOM),
            'message' => (string) ($response['message'] ?? 'Remote license validation succeeded.'),
            'product' => (string) ($response['product'] ?? ($license['product'] ?? 'foundry-pro')),
            'plan' => (string) ($response['plan'] ?? ($license['plan'] ?? 'pro')),
            'feature_flags' => array_values(array_map('strval', (array) ($response['feature_flags'] ?? $response['features'] ?? ($license['features'] ?? [])))),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function requestRemoteValidation(string $url, array $payload): array
    {
        $json = false;

        if (preg_match('/^https?:\/\//i', $url) === 1) {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                    'content' => Json::encode($payload, true),
                    'timeout' => $this->remoteValidationTimeoutSeconds(),
                    'ignore_errors' => true,
                ],
            ]);

            $json = @file_get_contents($url, false, $context);
        } else {
            $json = @file_get_contents($url);
        }

        if ($json === false) {
            throw new FoundryError(
                'PRO_LICENSE_REMOTE_VALIDATION_FAILED',
                'runtime',
                ['validation_url' => $this->redactValidationUrl($url)],
                'Remote license validation could not be completed.',
            );
        }

        try {
            /** @var array<string,mixed> $decoded */
            $decoded = Json::decodeAssoc($json);

            return $decoded;
        } catch (\Throwable) {
            throw new FoundryError(
                'PRO_LICENSE_REMOTE_VALIDATION_FAILED',
                'runtime',
                ['validation_url' => $this->redactValidationUrl($url)],
                'Remote license validation returned invalid JSON.',
            );
        }
    }

    /**
     * @param array<string,mixed> $status
     * @return array<string,mixed>
     */
    private function decorateStatus(array $status): array
    {
        $featureFlags = array_values(array_map('strval', (array) ($status['feature_flags'] ?? $status['features'] ?? [])));

        $status['feature_flags'] = $featureFlags;
        $status['features'] = $featureFlags;
        $status['source'] = (string) ($status['source'] ?? (
            ($status['status'] ?? 'missing') === 'missing'
                ? 'none'
                : 'file'
        ));
        $status['usage_tracking'] = $this->usageTracker()->status();
        $status['upgrade'] = [
            'status_command' => 'foundry license:status',
            'activate_command' => 'foundry license:activate <license-key>',
            'deactivate_command' => 'foundry license:deactivate',
            'legacy_aliases' => [
                'foundry pro status',
                'foundry pro enable <license-key>',
            ],
        ];

        return $status;
    }

    private function environmentLicenseKey(): ?string
    {
        foreach (['FOUNDRY_LICENSE_KEY', 'FOUNDRY_PRO_LICENSE_KEY'] as $name) {
            $value = getenv($name);
            if (!is_string($value) || trim($value) === '') {
                continue;
            }

            return trim($value);
        }

        return null;
    }

    private function remoteValidationUrl(): ?string
    {
        $value = getenv('FOUNDRY_LICENSE_VALIDATION_URL');
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function remoteValidationTimeoutSeconds(): int
    {
        $value = getenv('FOUNDRY_LICENSE_VALIDATION_TIMEOUT');
        if (!is_string($value) || trim($value) === '') {
            return 3;
        }

        return max(1, (int) $value);
    }

    private function remoteValidationMode(string $url): string
    {
        return preg_match('/^https?:\/\//i', $url) === 1 ? 'remote_opt_in' : 'local_stub';
    }

    private function redactValidationUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !array_key_exists('scheme', $parts)) {
            return $url;
        }

        if (($parts['scheme'] ?? null) === 'file') {
            return 'file://' . basename((string) ($parts['path'] ?? 'validation.json'));
        }

        $host = (string) ($parts['host'] ?? '');
        $path = (string) ($parts['path'] ?? '');

        return strtolower((string) $parts['scheme']) . '://' . $host . $path;
    }

    private function licenses(): LicenseStore
    {
        return $this->licenses ?? new LicenseStore();
    }

    private function validator(): LicenseValidator
    {
        return $this->validator ?? new LicenseValidator();
    }

    private function usageTracker(): UsageTracker
    {
        return $this->usageTracker ?? new UsageTracker();
    }
}
