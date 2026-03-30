<?php

declare(strict_types=1);

namespace Foundry\Pro;

use Foundry\Support\Json;

final class LicenseStore
{
    public function __construct(
        private readonly ?LicenseValidator $validator = null,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function enable(string $licenseKey, array $metadata = []): array
    {
        $record = $this->validator()->validate($licenseKey);
        $featureFlags = array_values(array_map('strval', (array) ($metadata['feature_flags'] ?? $metadata['features'] ?? $record['features'] ?? [])));

        $record = array_merge($record, $metadata);
        $record['feature_flags'] = $featureFlags;
        $record['features'] = $featureFlags;
        $record['enabled_at'] = gmdate(DATE_ATOM);

        $path = $this->path();
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($path, Json::encode($record, true) . PHP_EOL);

        return $this->status();
    }

    public function disable(): bool
    {
        $path = $this->path();
        if (!is_file($path)) {
            return false;
        }

        return @unlink($path);
    }

    /**
     * @return array<string,mixed>
     */
    public function status(): array
    {
        $path = $this->path();

        if (!is_file($path)) {
            return [
                'enabled' => false,
                'valid' => false,
                'status' => 'missing',
                'source' => 'none',
                'license_path' => $path,
                'product' => 'foundry-pro',
                'plan' => null,
                'key_hint' => null,
                'fingerprint' => null,
                'feature_flags' => [],
                'features' => [],
                'enabled_at' => null,
                'validated_at' => null,
                'remote_validation' => null,
                'activated_via' => null,
                'message' => 'Foundry Pro is not enabled.',
            ];
        }

        $json = file_get_contents($path);
        if ($json === false) {
            return $this->invalidStatus($path, 'The stored Foundry Pro license could not be read.');
        }

        try {
            /** @var array<string,mixed> $payload */
            $payload = Json::decodeAssoc($json);
        } catch (\Throwable) {
            return $this->invalidStatus($path, 'The stored Foundry Pro license file is not valid JSON.');
        }

        $licenseKey = trim((string) ($payload['license_key'] ?? ''));
        if ($licenseKey === '') {
            return $this->invalidStatus($path, 'The stored Foundry Pro license file is missing the license key.');
        }

        try {
            $validated = $this->validator()->validate($licenseKey);
        } catch (\Throwable $error) {
            return $this->invalidStatus($path, 'The stored Foundry Pro license is invalid: ' . $error->getMessage());
        }

        $featureFlags = array_values(array_map('strval', (array) ($payload['feature_flags'] ?? $payload['features'] ?? $validated['features'] ?? [])));

        return [
            'enabled' => true,
            'valid' => true,
            'status' => 'enabled',
            'source' => 'file',
            'license_path' => $path,
            'product' => (string) ($payload['product'] ?? ($validated['product'] ?? 'foundry-pro')),
            'plan' => (string) ($payload['plan'] ?? ($validated['plan'] ?? 'pro')),
            'key_hint' => (string) ($validated['key_hint'] ?? ''),
            'fingerprint' => (string) ($validated['fingerprint'] ?? ''),
            'feature_flags' => $featureFlags,
            'features' => $featureFlags,
            'enabled_at' => (string) ($payload['enabled_at'] ?? ($validated['validated_at'] ?? '')),
            'validated_at' => (string) ($validated['validated_at'] ?? ''),
            'remote_validation' => is_array($payload['remote_validation'] ?? null) ? $payload['remote_validation'] : null,
            'activated_via' => (string) ($payload['activated_via'] ?? 'file'),
            'message' => 'Foundry Pro is enabled.',
        ];
    }

    public function path(): string
    {
        $override = getenv('FOUNDRY_LICENSE_PATH');
        if (is_string($override) && trim($override) !== '') {
            return $this->normalizePath($override);
        }

        $foundryHome = getenv('FOUNDRY_HOME');
        if (is_string($foundryHome) && trim($foundryHome) !== '') {
            return $this->normalizePath(rtrim($foundryHome, '/\\') . '/license.json');
        }

        $home = getenv('HOME');
        if (is_string($home) && trim($home) !== '') {
            return $this->normalizePath(rtrim($home, '/\\') . '/.foundry/license.json');
        }

        return $this->normalizePath((getcwd() ?: '.') . '/.foundry/license.json');
    }

    private function validator(): LicenseValidator
    {
        return $this->validator ?? new LicenseValidator();
    }

    /**
     * @return array<string,mixed>
     */
    private function invalidStatus(string $path, string $message): array
    {
        return [
            'enabled' => false,
            'valid' => false,
            'status' => 'invalid',
            'source' => 'file',
            'license_path' => $path,
            'product' => 'foundry-pro',
            'plan' => null,
            'key_hint' => null,
            'fingerprint' => null,
            'feature_flags' => [],
            'features' => [],
            'enabled_at' => null,
            'validated_at' => null,
            'remote_validation' => null,
            'activated_via' => null,
            'message' => $message,
        ];
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', trim($path));
    }
}
