<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\CLI\CompletionService;
use Foundry\Support\FoundryError;

final class CompletionCommand extends Command
{
    #[\Override]
    public function supportedSignatures(): array
    {
        return ['completion'];
    }

    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'completion';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $shell = trim((string) ($args[1] ?? ''));
        if ($shell === '') {
            throw new FoundryError(
                'CLI_COMPLETION_SHELL_REQUIRED',
                'validation',
                [],
                'Completion shell required.',
            );
        }

        $service = new CompletionService($context->paths(), $context->apiSurfaceRegistry());

        if (in_array('--complete', $args, true)) {
            $index = $this->completionIndex($args);
            $current = $this->completionCurrent($args);
            $words = $this->completionWords($args);
            $candidates = $service->complete($shell, $words, $index, $current);

            return [
                'status' => 0,
                'message' => $context->expectsJson() ? null : implode(PHP_EOL, $candidates),
                'payload' => $context->expectsJson() ? [
                    'shell' => $shell,
                    'mode' => 'complete',
                    'candidates' => $candidates,
                ] : null,
            ];
        }

        $script = $service->script($shell);

        return [
            'status' => 0,
            'message' => $context->expectsJson() ? null : $script,
            'payload' => $context->expectsJson() ? [
                'shell' => $shell,
                'script' => $script,
            ] : null,
        ];
    }

    /**
     * @param list<string> $args
     */
    private function completionIndex(array $args): int
    {
        foreach ($args as $arg) {
            if (!str_starts_with($arg, '--index=')) {
                continue;
            }

            $value = substr($arg, strlen('--index='));
            if ($value !== '' && preg_match('/^\d+$/', $value) === 1) {
                return (int) $value;
            }
        }

        throw new FoundryError(
            'CLI_COMPLETION_CONTEXT_INVALID',
            'validation',
            ['args' => $args],
            'Completion context is invalid.',
        );
    }

    /**
     * @param list<string> $args
     */
    private function completionCurrent(array $args): string
    {
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--current=')) {
                return substr($arg, strlen('--current='));
            }
        }

        return '';
    }

    /**
     * @param list<string> $args
     * @return list<string>
     */
    private function completionWords(array $args): array
    {
        $separatorIndex = array_search('--', $args, true);
        if ($separatorIndex === false) {
            return [];
        }

        return array_values(array_slice($args, $separatorIndex + 1));
    }
}
