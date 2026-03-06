<?php
declare(strict_types=1);

namespace Foundry\Events;

final class InMemoryEventCollector implements EventSubscriber
{
    /**
     * @var array<int,array<string,mixed>>
     */
    private array $collected = [];

    public function __construct(private readonly string $eventName)
    {
    }

    #[\Override]
    public function eventName(): string
    {
        return $this->eventName;
    }

    #[\Override]
    public function handle(array $payload): void
    {
        $this->collected[] = $payload;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function collected(): array
    {
        return $this->collected;
    }
}
