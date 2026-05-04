<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Support\FoundryError;

final class VerifyCoverageCommand extends Command
{
    #[\Override]
    public function supportedSignatures(): array
    {
        return ['verify coverage'];
    }

    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'verify' && ($args[1] ?? null) === 'coverage';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $minimum = 90.0;
        $cloverPath = 'build/coverage/clover.xml';

        foreach (array_slice($args, 2) as $arg) {
            if (str_starts_with($arg, '--min=')) {
                $value = trim(substr($arg, strlen('--min=')));
                if ($value === '' || !is_numeric($value)) {
                    throw new FoundryError('CLI_VERIFY_COVERAGE_MIN_INVALID', 'validation', ['value' => $value], 'Coverage minimum must be numeric.');
                }

                $minimum = (float) $value;
                continue;
            }

            if (str_starts_with($arg, '--clover=')) {
                $value = trim(substr($arg, strlen('--clover=')));
                if ($value === '') {
                    throw new FoundryError('CLI_VERIFY_COVERAGE_CLOVER_REQUIRED', 'validation', [], 'Clover path is required.');
                }

                $cloverPath = $value;
                continue;
            }
        }

        if ($minimum < 0.0) {
            throw new FoundryError('CLI_VERIFY_COVERAGE_MIN_INVALID', 'validation', ['value' => $minimum], 'Coverage minimum must be greater than or equal to 0.');
        }

        $payload = $context->cloverCoverageVerifier()->verify($cloverPath, $minimum);
        $passed = (string) ($payload['status'] ?? 'fail') === 'pass';

        return [
            'status' => $passed ? 0 : 1,
            'message' => $passed ? 'Coverage verification passed.' : 'Coverage verification failed.',
            'payload' => $payload,
        ];
    }
}
