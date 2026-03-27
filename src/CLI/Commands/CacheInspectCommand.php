<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;

final class CacheInspectCommand extends Command
{
    #[\Override]
    public function supportedSignatures(): array
    {
        return ['cache inspect'];
    }

    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'cache' && ($args[1] ?? null) === 'inspect';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $payload = $context->graphCompiler()->inspectCache();

        return [
            'status' => 0,
            'message' => $context->expectsJson() ? null : $this->renderMessage($payload),
            'payload' => $context->expectsJson() ? $payload : null,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderMessage(array $payload): string
    {
        $lines = [
            'Compile cache status: ' . (string) ($payload['status'] ?? 'unknown'),
            'Reason: ' . (string) ($payload['reason'] ?? 'n/a'),
            'Key: ' . (string) ($payload['key'] ?? ''),
        ];

        $missing = array_values(array_map('strval', (array) (($payload['artifacts']['missing'] ?? []))));
        if ($missing !== []) {
            $lines[] = 'Missing artifacts: ' . implode(', ', $missing);
        }

        return implode(PHP_EOL, $lines);
    }
}
