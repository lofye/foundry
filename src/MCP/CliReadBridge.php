<?php

declare(strict_types=1);

namespace Foundry\MCP;

use Foundry\CLI\Application;
use Foundry\Support\FoundryError;

final class CliReadBridge
{
    public function __construct(private readonly ?Application $application = null) {}

    /**
     * @param list<string> $args
     * @return array<string,mixed>
     */
    public function run(array $args): array
    {
        $app = $this->application ?? new Application();

        ob_start();
        $status = $app->run(array_merge(['foundry'], $args, ['--json']));
        $raw = trim((string) ob_get_clean());

        if ($raw === '') {
            throw new FoundryError(
                'MCP_CLI_EMPTY_OUTPUT',
                'runtime',
                ['args' => $args, 'status' => $status],
                'CLI bridge returned empty output.',
            );
        }

        /** @var array<string,mixed> $payload */
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            throw new FoundryError(
                'MCP_CLI_INVALID_OUTPUT',
                'runtime',
                ['args' => $args, 'status' => $status, 'output' => $raw],
                'CLI bridge returned non-JSON output.',
            );
        }

        if ($status !== 0) {
            $error = is_array($payload['error'] ?? null) ? $payload['error'] : [];
            throw new FoundryError(
                (string) ($error['code'] ?? 'MCP_TOOL_CLI_FAILED'),
                'runtime',
                ['args' => $args, 'status' => $status, 'payload' => $payload],
                (string) ($error['message'] ?? 'CLI read command failed.'),
            );
        }

        return $payload;
    }
}
