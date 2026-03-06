<?php
declare(strict_types=1);

namespace Foundry\Scheduler;

use Foundry\Observability\TraceRecorder;

final class Scheduler
{
    public function __construct(
        private readonly SchedulerRegistry $registry,
        private readonly ?TraceRecorder $traceRecorder = null,
    ) {
    }

    public function run(?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $ran = 0;

        foreach ($this->registry->all() as $task) {
            if (!$this->isDue($task->frequency, $now)) {
                continue;
            }

            $ran++;
            ($task->task)();
            $this->traceRecorder?->record($task->name, 'scheduler', 'task_run');
        }

        return $ran;
    }

    private function isDue(string $frequency, \DateTimeImmutable $now): bool
    {
        return match ($frequency) {
            'always' => true,
            'hourly' => (int) $now->format('i') === 0,
            'daily' => $now->format('H:i') === '00:00',
            default => false,
        };
    }
}
