<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

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
use Foundry\Http\RequestContext;
use Foundry\Observability\AuditRecorder;
use Foundry\Observability\TraceContext;
use Foundry\Observability\TraceRecorder;
use Foundry\Queue\DefaultJobDispatcher;
use Foundry\Queue\JobRegistry;
use Foundry\Queue\SyncQueueDriver;
use Foundry\Schema\JsonSchemaValidator;
use Foundry\Storage\LocalStorageDriver;
use Foundry\Support\FoundryError;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class PipelineRuntimeExecutorTest extends TestCase
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

    public function test_runtime_enforces_csrf_guard_from_execution_plan(): void
    {
        $this->writeFeature('csrf_guarded', 'POST', '/csrf', false);
        $this->writePipelineIndexes('csrf_guarded', 'POST /csrf', [
            'guard:csrf:csrf_guarded' => [
                'id' => 'guard:csrf:csrf_guarded',
                'feature' => 'csrf_guarded',
                'type' => 'csrf',
                'stage' => 'before_validation',
                'config' => ['feature' => 'csrf_guarded', 'type' => 'csrf', 'required' => true],
            ],
            'guard:request_validation:csrf_guarded' => [
                'id' => 'guard:request_validation:csrf_guarded',
                'feature' => 'csrf_guarded',
                'type' => 'request_validation',
                'stage' => 'validation',
                'config' => ['feature' => 'csrf_guarded', 'type' => 'request_validation'],
            ],
        ], ['guard:csrf:csrf_guarded', 'guard:request_validation:csrf_guarded']);

        $executor = $this->makeExecutor($this->project->root, []);

        $this->expectException(FoundryError::class);
        $this->expectExceptionMessage('Missing CSRF token.');
        $executor->executeHttp(new RequestContext('POST', '/csrf'));
    }

    public function test_runtime_enforces_rate_limit_guard_from_execution_plan(): void
    {
        $this->writeFeature('rate_limited', 'POST', '/limited', false);
        $this->writePipelineIndexes('rate_limited', 'POST /limited', [
            'guard:rate_limit:rate_limited:bucket' => [
                'id' => 'guard:rate_limit:rate_limited:bucket',
                'feature' => 'rate_limited',
                'type' => 'rate_limit',
                'stage' => 'before_auth',
                'config' => [
                    'feature' => 'rate_limited',
                    'type' => 'rate_limit',
                    'strategy' => 'global',
                    'bucket' => 'bucket_rate_limited_pipeline_test',
                    'cost' => 1,
                    'limit' => 1,
                ],
            ],
            'guard:request_validation:rate_limited' => [
                'id' => 'guard:request_validation:rate_limited',
                'feature' => 'rate_limited',
                'type' => 'request_validation',
                'stage' => 'validation',
                'config' => ['feature' => 'rate_limited', 'type' => 'request_validation'],
            ],
        ], ['guard:rate_limit:rate_limited:bucket', 'guard:request_validation:rate_limited']);

        $executor = $this->makeExecutor($this->project->root, []);

        $first = $executor->executeHttp(new RequestContext('POST', '/limited', [], [], ['ok' => true]));
        $this->assertSame('ok-rate_limited', $first['id']);

        try {
            $executor->executeHttp(new RequestContext('POST', '/limited', [], [], ['ok' => true]));
            $this->fail('Expected rate limit exception.');
        } catch (FoundryError $error) {
            $this->assertSame('RATE_LIMIT_EXCEEDED', $error->errorCode);
        }
    }

    private function makeExecutor(string $root, array $permissions): FeatureExecutor
    {
        $perm = new PermissionRegistry();
        foreach ($permissions as $permission) {
            $perm->register($permission);
        }

        $authorization = new AuthorizationEngine($perm, ['bearer' => new HeaderTokenAuthenticator('x-user-id')]);

        $pdo = new \PDO('sqlite::memory:');
        $db = new PdoQueryExecutor(new Connection($pdo), new QueryRegistry());

        $traceContext = new TraceContext('trace-pipeline-runtime');
        $trace = new TraceRecorder($traceContext);

        $services = new DefaultFeatureServices(
            $db,
            new CacheManager(new ArrayCacheStore(), new CacheRegistry()),
            new DefaultJobDispatcher(new JobRegistry(), new SyncQueueDriver(), $trace),
            new DefaultEventDispatcher(new EventRegistry(), $trace),
            new LocalStorageDriver($root . '/tmp-storage'),
            $traceContext,
            new AIManager(['static' => new StaticAIProvider('static', ['content' => 'ok'])]),
        );

        return new FeatureExecutor(
            new FeatureLoader(Paths::fromCwd($root)),
            $authorization,
            new JsonSchemaValidator(),
            new TransactionManager(new Connection($pdo)),
            $services,
            $trace,
            new AuditRecorder(),
            Paths::fromCwd($root),
        );
    }

    private function writeFeature(string $name, string $method, string $path, bool $authRequired): void
    {
        $featureDir = $this->project->root . '/app/features/' . $name;
        mkdir($featureDir, 0777, true);

        $studly = str_replace(' ', '', ucwords(str_replace('_', ' ', $name)));

        file_put_contents($featureDir . '/action.php', <<<PHP
<?php
declare(strict_types=1);
namespace App\Features\\{$studly};
use Foundry\Feature\FeatureAction;
use Foundry\Feature\FeatureServices;
use Foundry\Auth\AuthContext;
use Foundry\Http\RequestContext;
final class Action implements FeatureAction {
    public function handle(array \$input, RequestContext \$request, AuthContext \$auth, FeatureServices \$services): array {
        return ['id' => 'ok-{$name}'];
    }
}
PHP);

        file_put_contents($featureDir . '/input.schema.json', '{"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object","additionalProperties":true,"properties":{}}');
        file_put_contents($featureDir . '/output.schema.json', '{"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object","additionalProperties":false,"required":["id"],"properties":{"id":{"type":"string"}}}');

        file_put_contents($this->project->root . '/app/generated/feature_index.php', "<?php return ['{$name}' => ['kind' => 'http', 'description' => 'x', 'route' => ['method' => '{$method}', 'path' => '{$path}'], 'input_schema' => 'app/features/{$name}/input.schema.json', 'output_schema' => 'app/features/{$name}/output.schema.json', 'auth' => ['required' => " . ($authRequired ? 'true' : 'false') . ", 'strategies' => ['bearer'], 'permissions' => []], 'database' => ['transactions' => 'required'], 'cache' => [], 'events' => [], 'jobs' => [], 'rate_limit' => [], 'tests' => [], 'llm' => [], 'base_path' => 'app/features/{$name}', 'action_class' => 'App\\\\Features\\\\{$studly}\\\\Action']];");
        file_put_contents($this->project->root . '/app/generated/routes.php', "<?php return ['{$method} {$path}' => ['feature' => '{$name}', 'kind' => 'http', 'input_schema' => 'app/features/{$name}/input.schema.json', 'output_schema' => 'app/features/{$name}/output.schema.json']];");
    }

    /**
     * @param array<string,array<string,mixed>> $guards
     * @param array<int,string> $guardIds
     */
    private function writePipelineIndexes(string $feature, string $routeSignature, array $guards, array $guardIds): void
    {
        $dir = $this->project->root . '/app/.foundry/build/projections';
        mkdir($dir, 0777, true);

        file_put_contents($dir . '/guard_index.php', '<?php return ' . var_export($guards, true) . ';');
        file_put_contents($dir . '/execution_plan_index.php', '<?php return ' . var_export([
            'by_feature' => [
                $feature => [
                    'id' => 'execution_plan:feature:' . $feature,
                    'feature' => $feature,
                    'route_signature' => $routeSignature,
                    'stages' => [
                        'request_received',
                        'routing',
                        'before_auth',
                        'auth',
                        'before_validation',
                        'validation',
                        'before_action',
                        'action',
                        'after_action',
                        'response_serialization',
                        'response_send',
                    ],
                    'guards' => $guardIds,
                    'interceptors' => [],
                    'action_node' => 'feature:' . $feature,
                    'plan_version' => 1,
                ],
            ],
            'by_route' => [],
        ], true) . ';');
        file_put_contents($dir . '/pipeline_index.php', '<?php return ' . var_export([
            'version' => 1,
            'order' => [
                'request_received',
                'routing',
                'before_auth',
                'auth',
                'before_validation',
                'validation',
                'before_action',
                'action',
                'after_action',
                'response_serialization',
                'response_send',
            ],
            'stages' => [],
            'links' => [],
        ], true) . ';');
    }
}
