<?php
declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Support\FoundryError;

final class VerifyFeatureCommand extends Command
{
    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'verify' && ($args[1] ?? null) === 'feature';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $feature = (string) ($args[2] ?? '');
        if ($feature === '') {
            throw new FoundryError('CLI_FEATURE_REQUIRED', 'validation', [], 'Feature name required.');
        }

        $result = $context->featureVerifier()->verify($feature);

        return [
            'status' => $result->ok ? 0 : 1,
            'message' => $result->ok ? 'Feature verified.' : 'Feature verification failed.',
            'payload' => $result->toArray(),
        ];
    }
}
