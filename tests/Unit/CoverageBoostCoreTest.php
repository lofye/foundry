<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Auth\AuthorizationEngine;
use Foundry\Auth\PermissionRegistry;
use Foundry\Cache\CacheRegistry;
use Foundry\Config\ConfigRepository;
use Foundry\Config\ConfigSchemaRegistry;
use Foundry\Core\App;
use Foundry\Core\Environment;
use Foundry\Core\RuntimeFactory;
use Foundry\DB\Connection;
use Foundry\DB\DatabaseManager;
use Foundry\DB\QueryRegistry;
use Foundry\Feature\FeatureExecutor;
use Foundry\Feature\FeatureLoader;
use Foundry\Http\HttpKernel;
use Foundry\Http\RequestContext;
use Foundry\Observability\AuditRecorder;
use Foundry\Observability\MetricsRecorder;
use Foundry\Observability\StructuredLogger;
use Foundry\Observability\TraceContext;
use Foundry\Observability\TraceRecorder;
use Foundry\Queue\DatabaseQueueDriver;
use Foundry\Queue\JobRegistry;
use Foundry\Schema\JsonSchemaValidator;
use Foundry\Schema\Schema;
use Foundry\Schema\SchemaRegistry;
use Foundry\Storage\LocalStorageDriver;
use Foundry\Support\FoundryError;
use Foundry\Support\Paths;
use Foundry\Support\Result;
use Foundry\Testing\JobTestGenerator;
use Foundry\Tests\Fixtures\TempProject;
use Foundry\Webhook\IncomingWebhookDefinition;
use Foundry\Webhook\OutgoingWebhookDefinition;
use Foundry\Webhook\WebhookRegistry;
use PHPUnit\Framework\TestCase;

final class CoverageBoostCoreTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function test_request_from_globals_uses_superglobals_when_input_stream_is_empty(): void
    {
        $server = $_SERVER;
        $get = $_GET;
        $post = $_POST;

        try {
            $_SERVER = [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/publish?draft=1',
            ];
            $_GET = ['draft' => '1'];
            $_POST = ['title' => 'Hello'];

            $request = RuntimeFactory::requestFromGlobals();

            $this->assertSame('POST', $request->method());
            $this->assertSame('/publish', $request->path());
            $this->assertSame(['draft' => '1'], $request->query());
            $this->assertSame(['title' => 'Hello'], $request->body());
        } finally {
            $_SERVER = $server;
            $_GET = $get;
            $_POST = $post;
        }
    }

    public function test_runtime_factory_load_index_prefers_build_then_generated_and_rejects_non_arrays(): void
    {
        $paths = Paths::fromCwd($this->project->root);
        $generatedPath = $this->project->root . '/app/generated/permission_index.php';
        $buildPath = $this->project->root . '/app/.foundry/build/projections/permission_index.php';
        mkdir(dirname($buildPath), 0777, true);

        $this->assertSame([], $this->invokeRuntimeFactory('loadIndex', $paths, 'permission_index.php'));

        $this->writePhpArray($generatedPath, ['source' => 'generated']);
        $this->assertSame(['source' => 'generated'], $this->invokeRuntimeFactory('loadIndex', $paths, 'permission_index.php'));

        $this->writePhpArray($buildPath, ['source' => 'build']);
        $this->assertSame(['source' => 'build'], $this->invokeRuntimeFactory('loadIndex', $paths, 'permission_index.php'));

        file_put_contents($buildPath, "<?php\ndeclare(strict_types=1);\n\nreturn 'invalid';\n");

        $this->expectException(FoundryError::class);
        $this->expectExceptionMessage('Generated index must return an array.');
        $this->invokeRuntimeFactory('loadIndex', $paths, 'permission_index.php');
    }

    public function test_runtime_factory_registry_helpers_cover_projection_and_query_fallback_paths(): void
    {
        $paths = Paths::fromCwd($this->project->root);
        $projectionDir = $this->project->root . '/app/.foundry/build/projections';
        mkdir($projectionDir, 0777, true);

        $this->writePhpArray($projectionDir . '/permission_index.php', [
            'publish_post' => ['permissions' => ['posts.create', 'posts.publish']],
            'invalid' => 'not-an-array',
        ]);

        $this->writePhpArray($projectionDir . '/query_index.php', [
            ['feature' => 'publish_post', 'name' => 'insert_post', 'sql' => 'INSERT INTO posts(id) VALUES(:id)', 'placeholders' => ['id']],
            ['feature' => '', 'name' => 'ignored', 'sql' => 'SELECT 1', 'placeholders' => []],
            'invalid-row',
        ]);

        $this->writePhpArray($projectionDir . '/cache_index.php', [
            'posts:list' => ['kind' => 'computed', 'ttl_seconds' => 90, 'invalidated_by' => ['publish_post']],
            'invalid' => 'not-an-array',
        ]);

        $this->writePhpArray($projectionDir . '/event_index.php', [
            'emit' => [
                'post.created' => ['schema' => ['type' => 'object']],
                'invalid' => 'not-an-array',
            ],
        ]);

        $this->writePhpArray($projectionDir . '/job_index.php', [
            'notify_followers' => [
                'input_schema' => ['type' => 'object'],
                'queue' => 'default',
                'retry' => ['max_attempts' => 0, 'backoff_seconds' => [-1]],
                'timeout_seconds' => 0,
            ],
            'cleanup_posts' => [
                'retry' => ['max_attempts' => 2, 'backoff_seconds' => []],
            ],
            5 => 'invalid',
        ]);

        $permissions = $this->invokeRuntimeFactory('permissionRegistry', $paths);
        $queries = $this->invokeRuntimeFactory('queryRegistry', $paths);
        $cache = $this->invokeRuntimeFactory('cacheRegistry', $paths);
        $events = $this->invokeRuntimeFactory('eventRegistry', $paths);
        $jobs = $this->invokeRuntimeFactory('jobRegistry', $paths);

        $this->assertInstanceOf(PermissionRegistry::class, $permissions);
        $this->assertTrue($permissions->has('posts.create'));

        $this->assertInstanceOf(QueryRegistry::class, $queries);
        $this->assertTrue($queries->has('publish_post', 'insert_post'));

        $this->assertInstanceOf(CacheRegistry::class, $cache);
        $this->assertTrue($cache->has('posts:list'));
        $this->assertCount(1, $cache->invalidatedBy('publish_post'));

        $this->assertArrayHasKey('post.created', $events->allEvents());

        $notify = $jobs->get('notify_followers');
        $this->assertSame(1, $notify->retry->maxAttempts);
        $this->assertSame([1], $notify->retry->backoffSeconds);
        $this->assertSame(1, $notify->timeoutSeconds);
        $this->assertSame(2, $jobs->get('cleanup_posts')->retry->maxAttempts);

        @unlink($projectionDir . '/query_index.php');
        $featureDir = $this->project->root . '/app/features/fallback_feature';
        mkdir($featureDir, 0777, true);
        file_put_contents($featureDir . '/queries.sql', "-- name: list_posts\nSELECT * FROM posts;\n");

        $fallbackQueries = $this->invokeRuntimeFactory('queryRegistry', $paths);
        $this->assertTrue($fallbackQueries->has('fallback_feature', 'list_posts'));
    }

    public function test_runtime_factory_connection_and_http_kernel_factory_work_without_prebuilt_indexes(): void
    {
        $paths = Paths::fromCwd($this->project->root);
        @rmdir($this->project->root . '/storage');

        $connection = $this->invokeRuntimeFactory('connection', $paths);
        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertFileExists($this->project->root . '/database/foundry.sqlite');

        $kernel = RuntimeFactory::httpKernel($paths);
        $this->assertInstanceOf(HttpKernel::class, $kernel);

        $response = $kernel->handle(new RequestContext('GET', '/missing'));
        $this->assertSame(404, $response['status']);
    }

    public function test_core_registries_and_small_value_objects_are_exercised(): void
    {
        $schemaRegistry = new SchemaRegistry();
        $schemaRegistry->register(new Schema('app/features/a/input.schema.json', ['type' => 'object']));
        $schemaRegistry->register(new Schema('app/features/b/input.schema.json', ['type' => 'object']));
        $this->assertTrue($schemaRegistry->has('app/features/a/input.schema.json'));
        $this->assertSame(['app/features/a/input.schema.json', 'app/features/b/input.schema.json'], array_keys($schemaRegistry->all()));
        $this->expectException(FoundryError::class);
        $schemaRegistry->get('missing.schema.json');
    }

    public function test_core_app_getters_and_misc_primitives_are_covered(): void
    {
        $paths = Paths::fromCwd($this->project->root);
        $environment = new Environment('test', true, ['APP_ENV' => 'test']);
        $config = new ConfigRepository();
        $featureLoader = new FeatureLoader($paths);
        $schemaRegistry = new SchemaRegistry();
        $schemaValidator = new JsonSchemaValidator();
        $authz = new AuthorizationEngine(new PermissionRegistry());
        $logger = new StructuredLogger();
        $traceContext = new TraceContext('trace-app');
        $traceRecorder = new TraceRecorder($traceContext);
        $metrics = new MetricsRecorder();
        $audit = new AuditRecorder();

        $app = new App(
            $paths,
            $environment,
            $config,
            $featureLoader,
            $schemaRegistry,
            $schemaValidator,
            $authz,
            $logger,
            $traceContext,
            $traceRecorder,
            $metrics,
            $audit,
        );

        $executor = (new \ReflectionClass(FeatureExecutor::class))->newInstanceWithoutConstructor();
        $this->assertInstanceOf(HttpKernel::class, $app->httpKernel($executor));
        $this->assertSame($paths, $app->paths());
        $this->assertSame($environment, $app->env());
        $this->assertSame($config, $app->config());
        $this->assertSame($featureLoader, $app->featureLoader());
        $this->assertSame($schemaRegistry, $app->schemaRegistry());
        $this->assertSame($schemaValidator, $app->schemaValidator());
        $this->assertSame($authz, $app->authz());
        $this->assertSame($logger, $app->logger());
        $this->assertSame($traceContext, $app->traceContext());
        $this->assertSame($traceRecorder, $app->traceRecorder());
        $this->assertSame($metrics, $app->metrics());
        $this->assertSame($audit, $app->audit());

        $configSchema = new ConfigSchemaRegistry();
        $configSchema->register('auth', ['type' => 'object']);
        $configSchema->register('cache', ['type' => 'object']);
        $this->assertSame(['auth', 'cache'], array_keys($configSchema->all()));
        $this->assertNull($configSchema->get('missing'));

        $dbManager = new DatabaseManager();
        $dbManager->addConnection('default', new Connection(new \PDO('sqlite::memory:')));
        $this->assertInstanceOf(Connection::class, $dbManager->connection());

        $queue = new DatabaseQueueDriver();
        $queue->enqueue('default', 'job.a', ['id' => '1']);
        $this->assertCount(1, $queue->inspect('default'));
        $this->assertSame('job.a', $queue->dequeue('default')['job'] ?? null);

        $webhooks = new WebhookRegistry();
        $incoming = new IncomingWebhookDefinition('incoming.a', '/hooks/a', 'secret', ['type' => 'object']);
        $outgoing = new OutgoingWebhookDefinition('outgoing.a', 'https://example.test/hook', 'secret', ['X-Test' => '1']);
        $webhooks->registerIncoming($incoming);
        $webhooks->registerOutgoing($outgoing);
        $this->assertSame($incoming, $webhooks->incoming('incoming.a'));
        $this->assertSame($outgoing, $webhooks->outgoing('outgoing.a'));

        $generator = new JobTestGenerator();
        $generated = $generator->generate('publish_post');
        $this->assertStringContainsString('final class PublishPostJobTest', $generated);

        $ok = Result::ok(['value' => 1]);
        $err = Result::err('boom');
        $this->assertTrue($ok->ok);
        $this->assertSame(['value' => 1], $ok->value);
        $this->assertFalse($err->ok);
        $this->assertSame('boom', $err->error);

        $storage = new LocalStorageDriver($this->project->root . '/storage/files');
        $descriptor = $storage->write('docs/readme.txt', 'hi');
        $this->assertSame('docs/readme.txt', $descriptor->path);
        $this->assertTrue($storage->exists('docs/readme.txt'));
    }

    private function writePhpArray(string $path, array $payload): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents(
            $path,
            "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($payload, true) . ";\n",
        );
    }

    private function invokeRuntimeFactory(string $methodName, mixed ...$args): mixed
    {
        $invoker = \Closure::bind(
            static function (string $methodName, array $args): mixed {
                return RuntimeFactory::{$methodName}(...$args);
            },
            null,
            RuntimeFactory::class,
        );

        return $invoker($methodName, $args);
    }
}
