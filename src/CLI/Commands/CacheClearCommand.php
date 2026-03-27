<?php
declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;

final class CacheClearCommand extends Command
{
    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'cache' && ($args[1] ?? null) === 'clear';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $payload = $context->graphCompiler()->clearCache();

        return [
            'status' => 0,
            'message' => (($payload['cleared'] ?? false) === true) ? 'Compile cache cleared.' : 'Compile cache was already clear.',
            'payload' => $payload,
        ];
    }
}
