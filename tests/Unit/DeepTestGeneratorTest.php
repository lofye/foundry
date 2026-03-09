<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Compiler\ApplicationGraph;
use Foundry\Compiler\CompileOptions;
use Foundry\Compiler\Diagnostics\DiagnosticBag;
use Foundry\Compiler\GraphCompiler;
use Foundry\Compiler\IR\FeatureNode;
use Foundry\Compiler\IR\ResourceNode;
use Foundry\Generation\DeepTestGenerator;
use Foundry\Generation\TestGenerator;
use Foundry\Support\FoundryError;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class DeepTestGeneratorTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->createFeature('api_create_post', 'POST', '/api/posts');

        $compiler = new GraphCompiler(Paths::fromCwd($this->project->root));
        $compiler->compile(new CompileOptions());
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function test_generates_deep_tests_for_feature_target(): void
    {
        $paths = Paths::fromCwd($this->project->root);
        $compiler = new GraphCompiler($paths);
        $generator = new DeepTestGenerator($paths, $compiler, new TestGenerator());

        $result = $generator->generateForTarget('api_create_post', 'deep');

        $this->assertSame('feature', $result['kind']);
        $this->assertNotEmpty($result['files']);
        $this->assertFileExists($this->project->root . '/app/features/api_create_post/tests/api_create_post_deep_test.php');
    }

    public function test_generates_all_missing_tests(): void
    {
        @unlink($this->project->root . '/app/features/api_create_post/tests/api_create_post_contract_test.php');

        $paths = Paths::fromCwd($this->project->root);
        $compiler = new GraphCompiler($paths);
        $generator = new DeepTestGenerator($paths, $compiler, new TestGenerator());

        $result = $generator->generateAllMissing('basic');

        $this->assertContains('api_create_post', $result['features']);
        $this->assertFileExists($this->project->root . '/app/features/api_create_post/tests/api_create_post_contract_test.php');
    }

    public function test_generate_for_target_requires_non_empty_target(): void
    {
        $paths = Paths::fromCwd($this->project->root);
        $generator = new DeepTestGenerator($paths, new GraphCompiler($paths), new TestGenerator());

        try {
            $generator->generateForTarget('   ', 'deep');
            self::fail('Expected target required error.');
        } catch (FoundryError $error) {
            $this->assertSame('CLI_TARGET_REQUIRED', $error->errorCode);
        }
    }

    public function test_generate_for_target_throws_when_target_not_found(): void
    {
        $paths = Paths::fromCwd($this->project->root);
        $generator = new DeepTestGenerator($paths, new GraphCompiler($paths), new TestGenerator());

        try {
            $generator->generateForTarget('missing_target', 'deep');
            self::fail('Expected target not found error.');
        } catch (FoundryError $error) {
            $this->assertSame('TARGET_NOT_FOUND', $error->errorCode);
        }
    }

    public function test_generates_tests_for_resource_or_api_resource_target(): void
    {
        mkdir($this->project->root . '/app/specs/resources', 0777, true);
        mkdir($this->project->root . '/app/specs/api', 0777, true);

        file_put_contents($this->project->root . '/app/specs/resources/posts.resource.yaml', <<<'YAML'
version: 1
resource: posts
features: [create]
feature_names:
  create: api_create_post
YAML);

        file_put_contents($this->project->root . '/app/specs/api/posts.api-resource.yaml', <<<'YAML'
version: 1
resource: posts
style: api
features: [create]
feature_names:
  create: api_create_post
YAML);

        $paths = Paths::fromCwd($this->project->root);
        $compiler = new GraphCompiler($paths);
        $compiler->compile(new CompileOptions());

        $generator = new DeepTestGenerator($paths, $compiler, new TestGenerator());
        $result = $generator->generateForTarget('posts', 'api');

        $this->assertSame('api_resource', $result['kind']);
        $this->assertSame(['api_create_post'], $result['features']);
        $this->assertNotEmpty($result['files']);
    }

    public function test_generate_for_target_throws_when_feature_directory_missing(): void
    {
        $graph = new ApplicationGraph(1, '0.1.0', '2026-03-09T00:00:00+00:00', 'hash');
        $graph->addNode(new FeatureNode(
            'feature:ghost_feature',
            'app/features/ghost_feature/feature.yaml',
            [
                'feature' => 'ghost_feature',
                'tests' => ['required' => ['contract']],
            ],
        ));
        $this->writeGraph($graph);

        $paths = Paths::fromCwd($this->project->root);
        $generator = new DeepTestGenerator($paths, new GraphCompiler($paths), new TestGenerator());

        try {
            $generator->generateForTarget('ghost_feature', 'deep');
            self::fail('Expected feature directory not found error.');
        } catch (FoundryError $error) {
            $this->assertSame('FEATURE_NOT_FOUND', $error->errorCode);
        }
    }

    public function test_generate_for_target_throws_when_resource_has_no_features(): void
    {
        $graph = new ApplicationGraph(1, '0.1.0', '2026-03-09T00:00:00+00:00', 'hash');
        $graph->addNode(new ResourceNode(
            'resource:empty_resource',
            'app/specs/resources/empty.resource.yaml',
            [
                'resource' => 'empty_resource',
                'feature_map' => [],
            ],
        ));
        $this->writeGraph($graph);

        $paths = Paths::fromCwd($this->project->root);
        $generator = new DeepTestGenerator($paths, new GraphCompiler($paths), new TestGenerator());

        try {
            $generator->generateForTarget('empty_resource', 'resource');
            self::fail('Expected missing resource features error.');
        } catch (FoundryError $error) {
            $this->assertSame('RESOURCE_FEATURES_MISSING', $error->errorCode);
        }
    }

    public function test_deep_mode_includes_not_found_and_notification_dispatch_scenarios(): void
    {
        $this->createFeature('api_view_post', 'GET', '/api/posts/{id}');
        mkdir($this->project->root . '/app/specs/notifications', 0777, true);
        mkdir($this->project->root . '/app/notifications/templates', 0777, true);
        mkdir($this->project->root . '/app/notifications/schemas', 0777, true);

        file_put_contents($this->project->root . '/app/specs/notifications/post_viewed.notification.yaml', <<<'YAML'
version: 1
notification: post_viewed
channel: mail
queue: default
template: post_viewed
input_schema: app/notifications/schemas/post_viewed.input.schema.json
dispatch_features: [api_view_post]
YAML);
        file_put_contents($this->project->root . '/app/notifications/templates/post_viewed.mail.php', <<<'PHP'
<?php
declare(strict_types=1);

return ['subject' => 'Viewed', 'text' => 'Viewed', 'html' => ''];
PHP);
        file_put_contents($this->project->root . '/app/notifications/schemas/post_viewed.input.schema.json', <<<'JSON'
{"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object","additionalProperties":false,"required":["post_id"],"properties":{"post_id":{"type":"string"}}}
JSON);

        $paths = Paths::fromCwd($this->project->root);
        $compiler = new GraphCompiler($paths);
        $compiler->compile(new CompileOptions());

        $generator = new DeepTestGenerator($paths, $compiler, new TestGenerator());
        $result = $generator->generateForTarget('api_view_post', 'deep');

        $deepTestPath = $this->project->root . '/app/features/api_view_post/tests/api_view_post_deep_test.php';
        $contents = file_get_contents($deepTestPath) ?: '';

        $this->assertContains($deepTestPath, $result['files']);
        $this->assertStringContainsString('test_not_found', $contents);
        $this->assertStringContainsString('test_notification_dispatch', $contents);
    }

    private function createFeature(string $feature, string $method, string $path): void
    {
        $base = $this->project->root . '/app/features/' . $feature;
        mkdir($base . '/tests', 0777, true);

        file_put_contents($base . '/feature.yaml', <<<YAML
version: 2
feature: {$feature}
kind: http
description: test
route:
  method: {$method}
  path: {$path}
input:
  schema: app/features/{$feature}/input.schema.json
output:
  schema: app/features/{$feature}/output.schema.json
auth:
  required: true
  strategies: [bearer]
  permissions: [posts.create]
database:
  reads: []
  writes: [posts]
  transactions: required
  queries: [insert_post]
cache:
  reads: []
  writes: []
  invalidate: [posts:list]
events:
  emit: [post.created]
  subscribe: []
jobs:
  dispatch: [notify_followers]
rate_limit:
  strategy: user
  bucket: {$feature}
  cost: 1
tests:
  required: [contract, feature, auth]
listing:
  spec: app/specs/listing/posts.list.yaml
llm:
  editable: true
  risk_level: low
YAML);

        file_put_contents($base . '/action.php', '<?php declare(strict_types=1);');
        file_put_contents($base . '/input.schema.json', '{"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object","additionalProperties":false,"required":["title"],"properties":{"title":{"type":"string"}}}');
        file_put_contents($base . '/output.schema.json', '{"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object","additionalProperties":false,"properties":{"data":{"type":"object"}}}');
        file_put_contents($base . '/queries.sql', "-- name: insert_post\nINSERT INTO posts(title) VALUES(:title);\n");
        file_put_contents($base . '/permissions.yaml', "version: 1\npermissions: [posts.create]\nrules: {}\n");
        file_put_contents($base . '/cache.yaml', "version: 1\nentries:\n  - key: posts:list\n    kind: computed\n    ttl_seconds: 120\n    invalidated_by: [api_create_post]\n");
        file_put_contents($base . '/events.yaml', "version: 1\nemit:\n  - name: post.created\n    schema:\n      type: object\n      properties: {}\n      additionalProperties: false\nsubscribe: []\n");
        file_put_contents($base . '/jobs.yaml', "version: 1\ndispatch:\n  - name: notify_followers\n    input_schema:\n      type: object\n      properties: {}\n      additionalProperties: false\n    queue: default\n");
        file_put_contents($base . '/context.manifest.json', '{"version":1,"feature":"' . $feature . '","kind":"http"}');
        file_put_contents($base . '/tests/' . $feature . '_contract_test.php', '<?php declare(strict_types=1);');
    }

    private function writeGraph(ApplicationGraph $graph): void
    {
        $path = $this->project->root . '/app/.foundry/build/graph';
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }

        file_put_contents(
            $path . '/app_graph.json',
            json_encode($graph->toArray(new DiagnosticBag()), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }
}
