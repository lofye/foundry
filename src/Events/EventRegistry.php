<?php
declare(strict_types=1);

namespace Forge\Events;

use Forge\Support\ForgeError;

final class EventRegistry
{
    /**
     * @var array<string,EventDefinition>
     */
    private array $events = [];

    /**
     * @var array<string,array<int,EventSubscriber>>
     */
    private array $subscribers = [];

    public function registerEvent(EventDefinition $event): void
    {
        $this->events[$event->name] = $event;
    }

    public function registerSubscriber(EventSubscriber $subscriber): void
    {
        $event = $subscriber->eventName();
        $this->subscribers[$event] ??= [];
        $this->subscribers[$event][] = $subscriber;
    }

    public function event(string $name): EventDefinition
    {
        if (!isset($this->events[$name])) {
            throw new ForgeError('EVENT_NOT_FOUND', 'not_found', ['event' => $name], 'Event not found.');
        }

        return $this->events[$name];
    }

    /**
     * @return array<int,EventSubscriber>
     */
    public function subscribers(string $event): array
    {
        return $this->subscribers[$event] ?? [];
    }

    /**
     * @return array<string,EventDefinition>
     */
    public function allEvents(): array
    {
        ksort($this->events);

        return $this->events;
    }
}
