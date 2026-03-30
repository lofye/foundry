<?php

declare(strict_types=1);

namespace Foundry\Monetization;

use Foundry\Monetization\FeatureFlags;
use Foundry\Support\FoundryError;

final class LicenseValidator
{
    /**
     * @var array<int,string>
     */
    public const FEATURES = [
        FeatureFlags::HOSTED_SYNC,
    ];

    /**
     * @return array<string,mixed>
     */
    public function validate(string $licenseKey): array
    {
        $normalized = $this->normalize($licenseKey);
        if ($normalized === '') {
            throw new FoundryError(
                'LICENSE_KEY_REQUIRED',
                'validation',
                [],
                'A Foundry license key is required.',
            );
        }

        if (!preg_match('/^FPRO(?:-[A-Z0-9]{4}){4}-[A-F0-9]{8}$/', $normalized)) {
            throw new FoundryError(
                'LICENSE_KEY_INVALID',
                'validation',
                ['license_key' => $normalized],
                'The Foundry license key format is invalid.',
            );
        }

        $segments = explode('-', $normalized);
        $checksum = (string) array_pop($segments);
        $body = implode('-', $segments);
        $expected = strtoupper(substr(hash('sha256', 'foundry-pro:' . $body), 0, 8));

        if ($checksum !== $expected) {
            throw new FoundryError(
                'LICENSE_KEY_INVALID',
                'validation',
                ['license_key' => $normalized],
                'The Foundry license key checksum is invalid.',
            );
        }

        return [
            'schema_version' => 1,
            'product' => 'foundry',
            'tier' => FeatureFlags::TIER_PRO,
            'license_key' => $normalized,
            'key_hint' => $this->keyHint($normalized),
            'fingerprint' => substr(hash('sha256', $normalized), 0, 16),
            'feature_flags' => self::FEATURES,
            'features' => self::FEATURES,
            'validated_at' => gmdate(DATE_ATOM),
        ];
    }

    public function normalize(string $licenseKey): string
    {
        $trimmed = strtoupper(trim($licenseKey));

        return preg_replace('/\s+/', '', $trimmed) ?? '';
    }

    public function keyHint(string $licenseKey): string
    {
        $normalized = $this->normalize($licenseKey);
        if ($normalized === '') {
            return '';
        }

        return '...' . substr($normalized, -4);
    }
}
