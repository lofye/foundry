<?php
declare(strict_types=1);

namespace Forge\Tests\Integration;

use Forge\AI\AIManager;
use Forge\AI\StaticAIProvider;
use Forge\Auth\AuthorizationEngine;
use Forge\Auth\HeaderTokenAuthenticator;
use Forge\Auth\PermissionRegistry;
use Forge\Cache\ArrayCacheStore;
use Forge\Cache\CacheManager;
use Forge\Cache\CacheRegistry;
use Forge\DB\Connection;
use Forge\DB\PdoQueryExecutor;
use Forge\DB\QueryRegistry;
use Forge\DB\TransactionManager;
use Forge\Events\DefaultEventDispatcher;
use Forge\Events\EventDefinition;
use Forge\Events\EventRegistry;
use Forge\Events\InMemoryEventCollector;
use Forge\Feature\DefaultFeatureServices;
use Forge\Feature\FeatureExecutor;
use Forge\Feature\FeatureLoader;
use Forge\Http\RequestContext;
use Forge\Observability\AuditRecorder;
use Forge\Observability\TraceContext;
use Forge\Observability\TraceRecorder;
use Forge\Queue\DefaultJobDispatcher;
use Forge\Queue\JobDefinition;
use Forge\Queue\JobRegistry;
use Forge\Queue\RetryPolicy;
use Forge\Queue\SyncQueueDriver;
use Forge\Schema\JsonSchemaValidator;
use Forge\Storage\LocalStorageDriver;
use Forge\Support\Paths;
use PHPUnit\Framework\TestCase;

final class FeatureExecutionIntegrationTest extends TestCase
{
    public function test_executes_publish_post_pipeline_with_events_and_jobs(): void
    {
        $paths = Paths::fromCwd(getcwd() ?: '.');

        $permissions = new PermissionRegistry();
        $permissions->register('posts.create');

        $authorization = new AuthorizationEngine($permissions, ['bearer' => new HeaderTokenAuthenticator('x-user-id')]);

        $pdo = new \PDO('sqlite::memory:');
        $tx = new TransactionManager(new Connection($pdo));
        $db = new PdoQueryExecutor(new Connection($pdo), new QueryRegistry());

        $cache = new CacheManager(new ArrayCacheStore(), new CacheRegistry());

        $eventRegistry = new EventRegistry();
        $eventRegistry->registerEvent(new EventDefinition('post.created', [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['post_id', 'author_id', 'status'],
            'properties' => [
                'post_id' => ['type' => 'string'],
                'author_id' => ['type' => 'string'],
                'status' => ['type' => 'string'],
            ],
        ]));
        $collector = new InMemoryEventCollector('post.created');
        $eventRegistry->registerSubscriber($collector);

        $queue = new SyncQueueDriver();
        $jobs = new JobRegistry();
        $jobs->register(new JobDefinition(
            'notify_followers',
            [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['post_id'],
                'properties' => ['post_id' => ['type' => 'string']],
            ],
            'default',
            new RetryPolicy(3, [1, 5, 30]),
            60,
            'post_id'
        ));

        $traceContext = new TraceContext('trace-fixed');
        $trace = new TraceRecorder($traceContext);

        $services = new DefaultFeatureServices(
            $db,
            $cache,
            new DefaultJobDispatcher($jobs, $queue, $trace),
            new DefaultEventDispatcher($eventRegistry, $trace),
            new LocalStorageDriver(sys_get_temp_dir() . '/forge-storage-int'),
            $traceContext,
            new AIManager(['static' => new StaticAIProvider('static', ['content' => 'ok'])])
        );

        $executor = new FeatureExecutor(
            new FeatureLoader($paths),
            $authorization,
            new JsonSchemaValidator(),
            $tx,
            $services,
            $trace,
            new AuditRecorder(),
            $paths
        );

        $output = $executor->executeHttp(new RequestContext('POST', '/posts', ['x-user-id' => 'u-1'], [], [
            'title' => 'Hello',
            'slug' => 'hello',
            'body_markdown' => 'Body',
            'publish_now' => true,
        ]));

        $this->assertSame('Hello', $output['title']);
        $this->assertCount(1, $collector->collected());
        $this->assertCount(1, $queue->inspect('default'));
        $this->assertNotEmpty($trace->events());
    }
}
