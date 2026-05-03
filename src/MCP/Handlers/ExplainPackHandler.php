<?php

declare(strict_types=1);

namespace Foundry\MCP\Handlers;

use Foundry\MCP\CliReadBridge;
use Foundry\MCP\ToolHandler;
use Foundry\Support\FoundryError;

final class ExplainPackHandler implements ToolHandler
{
    public function __construct(private readonly CliReadBridge $bridge) {}

    public function handle(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new FoundryError('MCP_INPUT_INVALID', 'validation', ['tool' => 'explain_pack'], 'Input `name` is required.');
        }

        return $this->bridge->run(['explain', 'pack:' . $name]);
    }
}
