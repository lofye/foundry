<?php
declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;

final class CodemodRunCommand extends Command
{
    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'codemod'
            && ($args[1] ?? null) === 'run'
            && isset($args[2]);
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $codemod = (string) ($args[2] ?? '');
        $write = in_array('--write', $args, true);
        $dryRun = in_array('--dry-run', $args, true) || !$write;
        $path = $this->extractOption($args, '--path');

        $result = $context->codemodEngine()->run($codemod, $write, $path);

        return [
            'status' => 0,
            'message' => $write ? 'Codemod write complete.' : 'Codemod dry run complete.',
            'payload' => [
                'codemod' => $codemod,
                'mode' => $write ? 'write' : 'dry-run',
                'dry_run' => $dryRun,
                'path' => $path,
                'result' => $result->toArray(),
            ],
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
