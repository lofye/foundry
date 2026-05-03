<?php

declare(strict_types=1);

namespace Foundry\MCP\Handlers;

use Foundry\MCP\CliReadBridge;
use Foundry\MCP\ToolHandler;
use Foundry\Support\FoundryError;

final class ExplainTargetHandler implements ToolHandler
{
    public function __construct(private readonly CliReadBridge $bridge) {}

    public function handle(array $input): array
    {
        $target = trim((string) ($input['target'] ?? ''));
        if ($target === '') {
            throw new FoundryError('MCP_INPUT_INVALID', 'validation', ['tool' => 'explain_target'], 'Input `target` is required.');
        }

        return $this->bridge->run(['explain', $target]);
    }
}
