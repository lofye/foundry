<?php
declare(strict_types=1);

namespace Forge\CLI\Commands;

use Forge\CLI\Command;
use Forge\CLI\CommandContext;

final class ServeCommand extends Command
{
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'serve';
    }

    public function run(array $args, CommandContext $context): array
    {
        $host = (string) ($args[1] ?? '127.0.0.1:8000');
        $publicIndex = $context->paths()->join('app/platform/public/index.php');

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
