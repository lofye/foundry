<?php
declare(strict_types=1);

namespace Forge\Scheduler;

final class ScheduledTaskDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly string $frequency,
        public readonly \Closure $task,
    ) {
    }
}
