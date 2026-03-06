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
use Forge\Feature\DefaultFeatureServices;
use Forge\Feature\FeatureExecutor;
use Forge\Feature\FeatureLoader;
use Forge\Http\HttpKernel;
use Forge\Http\RequestContext;
use Forge\Observability\AuditRecorder;
use Forge\Observability\StructuredLogger;
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

final class HttpKernelIntegrationTest extends TestCase
{
    public function test_returns_structured_validation_error_for_bad_input(): void
    {
        $paths = Paths::fromCwd(getcwd() ?: '.');

        $permissions = new PermissionRegistry();
        $permissions->register('posts.create');
        $authorization = new AuthorizationEngine($permissions, ['bearer' => new HeaderTokenAuthenticator('x-user-id')]);

        $pdo = new \PDO('sqlite::memory:');
        $tx = new TransactionManager(new Connection($pdo));
        $db = new PdoQueryExecutor(new Connection($pdo), new QueryRegistry());

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
            60
        ));

        $traceContext = new TraceContext('trace-fixed');
        $trace = new TraceRecorder($traceContext);

        $services = new DefaultFeatureServices(
            $db,
            new CacheManager(new ArrayCacheStore(), new CacheRegistry()),
            new DefaultJobDispatcher($jobs, new SyncQueueDriver(), $trace),
            new DefaultEventDispatcher($eventRegistry, $trace),
            new LocalStorageDriver(sys_get_temp_dir() . '/forge-storage-http'),
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

        $kernel = new HttpKernel($executor, new StructuredLogger());

        $response = $kernel->handle(new RequestContext('POST', '/posts', ['x-user-id' => 'u-1'], [], [
            'title' => 'Hello',
            'slug' => 'Not Valid',
            'body_markdown' => 'Body',
        ]));

        $this->assertSame(422, $response['status']);
        $this->assertSame('FEATURE_INPUT_SCHEMA_VIOLATION', $response['body']['error']['code']);
    }
}
