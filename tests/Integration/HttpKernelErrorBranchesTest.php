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

final class HttpKernelErrorBranchesTest extends TestCase
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

    public function test_route_not_found_maps_to_404(): void
    {
        file_put_contents($this->project->root . '/app/generated/feature_index.php', '<?php return [];');
        file_put_contents($this->project->root . '/app/generated/routes.php', '<?php return [];');

        $kernel = $this->makeKernel($this->project->root);
        $response = $kernel->handle(new RequestContext('GET', '/missing'));

        $this->assertSame(404, $response['status']);
        $this->assertSame('ROUTE_NOT_FOUND', $response['body']['error']['code']);
    }

    public function test_unhandled_exception_maps_to_500(): void
    {
        $featureDir = $this->project->root . '/app/features/explode';
        mkdir($featureDir, 0777, true);
        file_put_contents($featureDir . '/input.schema.json', '{"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object","additionalProperties":false,"properties":{"x":{"type":"string"}}}');
        file_put_contents($featureDir . '/output.schema.json', '{"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object","additionalProperties":false,"properties":{}}');
        file_put_contents($featureDir . '/action.php', <<<'PHP'
<?php
declare(strict_types=1);

namespace App\Features\Explode;

use Foundry\Auth\AuthContext;
use Foundry\Feature\FeatureAction;
use Foundry\Feature\FeatureServices;
use Foundry\Http\RequestContext;

final class Action implements FeatureAction
{
    public function handle(array $input, RequestContext $request, AuthContext $auth, FeatureServices $services): array
    {
        throw new \RuntimeException('boom');
    }
}
PHP);

        file_put_contents($this->project->root . '/app/generated/feature_index.php', "<?php return ['explode' => ['kind' => 'http', 'description' => 'x', 'route' => ['method' => 'GET', 'path' => '/explode'], 'input_schema' => 'app/features/explode/input.schema.json', 'output_schema' => 'app/features/explode/output.schema.json', 'auth' => ['required' => false, 'strategies' => [], 'permissions' => []], 'database' => ['transactions' => 'required'], 'cache' => [], 'events' => [], 'jobs' => [], 'rate_limit' => [], 'tests' => [], 'llm' => [], 'base_path' => 'app/features/explode', 'action_class' => 'App\\\\Features\\\\Explode\\\\Action']];");
        file_put_contents($this->project->root . '/app/generated/routes.php', "<?php return ['GET /explode' => ['feature' => 'explode', 'kind' => 'http', 'input_schema' => 'app/features/explode/input.schema.json', 'output_schema' => 'app/features/explode/output.schema.json']];");

        $kernel = $this->makeKernel($this->project->root);
        $response = $kernel->handle(new RequestContext('GET', '/explode', [], [], ['x' => 'ok']));

        $this->assertSame(500, $response['status']);
        $this->assertSame('UNHANDLED_EXCEPTION', $response['body']['error']['code']);
    }

    private function makeKernel(string $root): HttpKernel
    {
        $permissions = new PermissionRegistry();
        $authorization = new AuthorizationEngine($permissions, ['bearer' => new HeaderTokenAuthenticator('x-user-id')]);

        $pdo = new \PDO('sqlite::memory:');
        $connection = new Connection($pdo);
        $traceContext = new TraceContext('trace');
        $trace = new TraceRecorder($traceContext);

        $services = new DefaultFeatureServices(
            new PdoQueryExecutor($connection, new QueryRegistry()),
            new CacheManager(new ArrayCacheStore(), new CacheRegistry()),
            new DefaultJobDispatcher(new JobRegistry(), new SyncQueueDriver(), $trace),
            new DefaultEventDispatcher(new EventRegistry(), $trace),
            new LocalStorageDriver($root . '/tmp-storage'),
            $traceContext,
            new AIManager(['static' => new StaticAIProvider('static', ['content' => 'ok'])])
        );

        $executor = new FeatureExecutor(
            new FeatureLoader(Paths::fromCwd($root)),
            $authorization,
            new JsonSchemaValidator(),
            new TransactionManager($connection),
            $services,
            $trace,
            new AuditRecorder(),
            Paths::fromCwd($root)
        );

        return new HttpKernel($executor, new StructuredLogger());
    }
}
