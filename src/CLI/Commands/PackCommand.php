<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Packs\PackManager;
use Foundry\Support\FoundryError;

final class PackCommand extends Command
{
    public function __construct(private readonly ?PackManager $manager = null) {}

    #[\Override]
    public function supportedSignatures(): array
    {
        return ['pack install', 'pack remove', 'pack list', 'pack info', 'pack search'];
    }

    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'pack'
            && in_array((string) ($args[1] ?? ''), ['install', 'remove', 'list', 'info', 'search'], true);
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $action = (string) ($args[1] ?? '');
        $manager = $this->manager ?? new PackManager($context->paths());

        return match ($action) {
            'install' => $this->result(
                payload: ['pack' => $manager->install((string) ($args[2] ?? ''), $context)],
                message: fn(array $payload): string => $this->renderInstall($payload['pack'] ?? []),
                json: $context->expectsJson(),
            ),
            'remove' => $this->result(
                payload: ['pack' => $manager->remove((string) ($args[2] ?? ''), $context)],
                message: fn(array $payload): string => $this->renderRemove($payload['pack'] ?? []),
                json: $context->expectsJson(),
            ),
            'list' => $this->result(
                payload: ['packs' => $manager->list()],
                message: fn(array $payload): string => $this->renderList((array) ($payload['packs'] ?? [])),
                json: $context->expectsJson(),
            ),
            'info' => $this->result(
                payload: ['pack' => $manager->info((string) ($args[2] ?? ''))],
                message: fn(array $payload): string => $this->renderInfo((array) ($payload['pack'] ?? [])),
                json: $context->expectsJson(),
            ),
            'search' => $this->result(
                payload: $manager->search((string) ($args[2] ?? '')),
                message: fn(array $payload): string => $this->renderSearch($payload),
                json: $context->expectsJson(),
            ),
            default => throw new FoundryError('PACK_COMMAND_INVALID', 'validation', ['action' => $action], 'Unsupported pack command.'),
        };
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{status:int,payload:array<string,mixed>|null,message:string|null}
     */
    private function result(array $payload, callable $message, bool $json): array
    {
        return [
            'status' => 0,
            'payload' => $payload,
            'message' => $json ? null : $message($payload),
        ];
    }

    /**
     * @param array<string,mixed> $pack
     */
    private function renderInstall(array $pack): string
    {
        $lines = [
            'Pack installed.',
            'Name: ' . (string) ($pack['pack'] ?? ''),
            'Version: ' . (string) ($pack['version'] ?? ''),
            'Path: ' . (string) ($pack['install_path'] ?? ''),
            'Active: yes',
        ];

        $source = is_array($pack['source'] ?? null) ? $pack['source'] : [];
        if (($source['type'] ?? null) === 'registry') {
            $lines[] = 'Source: hosted registry';
            $lines[] = 'Download: ' . (string) ($source['download_url'] ?? '');
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param array<string,mixed> $pack
     */
    private function renderRemove(array $pack): string
    {
        return implode(PHP_EOL, [
            'Pack deactivated.',
            'Name: ' . (string) ($pack['pack'] ?? ''),
            'Installed versions: ' . implode(', ', array_values(array_map('strval', (array) ($pack['installed_versions'] ?? [])))),
            'Active: no',
        ]);
    }

    /**
     * @param array<int,array<string,mixed>> $packs
     */
    private function renderList(array $packs): string
    {
        if ($packs === []) {
            return 'No packs installed.';
        }

        $lines = ['Installed packs:'];
        foreach ($packs as $pack) {
            if (!is_array($pack)) {
                continue;
            }

            $versions = implode(', ', array_values(array_map('strval', (array) ($pack['installed_versions'] ?? []))));
            $activeVersion = $pack['active_version'] ?? null;
            $status = $activeVersion !== null ? 'active ' . $activeVersion : 'inactive';
            $lines[] = '- ' . (string) ($pack['name'] ?? '') . ' [' . $status . '] installed: ' . $versions;
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param array<string,mixed> $pack
     */
    private function renderInfo(array $pack): string
    {
        $manifest = is_array($pack['manifest'] ?? null) ? $pack['manifest'] : [];

        return implode(PHP_EOL, [
            'Pack: ' . (string) ($pack['name'] ?? ''),
            'Version: ' . (string) ($pack['version'] ?? ''),
            'Active: ' . (($pack['active'] ?? false) ? 'yes' : 'no'),
            'Install path: ' . (string) ($pack['install_path'] ?? ''),
            'Description: ' . (string) ($manifest['description'] ?? ''),
            'Entry: ' . (string) ($manifest['entry'] ?? ''),
            'Capabilities: ' . implode(', ', array_values(array_map('strval', (array) ($pack['capabilities'] ?? [])))),
            'Installed versions: ' . implode(', ', array_values(array_map('strval', (array) ($pack['installed_versions'] ?? [])))),
        ]);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderSearch(array $payload): string
    {
        $query = (string) ($payload['query'] ?? '');
        $packs = is_array($payload['packs'] ?? null) ? $payload['packs'] : [];

        if ($packs === []) {
            return 'No hosted packs matched `' . $query . '`.';
        }

        $lines = [
            'Hosted pack results for `' . $query . '`:',
        ];

        foreach ($packs as $pack) {
            if (!is_array($pack)) {
                continue;
            }

            $lines[] = '- ' . (string) ($pack['name'] ?? '') . ' ' . (string) ($pack['version'] ?? '') . ': ' . (string) ($pack['description'] ?? '');
        }

        return implode(PHP_EOL, $lines);
    }
}
