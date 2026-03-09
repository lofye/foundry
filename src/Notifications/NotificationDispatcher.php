<?php
declare(strict_types=1);

namespace Foundry\Notifications;

use Foundry\Support\FoundryError;

final class NotificationDispatcher
{
    public function __construct(
        private readonly NotificationRegistry $registry,
        private readonly NotificationTemplateRenderer $renderer = new NotificationTemplateRenderer(),
    ) {
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function dispatch(string $name, array $input, string $mode = 'queue'): array
    {
        $definition = $this->registry->get($name);
        $templatePath = (string) ($definition['template_path'] ?? '');
        if ($templatePath === '') {
            throw new FoundryError('NOTIFICATION_TEMPLATE_NOT_CONFIGURED', 'notifications', ['notification' => $name], 'Notification template path is not configured.');
        }

        $mode = strtolower($mode);
        if (!in_array($mode, ['queue', 'sync'], true)) {
            throw new FoundryError('NOTIFICATION_MODE_INVALID', 'validation', ['mode' => $mode], 'Notification mode must be queue or sync.');
        }

        if ($mode === 'queue') {
            return [
                'notification' => $name,
                'mode' => 'queue',
                'queue' => (string) ($definition['queue'] ?? 'default'),
                'status' => 'queued',
                'input' => $input,
            ];
        }

        $rendered = $this->renderer->render($this->absolutePath($templatePath), $input);

        return [
            'notification' => $name,
            'mode' => 'sync',
            'queue' => (string) ($definition['queue'] ?? 'default'),
            'status' => 'delivered',
            'input' => $input,
            'rendered' => $rendered,
        ];
    }

    private function absolutePath(string $templatePath): string
    {
        if (str_starts_with($templatePath, '/')) {
            return $templatePath;
        }

        return getcwd() . '/' . ltrim($templatePath, '/');
    }
}
