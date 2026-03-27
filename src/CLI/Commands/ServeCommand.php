<?php
declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;

final class ServeCommand extends Command
{
    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'serve';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $host = (string) ($args[1] ?? '127.0.0.1:8000');
        $publicIndex = $context->paths()->join('public/index.php');

        return [
            'status' => 0,
            'message' => 'Serve command configured.',
            'payload' => [
                'host' => $host,
                'public_index' => $publicIndex,
                'hint' => 'Run: php -S ' . $host . ' ' . $publicIndex,
            ],
        ];
    }
}
