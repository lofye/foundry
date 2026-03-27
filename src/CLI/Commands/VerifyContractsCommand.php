<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Application;
use Foundry\CLI\CliSurfaceVerifier;
use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Support\FoundryError;

final class VerifyContractsCommand extends Command
{
    #[\Override]
    public function supportedSignatures(): array
    {
        return ['verify contracts', 'verify cli-surface', 'verify auth', 'verify cache', 'verify events', 'verify jobs', 'verify migrations'];
    }

    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'verify' && $this->supportsSignature('verify ' . (string) ($args[1] ?? ''));
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $target = (string) ($args[1] ?? '');
        if ($target === 'cli-surface') {
            $payload = (new CliSurfaceVerifier(
                $context->apiSurfaceRegistry(),
                Application::registeredCommands(),
            ))->verify();
            $status = ((int) ($payload['invalid'] ?? 0) > 0
                || (int) ($payload['ambiguous'] ?? 0) > 0
                || (int) ($payload['orphan_handlers'] ?? 0) > 0)
                ? 1
                : 0;

            return [
                'status' => $status,
                'message' => $status === 0 ? 'CLI surface verification passed.' : 'CLI surface verification failed.',
                'payload' => $payload,
            ];
        }

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
