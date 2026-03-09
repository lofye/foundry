<?php
declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;

final class VerifyGraphCommand extends Command
{
    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'verify' && ($args[1] ?? null) === 'graph';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $result = $context->graphVerifier()->verify();

        return [
            'status' => $result->ok ? 0 : 1,
            'message' => $result->ok ? 'Graph verification passed.' : 'Graph verification failed.',
            'payload' => $result->toArray(),
        ];
    }
}
