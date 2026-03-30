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
        'license activate [--key=<license-key>]',
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
        $lines = [
            'License: ' . ($active ? 'Active' : 'Not active'),
            'Tier: ' . (string) ($license['tier'] ?? FeatureFlags::TIER_FREE),
            '',
        ];

        if ($active) {
            $lines[] = 'Enabled features:';
            array_push($lines, ...$this->featureLines(
                (array) (($license['public_features']['enabled'] ?? [])),
                null,
            ));
            $lines[] = '';
            $lines[] = 'Disabled features:';
            array_push($lines, ...$this->featureLines(
                (array) (($license['public_features']['disabled'] ?? [])),
                3,
            ));

            $source = trim((string) ($license['source'] ?? ''));
            if ($source !== '' && $source !== 'none') {
                $lines[] = '';
                $lines[] = 'Source: ' . $source;
            }
        } else {
            $lines[] = 'Some advanced features are unavailable.';
            $lines[] = '';
            $lines[] = 'Disabled features:';
            array_push($lines, ...$this->featureLines(
                (array) (($license['public_features']['disabled'] ?? [])),
                3,
            ));

            if (($license['status'] ?? 'missing') === 'invalid') {
                $details = trim((string) ($license['message'] ?? ''));
                if ($details !== '') {
                    $lines[] = '';
                    $lines[] = 'Details: ' . $details;
                }
            }

            $lines[] = '';
            $lines[] = 'Activate with:';
            $lines[] = '  foundry license activate --key=YOUR_KEY';
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param array<int,mixed> $features
     * @return list<string>
     */
    private function featureLines(array $features, ?int $limit): array
    {
        $names = array_values(array_filter(array_map('strval', $features), static fn(string $name): bool => trim($name) !== ''));

        if ($names === []) {
            return ['- none'];
        }

        $visible = $limit === null ? $names : array_slice($names, 0, $limit);
        $lines = array_values(array_map(
            static fn(string $name): string => '- ' . $name,
            $visible,
        ));

        if ($limit !== null && count($names) > $limit) {
            $lines[] = '- and ' . (count($names) - $limit) . ' more';
        }

        return $lines;
    }
}
