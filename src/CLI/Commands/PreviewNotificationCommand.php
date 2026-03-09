<?php
declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Support\FoundryError;

final class PreviewNotificationCommand extends Command
{
    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'preview' && ($args[1] ?? null) === 'notification';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $name = (string) ($args[2] ?? '');
        if ($name === '') {
            throw new FoundryError('CLI_NOTIFICATION_REQUIRED', 'validation', [], 'Notification name required.');
        }

        $preview = $context->notificationPreviewer()->preview($name);

        return [
            'status' => 0,
            'message' => (string) ($preview['rendered']['text'] ?? 'Notification preview generated.'),
            'payload' => $preview,
        ];
    }
}
