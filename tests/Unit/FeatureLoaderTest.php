<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Feature\FeatureLoader;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class FeatureLoaderTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();

        file_put_contents($this->project->root . '/app/generated/feature_index.php', <<<'PHP'
<?php
return [
  'publish_post' => [
    'kind' => 'http',
    'description' => 'x',
    'route' => ['method' => 'POST', 'path' => '/posts'],
    'input_schema' => 'app/features/publish_post/input.schema.json',
    'output_schema' => 'app/features/publish_post/output.schema.json',
    'auth' => [],
    'database' => [],
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
    'input_schema' => 'in.json',
    'output_schema' => 'out.json',
  ],
];
PHP);
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function test_loads_features_and_routes_from_indexes(): void
    {
        $loader = new FeatureLoader(Paths::fromCwd($this->project->root));

        $this->assertTrue($loader->has('publish_post'));
        $this->assertSame('publish_post', $loader->get('publish_post')->name);
        $this->assertCount(1, $loader->routes()->all());
    }
}
