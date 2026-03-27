<?php

declare(strict_types=1);

namespace Foundry\Scheduler;

final class SchedulerRegistry
{
    /**
     * @var array<string,ScheduledTaskDefinition>
     */
    private array $tasks = [];

    public function register(ScheduledTaskDefinition $task): void
    {
        $this->tasks[$task->name] = $task;
    }

    /**
     * @return array<string,ScheduledTaskDefinition>
     */
    public function all(): array
    {
        ksort($this->tasks);

        return $this->tasks;
    }
}
