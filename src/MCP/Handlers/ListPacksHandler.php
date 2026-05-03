<?php

declare(strict_types=1);

namespace Foundry\MCP\Handlers;

use Foundry\MCP\CliReadBridge;
use Foundry\MCP\ToolHandler;

final class ListPacksHandler implements ToolHandler
{
    public function __construct(private readonly CliReadBridge $bridge) {}

    public function handle(array $input): array
    {
        $payload = $this->bridge->run(['pack', 'list']);
        $packs = is_array($payload['packs'] ?? null) ? $payload['packs'] : [];

        return [
            'packs' => array_values(array_map(
                static fn(array $pack): array => [
                    'name' => (string) ($pack['name'] ?? ''),
                    'version' => (string) (($pack['active_version'] ?? $pack['latest_version'] ?? '')),
                ],
                array_values(array_filter($packs, 'is_array')),
            )),
        ];
    }
}
