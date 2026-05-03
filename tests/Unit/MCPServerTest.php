<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\MCP\MCPServer;
use Foundry\MCP\ToolHandler;
use Foundry\MCP\ToolRegistry;
use Foundry\Support\FoundryError;
use PHPUnit\Framework\TestCase;

final class MCPServerTest extends TestCase
{
    public function test_manifest_is_deterministic_and_sorted(): void
    {
        $registry = new ToolRegistry();
        $registry->register('z_tool', new class implements ToolHandler {
            public function handle(array $input): array
            {
                return ['ok' => true];
            }
        });
        $registry->register('a_tool', new class implements ToolHandler {
            public function handle(array $input): array
            {
                return ['ok' => true];
            }
        });

        $server = new MCPServer($registry);

        $this->assertSame([
            'name' => 'foundry-mcp',
            'tools' => ['a_tool', 'z_tool'],
        ], $server->manifest());
    }

    public function test_invoke_wraps_tool_and_data(): void
    {
        $registry = new ToolRegistry();
        $registry->register('echo', new class implements ToolHandler {
            public function handle(array $input): array
            {
                return ['echo' => $input];
            }
        });

        $server = new MCPServer($registry);
        $response = $server->invoke('echo', ['name' => 'pack']);

        $this->assertSame('echo', $response['tool']);
        $this->assertSame(['echo' => ['name' => 'pack']], $response['data']);
    }

    public function test_invoke_fails_for_unknown_tool(): void
    {
        $server = new MCPServer(new ToolRegistry());

        try {
            $server->invoke('missing');
            self::fail('Expected missing tool failure.');
        } catch (FoundryError $error) {
            $this->assertSame('MCP_TOOL_NOT_FOUND', $error->errorCode);
        }
    }
}
