<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Support\FoundryError;

final class VerifyPlatformCommand extends Command
{
    #[\Override]
    public function supportedSignatures(): array
    {
        return ['verify billing', 'verify workflows', 'verify orchestrations', 'verify search', 'verify streams', 'verify locales', 'verify policies'];
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

        $result = match ($target) {
            'billing' => $context->billingVerifier()->verify($this->extractOption($args, '--provider')),
            'workflows' => $context->workflowVerifier()->verify($this->extractOption($args, '--name')),
            'orchestrations' => $context->orchestrationsVerifier()->verify($this->extractOption($args, '--name')),
            'search' => $context->searchVerifier()->verify($this->extractOption($args, '--name')),
            'streams' => $context->streamsVerifier()->verify($this->extractOption($args, '--name')),
            'locales' => $context->localesVerifier()->verify($this->extractOption($args, '--bundle')),
            'policies' => $context->policiesVerifier()->verify($this->extractOption($args, '--name')),
            default => throw new FoundryError('CLI_VERIFY_TARGET_INVALID', 'validation', ['target' => $target], 'Unsupported verify target.'),
        };

        return [
            'status' => $result->ok ? 0 : 1,
            'message' => $result->ok ? 'Verification passed.' : 'Verification failed.',
            'payload' => $result->toArray(),
        ];
    }

    /**
     * @param array<int,string> $args
     */
    private function extractOption(array $args, string $name): ?string
    {
        foreach ($args as $index => $arg) {
            if (str_starts_with($arg, $name . '=')) {
                $value = substr($arg, strlen($name . '='));

                return $value !== '' ? $value : null;
            }

            if ($arg === $name) {
                $value = (string) ($args[$index + 1] ?? '');

                return $value !== '' ? $value : null;
            }
        }

        return null;
    }
}
