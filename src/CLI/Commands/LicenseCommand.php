<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Monetization\FeatureFlags;
use Foundry\Monetization\MonetizationService;
use Foundry\Support\FoundryError;

final class LicenseCommand extends Command
{
    /**
     * @var list<string>
     */
    private const LICENSE_COMMANDS = [
        'license status',
        'license activate [--key=YOUR_KEY]',
        'license deactivate',
    ];

    #[\Override]
    public function supportedSignatures(): array
    {
        return ['license status', 'license activate', 'license deactivate'];
    }

    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'license'
            && in_array($args[1] ?? null, ['status', 'activate', 'deactivate'], true);
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $subcommand = (string) ($args[1] ?? '');
        $service = new MonetizationService();

        return match ($subcommand) {
            'status' => $this->result($context, $service->status()),
            'activate' => $this->activate($args, $context, $service),
            'deactivate' => $this->result($context, $service->deactivate()),
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
        $licenseKey = '';

        foreach ($args as $index => $arg) {
            if (str_starts_with($arg, '--key=')) {
                $licenseKey = trim(substr($arg, strlen('--key=')));
                break;
            }

            if ($arg === '--key') {
                $licenseKey = trim((string) ($args[$index + 1] ?? ''));
                break;
            }
        }

        if ($licenseKey === '') {
            $licenseKey = trim((string) ($args[2] ?? ''));
        }

        if ($licenseKey === '') {
            throw new FoundryError(
                'LICENSE_KEY_REQUIRED',
                'validation',
                [],
                'A Foundry license key is required.',
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
        $active = (($license['valid'] ?? false) === true);
        $capabilities = array_values(array_filter(array_map('strval', (array) ($license['capabilities'] ?? []))));
        $services = is_array($license['service_access'] ?? null) ? $license['service_access'] : [];
        $usageTracking = is_array($license['usage_tracking'] ?? null) ? $license['usage_tracking'] : ['enabled' => false];
        $lines = [
            'License: ' . ($active ? 'Active' : 'Not active'),
            'Tier: ' . (string) ($license['tier'] ?? FeatureFlags::TIER_FREE),
            '',
            'Core capabilities remain available without a license.',
        ];

        if ($capabilities !== []) {
            $lines[] = 'Capabilities: ' . implode(', ', $capabilities);
        }

        $lines[] = '';
        $lines[] = 'Service access:';
        array_push($lines, ...$this->serviceLines($services));

        $source = trim((string) ($license['source'] ?? ''));
        if ($active && $source !== '' && $source !== 'none') {
            $lines[] = '';
            $lines[] = 'Source: ' . $source;
        }

        if (!$active) {
            $lines[] = '';
            $lines[] = 'A license may be used for future identity and service access.';
            $lines[] = 'Activate with:';
            $lines[] = '  foundry license activate --key=YOUR_KEY';
        }

        $lines[] = '';
        $lines[] = 'Usage tracking: ' . (((bool) ($usageTracking['enabled'] ?? false))
            ? 'enabled (opt-in local analytics)'
            : 'disabled (opt-in local analytics)');

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param array<string,mixed> $services
     * @return list<string>
     */
    private function serviceLines(array $services): array
    {
        $available = array_values(array_filter(array_map('strval', (array) ($services['available'] ?? []))));
        $unavailable = array_values(array_filter(array_map('strval', (array) ($services['unavailable'] ?? []))));
        $lines = [];

        foreach ($available as $service) {
            $lines[] = '- ' . $service . ': available';
        }

        foreach ($unavailable as $service) {
            $lines[] = '- ' . $service . ': unavailable';
        }

        if ($lines === []) {
            return ['- none'];
        }

        return $lines;
    }
}
