<?php

declare(strict_types=1);

namespace Foundry\MCP\Handlers;

use Foundry\MCP\CliReadBridge;
use Foundry\MCP\ToolHandler;
use Foundry\Support\FoundryError;

final class EventInspectHandler implements ToolHandler
{
    public function __construct(private readonly CliReadBridge $bridge) {}

    public function handle(array $input): array
    {
        $event = trim((string) ($input['event'] ?? $input['name'] ?? ''));
        if ($event === '') {
            throw new FoundryError('MCP_INPUT_INVALID', 'validation', ['tool' => 'event.inspect'], 'Input `event` is required.');
        }

        return $this->bridge->run(['event:inspect', $event]);
    }
}
