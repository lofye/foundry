<?php
declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Support\FoundryError;

final class GenerateIndexesCommand extends Command
{
    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'generate' && in_array(($args[1] ?? ''), ['indexes', 'migration'], true);
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $target = (string) ($args[1] ?? '');

        if ($target === 'indexes') {
            $files = $context->indexGenerator()->generate();

            return [
                'status' => 0,
                'message' => 'Indexes generated.',
                'payload' => ['files' => $files],
            ];
        }

        $definitionPath = (string) ($args[2] ?? '');
        if ($definitionPath === '') {
            throw new FoundryError('CLI_MIGRATION_DEFINITION_REQUIRED', 'validation', [], 'Migration definition path required.');
        }

        $out = $context->paths()->join('database/migrations');
        $file = $context->migrationGenerator()->generate($definitionPath, $out);

        return [
            'status' => 0,
            'message' => 'Migration generated.',
            'payload' => ['file' => $file],
        ];
    }
}
