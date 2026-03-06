<?php
declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Support\FoundryError;

final class VerifyContractsCommand extends Command
{
    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'verify' && in_array(($args[1] ?? ''), ['contracts', 'auth', 'cache', 'events', 'jobs', 'migrations'], true);
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $target = (string) ($args[1] ?? '');

        $result = match ($target) {
            'contracts' => $context->contractsVerifier()->verify(),
            'auth' => $context->authVerifier()->verify(),
            'cache' => $context->cacheVerifier()->verify(),
            'events' => $context->eventsVerifier()->verify(),
            'jobs' => $context->jobsVerifier()->verify(),
            'migrations' => $context->migrationsVerifier()->verify(),
            default => throw new FoundryError('CLI_VERIFY_TARGET_INVALID', 'validation', ['target' => $target], 'Unsupported verify target.'),
        };

        return [
            'status' => $result->ok ? 0 : 1,
            'message' => $result->ok ? 'Verification passed.' : 'Verification failed.',
            'payload' => $result->toArray(),
        ];
    }
}
