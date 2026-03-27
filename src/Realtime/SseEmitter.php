<?php

declare(strict_types=1);

namespace Foundry\Realtime;

final class SseEmitter
{
    /**
     * @param array<int,array<string,mixed>> $events
     */
    public function render(array $events, int $retryMs = 15000): string
    {
        $lines = ['retry: ' . $retryMs];

        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }

            $id = (string) ($event['id'] ?? '');
            $name = (string) ($event['event'] ?? 'message');
            $data = $event['data'] ?? [];

            if ($id !== '') {
                $lines[] = 'id: ' . $id;
            }
            $lines[] = 'event: ' . $name;
            $payload = json_encode($data, JSON_UNESCAPED_SLASHES);
            $lines[] = 'data: ' . (is_string($payload) ? $payload : '{}');
            $lines[] = '';
        }

        return implode("\n", $lines) . "\n";
    }
}
