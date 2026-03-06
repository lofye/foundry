<?php
declare(strict_types=1);

namespace Forge\Tests\Unit;

use Forge\Scheduler\ScheduledTaskDefinition;
use Forge\Scheduler\Scheduler;
use Forge\Scheduler\SchedulerRegistry;
use PHPUnit\Framework\TestCase;

final class SchedulerTest extends TestCase
{
    public function test_scheduler_runs_due_tasks(): void
    {
        $registry = new SchedulerRegistry();
        $count = 0;

        $registry->register(new ScheduledTaskDefinition('always_task', 'always', function () use (&$count): void {
            $count++;
        }));

        $scheduler = new Scheduler($registry);
        $ran = $scheduler->run(new \DateTimeImmutable('2026-01-01 12:34:00', new \DateTimeZone('UTC')));

        $this->assertSame(1, $ran);
        $this->assertSame(1, $count);
    }
}
