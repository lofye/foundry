<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Monetization\MonetizationService;
use Foundry\Support\FoundryError;

final class LicenseCommand extends Command
{
    /**
     * @var list<string>
     */
    private const LICENSE_COMMANDS = [
        'license:status',
        'license:activate <license-key>',
        'license:deactivate',
    ];

    /**
     * @var list<string>
     */
    private const LEGACY_ALIASES = [
        'pro status',
        'pro enable <license-key>',
    ];

    #[\Override]
    public function supportedSignatures(): array
    {
        return ['license:status', 'license:activate', 'license:deactivate'];
    }

    #[\Override]
    public function matches(array $args): bool
    {
        return in_array($args[0] ?? null, ['license:status', 'license:activate', 'license:deactivate'], true);
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $subcommand = (string) ($args[0] ?? 'license:status');
        $service = new MonetizationService();

        return match ($subcommand) {
            'license:status' => $this->result($context, $service->status()),
            'license:activate' => $this->activate($args, $context, $service),
            'license:deactivate' => $this->result($context, $service->deactivate()),
            default => throw new FoundryError(
                'CLI_LICENSE_SUBCOMMAND_NOT_FOUND',
                'not_found',
                ['subcommand' => $subcommand],
                'License subcommand not found.',
            ),
        };
    }

    /**
     * @param array<int,string> $args
     * @return array{status:int,payload:array<string,mixed>|null,message:string|null}
     */
    private function activate(array $args, CommandContext $context, MonetizationService $service): array
    {
        $licenseKey = trim((string) ($args[1] ?? ''));
        if ($licenseKey === '') {
            throw new FoundryError(
                'PRO_LICENSE_KEY_REQUIRED',
                'validation',
                [],
                'A Foundry Pro license key is required.',
            );
        }

        return $this->result($context, $service->activate($licenseKey));
    }

    /**
     * @param array<string,mixed> $license
     * @return array{status:int,payload:array<string,mixed>|null,message:string|null}
     */
    private function result(CommandContext $context, array $license): array
    {
        $payload = [
            'license' => $license,
            'commands' => self::LICENSE_COMMANDS,
            'legacy_aliases' => self::LEGACY_ALIASES,
        ];

        return [
            'status' => 0,
            'message' => $context->expectsJson() ? null : $this->renderStatus($license),
            'payload' => $context->expectsJson() ? $payload : null,
        ];
    }

    /**
     * @param array<string,mixed> $license
     */
    private function renderStatus(array $license): string
    {
        $lines = [(string) ($license['message'] ?? 'Foundry license status unavailable.')];
        $lines[] = 'Source: ' . (string) ($license['source'] ?? 'none');
        $lines[] = 'License path: ' . (string) ($license['license_path'] ?? '');

        $fingerprint = trim((string) ($license['fingerprint'] ?? ''));
        if ($fingerprint !== '') {
            $lines[] = 'Fingerprint: ' . $fingerprint;
        }

        $features = array_values(array_map('strval', (array) ($license['feature_flags'] ?? $license['features'] ?? [])));
        if ($features !== []) {
            $lines[] = 'Feature flags: ' . implode(', ', $features);
        }

        $tracking = is_array($license['usage_tracking'] ?? null) ? $license['usage_tracking'] : [];
        $lines[] = 'Usage tracking: ' . (((bool) ($tracking['enabled'] ?? false)) ? 'enabled' : 'disabled');
        $lines[] = 'Commands: ' . implode(', ', self::LICENSE_COMMANDS);
        $lines[] = 'Legacy aliases: ' . implode(', ', self::LEGACY_ALIASES);

        return implode(PHP_EOL, $lines);
    }
}
