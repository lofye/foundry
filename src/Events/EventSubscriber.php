<?php
declare(strict_types=1);

namespace Forge\Events;

interface EventSubscriber
{
    public function eventName(): string;

    /**
     * @param array<string,mixed> $payload
     */
    public function handle(array $payload): void;
}
