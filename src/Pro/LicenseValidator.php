<?php

declare(strict_types=1);

namespace Foundry\Pro;

use Foundry\Support\FoundryError;

final class LicenseValidator
{
    /**
     * @var array<int,string>
     */
    public const FEATURES = [
        'deep_diagnostics',
        'architecture_explanation',
        'graph_diffing',
        'trace_analysis',
        'ai_assisted_generation',
    ];

    /**
     * @return array<string,mixed>
     */
    public function validate(string $licenseKey): array
    {
        $normalized = $this->normalize($licenseKey);
        if ($normalized === '') {
            throw new FoundryError(
                'PRO_LICENSE_KEY_REQUIRED',
                'validation',
                [],
                'A Foundry Pro license key is required.',
            );
        }

        if (!preg_match('/^FPRO(?:-[A-Z0-9]{4}){4}-[A-F0-9]{8}$/', $normalized)) {
            throw new FoundryError(
                'PRO_LICENSE_KEY_INVALID',
                'validation',
                ['license_key' => $normalized],
                'The Foundry Pro license key format is invalid.',
            );
        }

        $segments = explode('-', $normalized);
        $checksum = (string) array_pop($segments);
        $body = implode('-', $segments);
        $expected = strtoupper(substr(hash('sha256', 'foundry-pro:' . $body), 0, 8));

        if ($checksum !== $expected) {
            throw new FoundryError(
                'PRO_LICENSE_KEY_INVALID',
                'validation',
                ['license_key' => $normalized],
                'The Foundry Pro license key checksum is invalid.',
            );
        }

        return [
            'schema_version' => 1,
            'product' => 'foundry-pro',
            'plan' => 'pro',
            'license_key' => $normalized,
            'key_hint' => $this->keyHint($normalized),
            'fingerprint' => substr(hash('sha256', $normalized), 0, 16),
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
