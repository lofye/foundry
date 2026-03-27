<?php

declare(strict_types=1);

namespace Foundry\Notifications;

use Foundry\Support\FoundryError;

final class NotificationTemplateRenderer
{
    /**
     * @param array<string,mixed> $variables
     * @return array<string,string>
     */
    public function render(string $templatePath, array $variables): array
    {
        if (!is_file($templatePath)) {
            throw new FoundryError(
                'NOTIFICATION_TEMPLATE_NOT_FOUND',
                'notifications',
                ['template_path' => $templatePath],
                'Notification template not found.',
            );
        }

        $template = $this->loadTemplate($templatePath);
        $bindings = $this->normalizeBindings($variables);

        $rendered = [
            'subject' => $this->bind((string) ($template['subject'] ?? ''), $bindings),
            'text' => $this->bind((string) ($template['text'] ?? ''), $bindings),
            'html' => $this->bind((string) ($template['html'] ?? ''), $bindings),
        ];

        return $rendered;
    }

    /**
     * @return array<string,mixed>
     */
    private function loadTemplate(string $templatePath): array
    {
        if (str_ends_with($templatePath, '.php')) {
            /** @var mixed $raw */
            $raw = require $templatePath;
            if (!is_array($raw)) {
                throw new FoundryError(
                    'NOTIFICATION_TEMPLATE_INVALID',
                    'notifications',
                    ['template_path' => $templatePath],
                    'Notification PHP template must return an array.',
                );
            }

            return $raw;
        }

        $raw = file_get_contents($templatePath);
        if (!is_string($raw)) {
            throw new FoundryError(
                'NOTIFICATION_TEMPLATE_INVALID',
                'notifications',
                ['template_path' => $templatePath],
                'Notification template could not be read.',
            );
        }

        return [
            'subject' => '',
            'text' => $raw,
            'html' => '',
        ];
    }

    /**
     * @param array<string,mixed> $bindings
     */
    private function bind(string $template, array $bindings): string
    {
        foreach ($bindings as $key => $value) {
            $template = str_replace('{{' . $key . '}}', (string) $value, $template);
        }

        return $template;
    }

    /**
     * @param array<string,mixed> $variables
     * @return array<string,string>
     */
    private function normalizeBindings(array $variables): array
    {
        $bindings = [];
        foreach ($variables as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            if (is_bool($value)) {
                $bindings[$key] = $value ? 'true' : 'false';
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $bindings[$key] = (string) $value;
                continue;
            }

            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
            $bindings[$key] = is_string($encoded) ? $encoded : '';
        }

        ksort($bindings);

        return $bindings;
    }
}
