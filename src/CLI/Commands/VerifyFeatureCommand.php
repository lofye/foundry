<?php
declare(strict_types=1);

namespace Forge\CLI\Commands;

use Forge\CLI\Command;
use Forge\CLI\CommandContext;
use Forge\Support\ForgeError;

final class VerifyFeatureCommand extends Command
{
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'verify' && ($args[1] ?? null) === 'feature';
    }

    public function run(array $args, CommandContext $context): array
    {
        $feature = (string) ($args[2] ?? '');
        if ($feature === '') {
            throw new ForgeError('CLI_FEATURE_REQUIRED', 'validation', [], 'Feature name required.');
        }

        $result = $context->featureVerifier()->verify($feature);

        return [
            'status' => $result->ok ? 0 : 1,
            'message' => $result->ok ? 'Feature verified.' : 'Feature verification failed.',
            'payload' => $result->toArray(),
        ];
    }
}
