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
use Foundry\Queue\JobRegistry;
use Foundry\Queue\SyncQueueDriver;
use Foundry\Schema\JsonSchemaValidator;
use Foundry\Storage\LocalStorageDriver;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class HttpKernelStatusMappingTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->writeFeature();
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function test_authentication_error_maps_to_401(): void
    {
        $kernel = $this->makeKernel(true);
        $response = $kernel->handle(new RequestContext('POST', '/posts', [], [], ['title' => 'ok']));

        $this->assertSame(401, $response['status']);
        $this->assertSame('AUTHENTICATION_REQUIRED', $response['body']['error']['code']);
    }

    public function test_authorization_error_maps_to_403(): void
    {
        $kernel = $this->makeKernel(false);
        $response = $kernel->handle(new RequestContext('POST', '/posts', ['x-user-id' => 'u1'], [], ['title' => 'ok']));

        $this->assertSame(403, $response['status']);
        $this->assertSame('AUTHORIZATION_DENIED', $response['body']['error']['code']);
    }

    public function test_validation_error_maps_to_422(): void
    {
        $kernel = $this->makeKernel(true);
        $response = $kernel->handle(new RequestContext('POST', '/posts', ['x-user-id' => 'u1'], [], ['title' => 123]));

        $this->assertSame(422, $response['status']);
        $this->assertSame('FEATURE_INPUT_SCHEMA_VIOLATION', $response['body']['error']['code']);
    }

    private function makeKernel(bool $registerPermission): HttpKernel
    {
        $permissions = new PermissionRegistry();
        if ($registerPermission) {
            $permissions->register('posts.create');
        }

        $authorization = new AuthorizationEngine($permissions, ['bearer' => new HeaderTokenAuthenticator('x-user-id')]);
        $pdo = new \PDO('sqlite::memory:');
        $connection = new Connection($pdo);
        $traceContext = new TraceContext('trace-status');
        $trace = new TraceRecorder($traceContext);

        $services = new DefaultFeatureServices(
            new PdoQueryExecutor($connection, new QueryRegistry()),
            new CacheManager(new ArrayCacheStore(), new CacheRegistry()),
            new DefaultJobDispatcher(new JobRegistry(), new SyncQueueDriver(), $trace),
            new DefaultEventDispatcher(new EventRegistry(), $trace),
            new LocalStorageDriver($this->project->root . '/tmp-storage'),
            $traceContext,
            new AIManager(['static' => new StaticAIProvider('static', ['content' => 'ok'])]),
        );

        $executor = new FeatureExecutor(
            new FeatureLoader(Paths::fromCwd($this->project->root)),
            $authorization,
            new JsonSchemaValidator(),
            new TransactionManager($connection),
            $services,
            $trace,
            new AuditRecorder(),
            Paths::fromCwd($this->project->root),
        );

        return new HttpKernel($executor, new StructuredLogger());
    }

    private function writeFeature(): void
    {
        $featureDir = $this->project->root . '/app/features/publish_post';
        mkdir($featureDir, 0777, true);

        file_put_contents($featureDir . '/action.php', <<<'PHP'
<?php
declare(strict_types=1);

namespace App\Features\PublishPost;

use Foundry\Auth\AuthContext;
use Foundry\Feature\FeatureAction;
use Foundry\Feature\FeatureServices;
use Foundry\Http\RequestContext;

final class Action implements FeatureAction
{
    public function handle(array $input, RequestContext $request, AuthContext $auth, FeatureServices $services): array
    {
        return ['id' => 'p1'];
    }
}
PHP);

        file_put_contents($featureDir . '/input.schema.json', '{"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object","additionalProperties":false,"required":["title"],"properties":{"title":{"type":"string"}}}');
        file_put_contents($featureDir . '/output.schema.json', '{"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object","additionalProperties":false,"required":["id"],"properties":{"id":{"type":"string"}}}');

        file_put_contents($this->project->root . '/app/generated/feature_index.php', <<<'PHP'
<?php
return [
    'publish_post' => [
        'kind' => 'http',
        'description' => 'Create post',
        'route' => ['method' => 'POST', 'path' => '/posts'],
        'input_schema' => 'app/features/publish_post/input.schema.json',
        'output_schema' => 'app/features/publish_post/output.schema.json',
        'auth' => ['required' => true, 'strategies' => ['bearer'], 'permissions' => ['posts.create']],
        'database' => ['transactions' => 'required'],
        'cache' => [],
        'events' => [],
        'jobs' => [],
        'rate_limit' => [],
        'tests' => [],
        'llm' => [],
        'base_path' => 'app/features/publish_post',
        'action_class' => 'App\\Features\\PublishPost\\Action',
    ],
];
PHP);
        file_put_contents($this->project->root . '/app/generated/routes.php', <<<'PHP'
<?php
return [
    'POST /posts' => [
        'feature' => 'publish_post',
        'kind' => 'http',
        'input_schema' => 'app/features/publish_post/input.schema.json',
        'output_schema' => 'app/features/publish_post/output.schema.json',
    ],
];
PHP);
    }
}
