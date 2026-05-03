<?php

declare(strict_types=1);

namespace Foundry\MCP;

use Foundry\Support\FoundryError;

final class ToolRegistry
{
    /**
     * @var array<string,ToolHandler>
     */
    private array $handlers = [];

    public function register(string $name, ToolHandler $handler): void
    {
        $name = trim($name);
        if ($name === '') {
            throw new FoundryError(
                'MCP_TOOL_NAME_INVALID',
                'validation',
                ['name' => $name],
                'MCP tool name must be non-empty.',
            );
        }

        $this->handlers[$name] = $handler;
        ksort($this->handlers);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_values(array_keys($this->handlers));
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function invoke(string $name, array $input): array
    {
        $handler = $this->handlers[$name] ?? null;
        if (!$handler instanceof ToolHandler) {
            throw new FoundryError(
                'MCP_TOOL_NOT_FOUND',
                'not_found',
                ['tool' => $name, 'available_tools' => $this->names()],
                'MCP tool was not found.',
            );
        }

        return $handler->handle($input);
    }
}
