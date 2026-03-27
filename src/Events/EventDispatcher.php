<?php

declare(strict_types=1);

namespace Foundry\Events;

interface EventDispatcher
{
    /**
     * @param array<string,mixed> $payload
     */
    public function emit(string $eventName, array $payload): void;
}
