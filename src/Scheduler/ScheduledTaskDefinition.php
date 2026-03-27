<?php

declare(strict_types=1);

namespace Foundry\Scheduler;

final readonly class ScheduledTaskDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly string $frequency,
        public readonly \Closure $task,
    ) {}
}
