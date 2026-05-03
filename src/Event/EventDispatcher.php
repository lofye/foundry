<?php

declare(strict_types=1);

namespace Foundry\Event;

use Foundry\Support\FoundryError;

final class EventDispatcher
{
    public function __construct(private readonly EventRegistry $registry) {}

    /**
     * @param array<string,mixed> $payload
     */
    public function dispatch(string $event, array $payload = []): void
    {
        $this->registry->beginDispatch();

        try {
            foreach ($this->registry->listenersFor($event) as $listener) {
                try {
                    ($listener['listener'])($payload);
                } catch (\Throwable $error) {
                    throw new FoundryError(
                        'EVENT_DISPATCH_FAILED',
                        'runtime',
                        [
                            'event' => $event,
                            'priority' => $listener['priority'],
                            'source' => $listener['source'],
                            'order' => $listener['order'],
                            'exception' => $error::class,
                        ],
                        'Event dispatch failed while invoking a listener.',
                        0,
                        $error,
                    );
                }
            }
        } finally {
            $this->registry->endDispatch();
        }
    }
}
