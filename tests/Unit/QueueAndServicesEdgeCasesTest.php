<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\AI\AIManager;
use Foundry\AI\StaticAIProvider;
use Foundry\Cache\ArrayCacheStore;
use Foundry\Cache\CacheManager;
use Foundry\Cache\CacheRegistry;
use Foundry\DB\Connection;
use Foundry\DB\PdoQueryExecutor;
use Foundry\DB\QueryRegistry;
use Foundry\Events\DefaultEventDispatcher;
use Foundry\Events\EventRegistry;
use Foundry\Feature\DefaultFeatureServices;
use Foundry\Observability\TraceContext;
use Foundry\Observability\TraceRecorder;
use Foundry\Queue\JobDefinition;
use Foundry\Queue\JobRegistry;
use Foundry\Queue\RetryPolicy;
use Foundry\Queue\SyncQueueDriver;
use Foundry\Queue\Worker;
use Foundry\Storage\LocalStorageDriver;
use Foundry\Support\FoundryError;
use PHPUnit\Framework\TestCase;

final class QueueAndServicesEdgeCasesTest extends TestCase
{
    public function test_job_registry_and_default_feature_services_getters(): void
    {
        $registry = new JobRegistry();
        $registry->register(new JobDefinition(
            'b_job',
            ['type' => 'object', 'additionalProperties' => false, 'properties' => []],
            'default',
            new RetryPolicy(1, [1]),
            10,
        ));
        $registry->register(new JobDefinition(
            'a_job',
            ['type' => 'object', 'additionalProperties' => false, 'properties' => []],
            'default',
            new RetryPolicy(1, [1]),
            10,
        ));

        $this->assertTrue($registry->has('a_job'));
        $this->assertFalse($registry->has('missing'));
        $this->assertSame('a_job', $registry->get('a_job')->name);
        $this->assertSame(['a_job', 'b_job'], array_keys($registry->all()));

        $this->expectException(FoundryError::class);
        $registry->get('missing');
    }

    public function test_worker_handles_retry_success_missing_handler_and_retry_exhaustion(): void
    {
        $registry = new JobRegistry();
        $registry->register(new JobDefinition(
            'retry_job',
            ['type' => 'object', 'additionalProperties' => false, 'properties' => []],
            'default',
            new RetryPolicy(2, [1, 1]),
            30,
        ));

        $driver = new SyncQueueDriver();
        $trace = new TraceRecorder(new TraceContext('trace-queue'));
        $attempts = 0;
        $worker = new Worker($driver, $registry, [
            'retry_job' => static function () use (&$attempts): void {
                $attempts++;
                if ($attempts === 1) {
                    throw new \RuntimeException('first attempt fails');
                }
            },
        ], $trace);

        $driver->enqueue('default', 'retry_job', []);
        $this->assertSame(1, $worker->process('default', 1));
        $this->assertSame(2, $attempts);

        $driver->enqueue('default', 'retry_job', []);
        $workerNoHandler = new Worker($driver, $registry, [], $trace);
        try {
            $workerNoHandler->process('default', 1);
            $this->fail('Expected missing handler exception');
        } catch (FoundryError $e) {
            $this->assertSame('JOB_HANDLER_NOT_FOUND', $e->errorCode);
        }

        $alwaysFailAttempts = 0;
        $driver->enqueue('default', 'retry_job', []);
        $workerAlwaysFails = new Worker($driver, $registry, [
            'retry_job' => static function () use (&$alwaysFailAttempts): void {
                $alwaysFailAttempts++;
                throw new \RuntimeException('always fails');
            },
        ], $trace);

        $this->expectException(\RuntimeException::class);
        $workerAlwaysFails->process('default', 1);
    }

    public function test_worker_returns_zero_when_queue_is_empty(): void
    {
        $registry = new JobRegistry();
        $driver = new SyncQueueDriver();
        $worker = new Worker($driver, $registry, []);

        $this->assertSame(0, $worker->process('default', 5));
    }

    public function test_default_feature_services_returns_all_dependencies(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $db = new PdoQueryExecutor(new Connection($pdo), new QueryRegistry());
        $cache = new CacheManager(new ArrayCacheStore(), new CacheRegistry());
        $jobs = new \Foundry\Queue\DefaultJobDispatcher(new JobRegistry(), new SyncQueueDriver());
        $events = new DefaultEventDispatcher(new EventRegistry());
        $storage = new LocalStorageDriver(sys_get_temp_dir() . '/foundry-storage-services');
        $trace = new TraceContext('trace-services');
        $ai = new AIManager(['static' => new StaticAIProvider('static', ['content' => 'ok'])]);

        $services = new DefaultFeatureServices($db, $cache, $jobs, $events, $storage, $trace, $ai);

        $this->assertSame($db, $services->db());
        $this->assertSame($cache, $services->cache());
        $this->assertSame($jobs, $services->jobs());
        $this->assertSame($events, $services->events());
        $this->assertSame($storage, $services->storage());
        $this->assertSame($trace, $services->trace());
        $this->assertSame($ai, $services->ai());
    }
}
