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

final class FeatureExecutorEdgeCasesTest extends TestCase
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

    public function test_route_not_found_throws(): void
    {
        file_put_contents($this->project->root . '/app/generated/feature_index.php', '<?php return [];');
        file_put_contents($this->project->root . '/app/generated/routes.php', '<?php return [];');

        $executor = $this->makeExecutor($this->project->root, ['posts.create']);

        $this->expectException(FoundryError::class);
        $executor->executeHttp(new RequestContext('GET', '/missing'));
    }

    public function test_authorization_denied_throws(): void
    {
        $this->writeFeature('secure_feature', '/secure', true, <<<'PHP'
<?php
declare(strict_types=1);
namespace App\Features\SecureFeature;
use Foundry\Feature\FeatureAction;
use Foundry\Feature\FeatureServices;
use Foundry\Auth\AuthContext;
use Foundry\Http\RequestContext;
final class Action implements FeatureAction { public function handle(array $input, RequestContext $request, AuthContext $auth, FeatureServices $services): array { return []; } }
PHP);

        $executor = $this->makeExecutor($this->project->root, []);

        $this->expectException(FoundryError::class);
        $executor->executeHttp(new RequestContext('GET', '/secure', ['x-user-id' => 'u1']));
    }

    public function test_output_schema_violation_throws(): void
    {
        $this->writeFeature('bad_output', '/bad-output', false, <<<'PHP'
<?php
declare(strict_types=1);
namespace App\Features\BadOutput;
use Foundry\Feature\FeatureAction;
use Foundry\Feature\FeatureServices;
use Foundry\Auth\AuthContext;
use Foundry\Http\RequestContext;
final class Action implements FeatureAction { public function handle(array $input, RequestContext $request, AuthContext $auth, FeatureServices $services): array { return []; } }
PHP);

        $executor = $this->makeExecutor($this->project->root, ['posts.create']);

        $this->expectException(FoundryError::class);
        $executor->executeHttp(new RequestContext('GET', '/bad-output'));
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

        $traceContext = new TraceContext('trace-fixed');
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

    private function writeFeature(string $name, string $path, bool $authRequired, string $actionCode): void
    {
        $featureDir = $this->project->root . '/app/features/' . $name;
        mkdir($featureDir, 0777, true);

        file_put_contents($featureDir . '/action.php', $actionCode);
        file_put_contents($featureDir . '/input.schema.json', '{"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object","additionalProperties":false,"properties":{}}');
        file_put_contents($featureDir . '/output.schema.json', '{"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object","additionalProperties":false,"required":["id"],"properties":{"id":{"type":"string"}}}');

        $studly = str_replace(' ', '', ucwords(str_replace('_', ' ', $name)));

        file_put_contents($this->project->root . '/app/generated/feature_index.php', "<?php return ['{$name}' => ['kind' => 'http', 'description' => 'x', 'route' => ['method' => 'GET', 'path' => '{$path}'], 'input_schema' => 'app/features/{$name}/input.schema.json', 'output_schema' => 'app/features/{$name}/output.schema.json', 'auth' => ['required' => " . ($authRequired ? 'true' : 'false') . ", 'strategies' => ['bearer'], 'permissions' => ['posts.create']], 'database' => ['transactions' => 'required'], 'cache' => [], 'events' => [], 'jobs' => [], 'rate_limit' => [], 'tests' => [], 'llm' => [], 'base_path' => 'app/features/{$name}', 'action_class' => 'App\\\\Features\\\\{$studly}\\\\Action']];");

        file_put_contents($this->project->root . '/app/generated/routes.php', "<?php return ['GET {$path}' => ['feature' => '{$name}', 'kind' => 'http', 'input_schema' => 'app/features/{$name}/input.schema.json', 'output_schema' => 'app/features/{$name}/output.schema.json']];");
    }
}
