<?php

declare(strict_types=1);

namespace Foundry\Pro\CLI;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Pro\CLI\Concerns\InteractsWithPro;
use Foundry\Support\FoundryError;

final class ProCommand extends Command
{
    use InteractsWithPro;

    /**
     * @var array<int,string>
     */
    private const COMMANDS = [
        'doctor --deep',
        'explain <target>',
        'diff',
        'trace [<target>]',
        'generate <prompt...>',
    ];

    #[\Override]
    public function supportedSignatures(): array
    {
        return ['pro', 'pro enable', 'pro status'];
    }

    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'pro';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $subcommand = (string) ($args[1] ?? 'status');

        return match ($subcommand) {
            'enable' => $this->enable($args, $context),
            'status' => $this->status($context),
            default => throw new FoundryError(
                'CLI_PRO_SUBCOMMAND_NOT_FOUND',
                'not_found',
                ['subcommand' => $subcommand],
                'Pro subcommand not found.',
            ),
        };
    }

    /**
     * @param array<int,string> $args
     * @return array{status:int,payload:array<string,mixed>|null,message:string|null}
     */
    private function enable(array $args, CommandContext $context): array
    {
        $licenseKey = trim((string) ($args[2] ?? ''));
        if ($licenseKey === '') {
            throw new FoundryError(
                'PRO_LICENSE_KEY_REQUIRED',
                'validation',
                [],
                'A Foundry Pro license key is required.',
            );
        }

        $license = $this->licenseStore()->enable($licenseKey);

        return [
            'status' => 0,
            'message' => $context->expectsJson() ? null : $this->renderStatus($license),
            'payload' => $context->expectsJson()
                ? ['license' => $license, 'commands' => self::COMMANDS]
                : null,
        ];
    }

    /**
     * @return array{status:int,payload:array<string,mixed>|null,message:string|null}
     */
    private function status(CommandContext $context): array
    {
        $license = $this->proStatus();

        return [
            'status' => 0,
            'message' => $context->expectsJson() ? null : $this->renderStatus($license),
            'payload' => $context->expectsJson()
                ? ['license' => $license, 'commands' => self::COMMANDS]
                : null,
        ];
    }

    /**
     * @param array<string,mixed> $license
     */
    private function renderStatus(array $license): string
    {
        $lines = [(string) ($license['message'] ?? 'Foundry Pro status unavailable.')];
        $lines[] = 'License path: ' . (string) ($license['license_path'] ?? '');

        $fingerprint = trim((string) ($license['fingerprint'] ?? ''));
        if ($fingerprint !== '') {
            $lines[] = 'Fingerprint: ' . $fingerprint;
        }

        $features = array_values(array_map('strval', (array) ($license['features'] ?? [])));
        if ($features !== []) {
            $lines[] = 'Features: ' . implode(', ', $features);
        }

        $lines[] = 'Commands: ' . implode(', ', self::COMMANDS);

        return implode(PHP_EOL, $lines);
    }
}
