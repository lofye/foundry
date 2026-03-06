<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Queue\DefaultJobDispatcher;
use Foundry\Queue\JobDefinition;
use Foundry\Queue\JobRegistry;
use Foundry\Queue\RetryPolicy;
use Foundry\Queue\SyncQueueDriver;
use Foundry\Queue\Worker;
use PHPUnit\Framework\TestCase;

final class QueueTest extends TestCase
{
    public function test_dispatch_validates_payload_and_enqueues(): void
    {
        $registry = new JobRegistry();
        $registry->register(new JobDefinition(
            'notify_followers',
            [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['post_id'],
                'properties' => ['post_id' => ['type' => 'string']],
            ],
            'default',
            new RetryPolicy(3, [1, 5, 10]),
            30,
            'post_id'
        ));

        $driver = new SyncQueueDriver();
        $dispatcher = new DefaultJobDispatcher($registry, $driver);
        $dispatcher->dispatch('notify_followers', ['post_id' => 'p1']);

        $this->assertCount(1, $driver->inspect('default'));
    }

    public function test_worker_processes_jobs(): void
    {
        $registry = new JobRegistry();
        $registry->register(new JobDefinition(
            'notify_followers',
            ['type' => 'object', 'additionalProperties' => false, 'properties' => []],
            'default',
            new RetryPolicy(2, [1, 1]),
            30
        ));

        $driver = new SyncQueueDriver();
        $driver->enqueue('default', 'notify_followers', ['ok' => true]);

        $called = false;
        $worker = new Worker($driver, $registry, [
            'notify_followers' => static function () use (&$called): void {
                $called = true;
            },
        ]);

        $processed = $worker->process('default', 1);
        $this->assertSame(1, $processed);
        $this->assertTrue($called);
    }
}
