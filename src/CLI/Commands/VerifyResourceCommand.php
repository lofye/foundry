<?php
declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Support\FoundryError;

final class VerifyResourceCommand extends Command
{
    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'verify' && ($args[1] ?? null) === 'resource';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $resource = (string) ($args[2] ?? '');
        if ($resource === '') {
            throw new FoundryError('CLI_RESOURCE_REQUIRED', 'validation', [], 'Resource name required.');
        }

        $result = $context->resourceVerifier()->verify($resource);

        return [
            'status' => $result->ok ? 0 : 1,
            'message' => $result->ok ? 'Resource verified.' : 'Resource verification failed.',
            'payload' => $result->toArray(),
        ];
    }
}
