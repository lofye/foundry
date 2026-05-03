<?php

declare(strict_types=1);

namespace Foundry\MCP;

interface ToolHandler
{
    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function handle(array $input): array;
}
