<?php

declare(strict_types=1);

namespace Foundry\Monetization;

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
        $tier = $this->normalizeTier((string) ($license['tier'] ?? FeatureFlags::TIER_PRO));
        $featureFlags = $this->enabledFeaturesForTier($tier);

        $metadata = [
            'activated_via' => 'license activate',
            'tier' => $tier,
            'feature_flags' => $featureFlags,
            'features' => $featureFlags,
        ];

        $remoteValidation = $this->remoteValidationMetadata($license);
        if ($remoteValidation !== null) {
            $remoteTier = $this->normalizeTier((string) ($remoteValidation['tier'] ?? $tier));
            $metadata['remote_validation'] = $remoteValidation;
            $metadata['product'] = (string) ($remoteValidation['product'] ?? ($license['product'] ?? 'foundry'));
            $metadata['tier'] = $remoteTier;
            $metadata['feature_flags'] = $this->enabledFeaturesForTier($remoteTier);
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
                ? 'Local license file removed. Environment-based licensing remains active until FOUNDRY_LICENSE_KEY is cleared.'
                : 'Environment-based licensing remains active. Clear FOUNDRY_LICENSE_KEY to fully deactivate.';

            return $status;
        }

        $status['message'] = $removed
            ? 'License deactivated.'
            : 'No local Foundry license file was active.';

        return $status;
    }

    public function getTier(): string
    {
        return $this->resolveTier($this->status());
    }

    public function isEnabled(string $feature): bool
    {
        return $this->featureEnabledForStatus($feature, $this->status());
    }

    /**
     * @return list<array{feature:string,type:string,monetization:string,enabled:bool,status:string,summary:string}>
     */
    public function visibleFeatureStatuses(): array
    {
        return $this->featureStatuses($this->status());
    }

    /**
     * @param array<int,string> $requiredFeatures
     * @return array<string,mixed>
     */
    public function trackUsage(string $command, array $requiredFeatures = []): array
    {
        $status = $this->status();

        $this->usageTracker()->record([
            'command' => $command,
            'product' => (string) ($status['product'] ?? 'foundry'),
            'tier' => $this->resolveTier($status),
            'source' => (string) ($status['source'] ?? 'file'),
            'fingerprint' => (string) ($status['fingerprint'] ?? ''),
            'feature_flags' => array_values($requiredFeatures),
            'feature_names' => FeatureFlags::publicNames($requiredFeatures),
        ]);

        return $status;
    }

    /**
     * @param array<int,string> $requiredFeatures
     * @return array<string,mixed>
     */
    public function require(string $command, array $requiredFeatures = []): array
    {
        return $this->trackUsage($command, $requiredFeatures);
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
            $tier = $this->normalizeTier((string) ($validated['tier'] ?? FeatureFlags::TIER_PRO));
            $featureFlags = $this->enabledFeaturesForTier($tier);

            return [
                'enabled' => true,
                'valid' => true,
                'status' => 'enabled',
                'source' => 'environment',
                'license_path' => $this->licenses()->path(),
                'product' => (string) ($validated['product'] ?? 'foundry'),
                'tier' => $tier,
                'key_hint' => (string) ($validated['key_hint'] ?? ''),
                'fingerprint' => (string) ($validated['fingerprint'] ?? ''),
                'feature_flags' => $featureFlags,
                'features' => $featureFlags,
                'enabled_at' => null,
                'validated_at' => (string) ($validated['validated_at'] ?? ''),
                'remote_validation' => null,
                'activated_via' => 'environment',
                'message' => 'License is active via the environment.',
            ];
        } catch (\Throwable $error) {
            return [
                'enabled' => false,
                'valid' => false,
                'status' => 'invalid',
                'source' => 'environment',
                'license_path' => $this->licenses()->path(),
                'product' => 'foundry',
                'tier' => FeatureFlags::TIER_FREE,
                'key_hint' => $this->validator()->keyHint($licenseKey),
                'fingerprint' => null,
                'feature_flags' => [],
                'features' => [],
                'enabled_at' => null,
                'validated_at' => null,
                'remote_validation' => null,
                'activated_via' => 'environment',
                'message' => 'The environment-provided license is invalid: ' . $error->getMessage(),
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

        $tier = $this->normalizeTier((string) ($license['tier'] ?? FeatureFlags::TIER_PRO));

        $response = $this->requestRemoteValidation($url, [
            'product' => (string) ($license['product'] ?? 'foundry'),
            'tier' => $tier,
            'license_key' => (string) ($license['license_key'] ?? ''),
            'fingerprint' => (string) ($license['fingerprint'] ?? ''),
            'feature_flags' => $this->enabledFeaturesForTier($tier),
        ]);

        if (($response['valid'] ?? false) !== true) {
            $message = trim((string) ($response['message'] ?? ''));

            throw new FoundryError(
                'LICENSE_REMOTE_VALIDATION_FAILED',
                'authorization',
                ['validation_url' => $this->redactValidationUrl($url)],
                $message !== '' ? $message : 'Remote license validation rejected the supplied key.',
            );
        }

        $validatedTier = $this->normalizeTier((string) ($response['tier'] ?? ($response['plan'] ?? $tier)));

        return [
            'enabled' => true,
            'mode' => $this->remoteValidationMode($url),
            'endpoint' => $this->redactValidationUrl($url),
            'validated_at' => gmdate(DATE_ATOM),
            'message' => (string) ($response['message'] ?? 'Remote license validation succeeded.'),
            'product' => (string) ($response['product'] ?? ($license['product'] ?? 'foundry')),
            'tier' => $validatedTier,
            'feature_flags' => $this->enabledFeaturesForTier($validatedTier),
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
                'LICENSE_REMOTE_VALIDATION_FAILED',
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
                'LICENSE_REMOTE_VALIDATION_FAILED',
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
        $tier = $this->resolveTier($status);
        $featureFlags = (($status['valid'] ?? false) === true)
            ? $this->enabledFeaturesForTier($tier)
            : [];
        $featureStatuses = $this->featureStatuses($status);

        $status['tier'] = $tier;
        $status['feature_flags'] = $featureFlags;
        $status['features'] = $featureFlags;
        $status['feature_statuses'] = $featureStatuses;
        $status['capabilities'] = array_values(array_map(
            static fn(array $row): string => (string) ($row['feature'] ?? ''),
            array_values(array_filter(
                $featureStatuses,
                static fn(array $row): bool => (string) ($row['type'] ?? '') === 'capability',
            )),
        ));
        $status['service_access'] = [
            'available' => array_values(array_map(
                static fn(array $row): string => (string) ($row['feature'] ?? ''),
                array_values(array_filter(
                    $featureStatuses,
                    static fn(array $row): bool => (string) ($row['type'] ?? '') === 'service'
                        && ($row['enabled'] ?? false) === true,
                )),
            )),
            'unavailable' => array_values(array_map(
                static fn(array $row): string => (string) ($row['feature'] ?? ''),
                array_values(array_filter(
                    $featureStatuses,
                    static fn(array $row): bool => (string) ($row['type'] ?? '') === 'service'
                        && ($row['enabled'] ?? false) !== true,
                )),
            )),
        ];
        $status['source'] = (string) ($status['source'] ?? (
            ($status['status'] ?? 'missing') === 'missing'
                ? 'none'
                : 'file'
        ));
        $status['usage_tracking'] = $this->usageTracker()->status();
        $status['license_commands'] = [
            'status_command' => 'foundry license status',
            'activate_command' => 'foundry license activate --key=YOUR_KEY',
            'deactivate_command' => 'foundry license deactivate',
        ];

        return $status;
    }

    private function resolveTier(array $status): string
    {
        $tier = (string) ($status['tier'] ?? '');
        if ($tier === '') {
            $tier = (($status['valid'] ?? false) === true)
                ? FeatureFlags::TIER_PRO
                : FeatureFlags::TIER_FREE;
        }

        return $this->normalizeTier($tier);
    }

    private function featureEnabledForStatus(string $feature, array $status): bool
    {
        if (FeatureFlags::monetization($feature) !== 'licensed') {
            return true;
        }

        return ($status['valid'] ?? false) === true;
    }

    /**
     * @param array<string,mixed> $status
     * @return list<array{feature:string,type:string,monetization:string,enabled:bool,status:string,summary:string}>
     */
    private function featureStatuses(array $status): array
    {
        $rows = [];

        foreach (FeatureFlags::catalog(true) as $feature => $metadata) {
            $enabled = $this->featureEnabledForStatus($feature, $status);
            $rows[] = [
                'feature' => (string) ($metadata['name'] ?? $feature),
                'type' => (string) ($metadata['type'] ?? 'capability'),
                'monetization' => (string) ($metadata['monetization'] ?? 'none'),
                'enabled' => $enabled,
                'status' => $enabled ? 'available' : 'unavailable',
                'summary' => (string) ($metadata['summary'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function enabledFeaturesForTier(string $tier): array
    {
        return FeatureFlags::enabledForTier($this->normalizeTier($tier));
    }

    private function normalizeTier(string $tier): string
    {
        return in_array($tier, [FeatureFlags::TIER_FREE, FeatureFlags::TIER_PRO], true)
            ? $tier
            : FeatureFlags::TIER_FREE;
    }

    private function environmentLicenseKey(): ?string
    {
        foreach (['FOUNDRY_LICENSE_KEY'] as $name) {
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
