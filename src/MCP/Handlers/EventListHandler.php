<?php

declare(strict_types=1);

namespace Foundry\MCP\Handlers;

use Foundry\MCP\CliReadBridge;
use Foundry\MCP\ToolHandler;

final class EventListHandler implements ToolHandler
{
    public function __construct(private readonly CliReadBridge $bridge) {}

    public function handle(array $input): array
    {
        return $this->bridge->run(['event:list']);
    }
}
