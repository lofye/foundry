<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Feature\FeatureLoader;
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
}
