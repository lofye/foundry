<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Feature\FeatureLoader;
use Foundry\Pipeline\PipelineDefinitionResolver;
use Foundry\Support\FoundryError;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class FeatureLoaderEdgeCasesTest extends TestCase
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

    public function test_returns_empty_sets_when_indexes_are_missing(): void
    {
        $loader = new FeatureLoader(Paths::fromCwd($this->project->root));

        $this->assertSame([], $loader->all());
        $this->assertFalse($loader->has('missing'));
        $this->assertCount(0, $loader->routes()->all());
        $this->assertNull($loader->contextManifest('missing'));
    }

    public function test_invalid_feature_index_throws(): void
    {
        file_put_contents($this->project->root . '/app/generated/feature_index.php', '<?php return "bad";');
        $loader = new FeatureLoader(Paths::fromCwd($this->project->root));

        $this->expectException(FoundryError::class);
        $this->expectExceptionMessage('Feature index must return an array.');
        $loader->all();
    }

    public function test_invalid_routes_index_throws(): void
    {
        file_put_contents($this->project->root . '/app/generated/routes.php', '<?php return "bad";');
        $loader = new FeatureLoader(Paths::fromCwd($this->project->root));

        $this->expectException(FoundryError::class);
        $this->expectExceptionMessage('Route index must return an array.');
        $loader->routes();
    }

    public function test_context_manifest_parsing_and_feature_not_found_error(): void
    {
        file_put_contents($this->project->root . '/app/generated/feature_index.php', <<<'PHP'
<?php
return [
  'alpha' => [
    'kind' => 'http',
    'description' => 'x',
    'route' => ['method' => 'GET', 'path' => '/alpha'],
    'input_schema' => 'app/features/alpha/input.schema.json',
    'output_schema' => 'app/features/alpha/output.schema.json',
    'auth' => [],
    'database' => [],
    'cache' => [],
    'events' => [],
    'jobs' => [],
    'rate_limit' => [],
    'tests' => [],
    'llm' => [],
    'base_path' => 'app/features/alpha',
    'action_class' => 'App\\Features\\Alpha\\Action',
  ],
];
PHP);

        $featureDir = $this->project->root . '/app/features/alpha';
        mkdir($featureDir, 0777, true);
        file_put_contents($featureDir . '/context.manifest.json', <<<'JSON'
{"version":1,"feature":"alpha","kind":"http","relevant_files":["a"],"generated_files":["b"],"upstream_dependencies":[],"downstream_dependents":[],"contracts":{},"tests":[],"forbidden_paths":[],"risk_level":"low"}
JSON);

        $loader = new FeatureLoader(Paths::fromCwd($this->project->root));
        $manifest = $loader->contextManifest('alpha');

        $this->assertNotNull($manifest);
        $this->assertSame('alpha', $manifest?->feature);
        $this->assertTrue($loader->has('alpha'));
        $this->assertSame('alpha', $loader->get('alpha')->name);

        $this->expectException(FoundryError::class);
        $this->expectExceptionMessage('Feature not found.');
        $loader->get('missing');
    }

    public function test_build_indexes_are_preferred_and_pipeline_related_indexes_are_normalized(): void
    {
        if (!is_dir($this->project->root . '/app/generated')) {
            mkdir($this->project->root . '/app/generated', 0777, true);
        }
        if (!is_dir($this->project->root . '/app/.foundry/build/projections')) {
            mkdir($this->project->root . '/app/.foundry/build/projections', 0777, true);
        }

        file_put_contents($this->project->root . '/app/generated/routes.php', <<<'PHP'
<?php
return [
  'GET /legacy' => [
    'feature' => 'legacy_feature',
    'kind' => 'http',
    'input_schema' => 'legacy.in',
    'output_schema' => 'legacy.out',
  ],
];
PHP);

        file_put_contents($this->project->root . '/app/.foundry/build/projections/routes_index.php', <<<'PHP'
<?php
return [
  'POST /build' => [
    'feature' => 'build_feature',
    'kind' => 'http',
    'input_schema' => 'build.in',
    'output_schema' => 'build.out',
  ],
];
PHP);

        file_put_contents($this->project->root . '/app/.foundry/build/projections/execution_plan_index.php', <<<'PHP'
<?php
return [
  'by_route' => [
    'POST /build' => ['route' => 'POST /build'],
  ],
  'by_feature' => [
    'build_feature' => ['feature' => 'build_feature'],
  ],
];
PHP);

        file_put_contents($this->project->root . '/app/.foundry/build/projections/pipeline_index.php', <<<'PHP'
<?php
return [
  'version' => 2,
  'stages' => ['custom' => ['name' => 'custom']],
  'links' => ['custom' => ['next' => 'action']],
];
PHP);

        file_put_contents($this->project->root . '/app/.foundry/build/projections/guard_index.php', <<<'PHP'
<?php
return [
  'zeta' => ['feature' => 'zeta'],
  'alpha' => ['feature' => 'alpha'],
];
PHP);

        file_put_contents($this->project->root . '/app/.foundry/build/projections/interceptor_index.php', <<<'PHP'
<?php
return [
  'z-last' => ['id' => 'z-last', 'stage' => 'auth', 'priority' => 10],
  'a-first' => ['id' => 'a-first', 'stage' => 'auth', 'priority' => 0],
  'm-middle' => ['id' => 'm-middle', 'stage' => 'before_auth', 'priority' => 5],
];
PHP);

        $loader = new FeatureLoader(Paths::fromCwd($this->project->root));

        $routes = $loader->routes()->all();
        $this->assertCount(1, $routes);
        $this->assertSame('build_feature', $routes[0]->feature);

        $executionPlans = $loader->executionPlans();
        $this->assertSame(['build_feature'], array_keys($executionPlans['by_feature']));
        $this->assertSame(['POST /build'], array_keys($executionPlans['by_route']));

        $pipeline = $loader->pipelineDefinition();
        $this->assertSame(2, $pipeline['version']);
        $this->assertSame(PipelineDefinitionResolver::defaultStages(), $pipeline['order']);
        $this->assertSame(['custom' => ['name' => 'custom']], $pipeline['stages']);

        $guards = $loader->guards();
        $this->assertSame(['alpha', 'zeta'], array_keys($guards));

        $interceptors = $loader->interceptors();
        $this->assertSame(['a-first', 'z-last', 'm-middle'], array_keys($interceptors));
    }

    public function test_invalid_projection_index_throws_generic_index_error(): void
    {
        mkdir($this->project->root . '/app/.foundry/build/projections', 0777, true);
        file_put_contents($this->project->root . '/app/.foundry/build/projections/guard_index.php', '<?php return "bad";');

        $loader = new FeatureLoader(Paths::fromCwd($this->project->root));

        $this->expectException(FoundryError::class);
        $this->expectExceptionMessage('Generated index must return an array.');
        $loader->guards();
    }
}
