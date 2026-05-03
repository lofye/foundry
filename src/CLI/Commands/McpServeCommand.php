<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\MCP\MCPServer;
use Foundry\Support\FoundryError;
use Foundry\Support\Json;

final class McpServeCommand extends Command
{
    public function __construct(private readonly ?MCPServer $server = null) {}

    #[\Override]
    public function supportedSignatures(): array
    {
        return ['mcp:serve'];
    }

    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'mcp:serve';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $server = $this->server ?? MCPServer::boot();
        $tool = $this->extractOption($args, '--tool');

        if ($tool !== null) {
            $input = $this->decodeInput($this->extractOption($args, '--input'));

            return [
                'status' => 0,
                'message' => null,
                'payload' => $server->invoke($tool, $input),
            ];
        }

        $manifest = $server->manifest();
        echo Json::encode($manifest, true) . PHP_EOL;

        while (($line = fgets(STDIN)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            try {
                $request = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($request)) {
                    throw new FoundryError('MCP_REQUEST_INVALID', 'validation', [], 'MCP request must be a JSON object.');
                }

                $requestTool = (string) ($request['tool'] ?? '');
                $input = is_array($request['input'] ?? null) ? $request['input'] : [];
                $response = $server->invoke($requestTool, $input);
                echo Json::encode($response, true) . PHP_EOL;
            } catch (\Throwable $error) {
                $code = $error instanceof FoundryError ? $error->errorCode : 'MCP_REQUEST_FAILED';
                $message = $error->getMessage() !== '' ? $error->getMessage() : 'MCP request failed.';
                echo Json::encode([
                    'tool' => (string) (($request['tool'] ?? '') ?? ''),
                    'error' => ['code' => $code, 'message' => $message],
                ], true) . PHP_EOL;
            }
        }

        return [
            'status' => 0,
            'message' => null,
            'payload' => null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeInput(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            throw new FoundryError('MCP_INPUT_INVALID', 'validation', ['input' => $raw], 'MCP tool input must be valid JSON object.', 0, $error);
        }

        if (!is_array($decoded)) {
            throw new FoundryError('MCP_INPUT_INVALID', 'validation', ['input' => $raw], 'MCP tool input must decode to a JSON object.');
        }

        return $decoded;
    }

    private function extractOption(array $args, string $name): ?string
    {
        foreach ($args as $arg) {
            if (!str_starts_with($arg, $name . '=')) {
                continue;
            }

            return substr($arg, strlen($name) + 1);
        }

        return null;
    }
}
