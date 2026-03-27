<?php

declare(strict_types=1);

namespace Foundry\Tests\Integration;

use Foundry\AI\AIManager;
use Foundry\AI\StaticAIProvider;
use Foundry\Auth\AuthorizationEngine;
use Foundry\Auth\HeaderTokenAuthenticator;
use Foundry\Auth\PermissionRegistry;
use Foundry\Cache\ArrayCacheStore;
use Foundry\Cache\CacheManager;
use Foundry\Cache\CacheRegistry;
use Foundry\DB\Connection;
use Foundry\DB\PdoQueryExecutor;
use Foundry\DB\QueryRegistry;
use Foundry\DB\TransactionManager;
use Foundry\Events\DefaultEventDispatcher;
use Foundry\Events\EventDefinition;
use Foundry\Events\EventRegistry;
use Foundry\Feature\DefaultFeatureServices;
use Foundry\Feature\FeatureExecutor;
use Foundry\Feature\FeatureLoader;
use Foundry\Http\HttpKernel;
use Foundry\Http\RequestContext;
use Foundry\Observability\AuditRecorder;
use Foundry\Observability\StructuredLogger;
use Foundry\Observability\TraceContext;
use Foundry\Observability\TraceRecorder;
use Foundry\Queue\DefaultJobDispatcher;
use Foundry\Queue\JobDefinition;
use Foundry\Queue\JobRegistry;
use Foundry\Queue\RetryPolicy;
use Foundry\Queue\SyncQueueDriver;
use Foundry\Schema\JsonSchemaValidator;
use Foundry\Storage\LocalStorageDriver;
use Foundry\Support\Paths;
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
            60,
        ));

        $traceContext = new TraceContext('trace-fixed');
        $trace = new TraceRecorder($traceContext);

        $services = new DefaultFeatureServices(
            $db,
            new CacheManager(new ArrayCacheStore(), new CacheRegistry()),
            new DefaultJobDispatcher($jobs, new SyncQueueDriver(), $trace),
            new DefaultEventDispatcher($eventRegistry, $trace),
            new LocalStorageDriver(sys_get_temp_dir() . '/foundry-storage-http'),
            $traceContext,
            new AIManager(['static' => new StaticAIProvider('static', ['content' => 'ok'])]),
        );

        $executor = new FeatureExecutor(
            new FeatureLoader($paths),
            $authorization,
            new JsonSchemaValidator(),
            $tx,
            $services,
            $trace,
            new AuditRecorder(),
            $paths,
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
