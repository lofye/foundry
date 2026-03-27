<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Compiler\CompileOptions;
use Foundry\Compiler\GraphCompiler;
use Foundry\Support\Json;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class GraphCompilerTest extends TestCase
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

    public function test_compile_emits_graph_and_projection_artifacts(): void
    {
        $this->createFeature('publish_post', 'POST', '/posts');

        $compiler = new GraphCompiler(Paths::fromCwd($this->project->root));
        $result = $compiler->compile(new CompileOptions());

        $this->assertFileExists($this->project->root . '/app/.foundry/build/graph/app_graph.json');
        $this->assertFileExists($this->project->root . '/app/.foundry/build/graph/app_graph.php');
        $this->assertFileExists($this->project->root . '/app/.foundry/build/projections/routes_index.php');
        $this->assertFileExists($this->project->root . '/app/.foundry/build/projections/feature_index.php');
        $this->assertFileExists($this->project->root . '/app/.foundry/build/projections/query_index.php');
        $this->assertFileExists($this->project->root . '/app/.foundry/build/projections/pipeline_index.php');
        $this->assertFileExists($this->project->root . '/app/.foundry/build/projections/guard_index.php');
        $this->assertFileExists($this->project->root . '/app/.foundry/build/projections/execution_plan_index.php');
        $this->assertFileExists($this->project->root . '/app/.foundry/build/projections/interceptor_index.php');
        $this->assertFileExists($this->project->root . '/app/.foundry/build/manifests/compile_manifest.json');
        $this->assertFileExists($this->project->root . '/app/.foundry/build/manifests/compile_cache.json');
        $this->assertFileExists($this->project->root . '/app/.foundry/build/manifests/integrity_hashes.json');
        $this->assertFileExists($this->project->root . '/app/.foundry/build/diagnostics/latest.json');

        $this->assertFileExists($this->project->root . '/app/generated/routes.php');
        $this->assertFileExists($this->project->root . '/app/generated/feature_index.php');
        $this->assertFileExists($this->project->root . '/app/generated/query_index.php');

        $graphRaw = file_get_contents($this->project->root . '/app/.foundry/build/graph/app_graph.json');
        $this->assertIsString($graphRaw);
        $graph = Json::decodeAssoc($graphRaw);

        $this->assertSame(1, $graph['graph_version']);
        $nodeIds = array_values(array_map(
            static fn(array $row): string => (string) ($row['id'] ?? ''),
            (array) ($graph['nodes'] ?? []),
        ));
        $this->assertContains('feature:publish_post', $nodeIds);

        $routes = require $this->project->root . '/app/.foundry/build/projections/routes_index.php';
        $this->assertArrayHasKey('POST /posts', $routes);

        $this->assertSame(0, $result->diagnostics->summary()['error']);
        $this->assertSame('miss', $result->cache['status']);
    }

    public function test_compile_reports_duplicate_route_diagnostic(): void
    {
        $this->createFeature('publish_post', 'POST', '/posts');
        $this->createFeature('create_post', 'POST', '/posts');

        $compiler = new GraphCompiler(Paths::fromCwd($this->project->root));
        $result = $compiler->compile(new CompileOptions());

        $codes = array_map(
            static fn(array $row): string => (string) ($row['code'] ?? ''),
            $result->diagnostics->toArray(),
        );

        $this->assertContains('FDY1001_DUPLICATE_ROUTE', $codes);
        $this->assertGreaterThan(0, $result->diagnostics->summary()['error']);
    }

    public function test_changed_only_skips_recompile_when_no_changes(): void
    {
        $this->createFeature('publish_post', 'POST', '/posts');

        $compiler = new GraphCompiler(Paths::fromCwd($this->project->root));
        $compiler->compile(new CompileOptions());

        $result = $compiler->compile(new CompileOptions(changedOnly: true));

        $this->assertTrue($result->plan->noChanges);
        $this->assertSame('changed_only', $result->plan->mode);
        $this->assertSame('hit', $result->cache['status']);

        $codes = array_map(
            static fn(array $row): string => (string) ($row['code'] ?? ''),
            $result->diagnostics->toArray(),
        );
        $this->assertContains('FDY0001_NO_CHANGES', $codes);
        $this->assertContains('FDY0002_COMPILE_CACHE_HIT', $codes);
    }

    public function test_repeated_full_compile_reuses_compile_cache(): void
    {
        $this->createFeature('publish_post', 'POST', '/posts');

        $compiler = new GraphCompiler(Paths::fromCwd($this->project->root));
        $compiler->compile(new CompileOptions());

        $graphPath = $this->project->root . '/app/.foundry/build/graph/app_graph.json';
        $before = (string) file_get_contents($graphPath);

        $result = $compiler->compile(new CompileOptions());

        $this->assertSame('hit', $result->cache['status']);
        $this->assertSame($before, (string) file_get_contents($graphPath));
    }

    public function test_feature_targeted_compile_preserves_other_features(): void
    {
        $this->createFeature('publish_post', 'POST', '/posts');
        $this->createFeature('list_posts', 'GET', '/posts');

        $compiler = new GraphCompiler(Paths::fromCwd($this->project->root));
        $compiler->compile(new CompileOptions());

        $manifestPath = $this->project->root . '/app/features/list_posts/feature.yaml';
        $manifest = (string) file_get_contents($manifestPath);
        file_put_contents($manifestPath, str_replace('description: test', 'description: updated', $manifest));

        $result = $compiler->compile(new CompileOptions(feature: 'list_posts'));

        $this->assertTrue($result->plan->incremental);
        $this->assertContains('publish_post', $result->graph->features());
        $this->assertContains('list_posts', $result->graph->features());
        $this->assertSame('invalidated', $result->cache['status']);
        $this->assertContains('feature_manifest_hash', $result->cache['invalidated_inputs']);
    }

    public function test_compile_cache_can_be_inspected_and_cleared(): void
    {
        $this->createFeature('publish_post', 'POST', '/posts');

        $compiler = new GraphCompiler(Paths::fromCwd($this->project->root));
        $compiler->compile(new CompileOptions());

        $inspect = $compiler->inspectCache();
        $this->assertSame('hit', $inspect['status']);
        $this->assertSame([], $inspect['artifacts']['missing']);

        $cleared = $compiler->clearCache();
        $this->assertTrue($cleared['cleared']);
        $this->assertFileDoesNotExist($this->project->root . '/app/.foundry/build/manifests/compile_manifest.json');

        $afterClear = $compiler->inspectCache();
        $this->assertSame('miss', $afterClear['status']);
    }

    public function test_compile_forces_full_rebuild_when_framework_cache_marker_changes(): void
    {
        $this->createFeature('publish_post', 'POST', '/posts');

        $compiler = new GraphCompiler(Paths::fromCwd($this->project->root));
        $compiler->compile(new CompileOptions());

        $cachePath = $this->project->root . '/app/.foundry/build/manifests/compile_cache.json';
        $cache = Json::decodeAssoc((string) file_get_contents($cachePath));
        $cache['inputs']['framework_source_hash'] = 'stale-framework-hash';
        file_put_contents($cachePath, Json::encode($cache, true));

        $result = $compiler->compile(new CompileOptions(changedOnly: true));

        $this->assertTrue($result->plan->fallbackToFull);
        $this->assertSame('invalidated', $result->cache['status']);
        $this->assertTrue($result->cache['requires_full_recompile']);
        $this->assertContains('framework_source_hash', $result->cache['invalidated_inputs']);
    }

    public function test_graph_and_projection_outputs_match_with_and_without_cache_enabled(): void
    {
        $otherProject = new TempProject();

        try {
            $this->createFeature('publish_post', 'POST', '/posts');
            $this->createFeature('publish_post', 'POST', '/posts', $otherProject->root);

            $noCacheCompiler = new GraphCompiler(Paths::fromCwd($this->project->root));
            $cachedCompiler = new GraphCompiler(Paths::fromCwd($otherProject->root));

            $noCacheResult = $noCacheCompiler->compile(new CompileOptions(useCache: false));
            $cachedResult = $cachedCompiler->compile(new CompileOptions());

            $this->assertSame('disabled', $noCacheResult->cache['status']);
            $this->assertSame('miss', $cachedResult->cache['status']);

            $leftGraph = Json::decodeAssoc((string) file_get_contents($this->project->root . '/app/.foundry/build/graph/app_graph.json'));
            $rightGraph = Json::decodeAssoc((string) file_get_contents($otherProject->root . '/app/.foundry/build/graph/app_graph.json'));
            unset($leftGraph['compiled_at'], $rightGraph['compiled_at']);

            $this->assertSame($leftGraph, $rightGraph);
            $this->assertSame(
                (string) file_get_contents($this->project->root . '/app/.foundry/build/projections/routes_index.php'),
                (string) file_get_contents($otherProject->root . '/app/.foundry/build/projections/routes_index.php'),
            );
            $this->assertSame(
                (string) file_get_contents($this->project->root . '/app/.foundry/build/projections/feature_index.php'),
                (string) file_get_contents($otherProject->root . '/app/.foundry/build/projections/feature_index.php'),
            );
        } finally {
            $otherProject->cleanup();
        }
    }

    private function createFeature(string $feature, string $method, string $path, ?string $root = null): void
    {
        $root ??= $this->project->root;
        $base = $root . '/app/features/' . $feature;
        mkdir($base . '/tests', 0777, true);

        file_put_contents($base . '/feature.yaml', <<<YAML
version: 1
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
  writes: []
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
  bucket: post_create
  cost: 1
tests:
  required: [contract, feature, auth]
llm:
  editable: true
  risk: medium
YAML);

        file_put_contents($base . '/action.php', '<?php declare(strict_types=1);');
        file_put_contents($base . '/input.schema.json', '{"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object","additionalProperties":false,"properties":{}}');
        file_put_contents($base . '/output.schema.json', '{"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object","additionalProperties":false,"properties":{}}');
        file_put_contents($base . '/queries.sql', "-- name: insert_post\nINSERT INTO posts(id) VALUES(:id);\n");
        file_put_contents($base . '/permissions.yaml', "version: 1\npermissions: [posts.create]\nrules: {}\n");
        file_put_contents($base . '/cache.yaml', "version: 1\nentries:\n  - key: posts:list\n    kind: computed\n    ttl_seconds: 300\n    invalidated_by: [{$feature}]\n");
        file_put_contents($base . '/events.yaml', "version: 1\nemit:\n  - name: post.created\n    schema:\n      type: object\n      additionalProperties: false\n      properties: {}\nsubscribe: []\n");
        file_put_contents($base . '/jobs.yaml', "version: 1\ndispatch:\n  - name: notify_followers\n    input_schema:\n      type: object\n      additionalProperties: false\n      properties: {}\n    queue: default\n    retry:\n      max_attempts: 2\n      backoff_seconds: [1,2]\n    timeout_seconds: 30\n");
        file_put_contents($base . '/context.manifest.json', '{"version":1,"feature":"' . $feature . '","kind":"http"}');
        file_put_contents($base . '/tests/' . $feature . '_contract_test.php', '<?php declare(strict_types=1);');
        file_put_contents($base . '/tests/' . $feature . '_feature_test.php', '<?php declare(strict_types=1);');
        file_put_contents($base . '/tests/' . $feature . '_auth_test.php', '<?php declare(strict_types=1);');
    }
}
