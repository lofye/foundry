<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Compiler\CompileOptions;
use Foundry\Compiler\Extensions\ExtensionRegistry;
use Foundry\Compiler\GraphCompiler;
use Foundry\Explain\Snapshot\ExplainSnapshotService;
use Foundry\Generate\GeneratorRegistry;
use Foundry\Support\ApiSurfaceRegistry;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class ExplainSnapshotServiceTest extends TestCase
{
    private TempProject $project;
    private string $cwd;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->cwd = getcwd() ?: '.';
        chdir($this->project->root);
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        $this->project->cleanup();
    }

    public function test_capture_uses_system_root_when_no_default_target_exists(): void
    {
        $paths = Paths::fromCwd($this->project->root);
        $extensions = ExtensionRegistry::forPaths($paths);
        $graph = (new GraphCompiler($paths, $extensions))->compile(new CompileOptions(emit: true))->graph;
        $service = new ExplainSnapshotService($paths, new ApiSurfaceRegistry());

        $snapshot = $service->capture('pre-generate', $graph, $extensions, GeneratorRegistry::forExtensions($extensions));

        $this->assertSame(1, $snapshot['schema_version']);
        $this->assertSame('system:root', $snapshot['explain']['subject']['id']);
        $this->assertArrayHasKey('confidence', $snapshot);
        $this->assertArrayHasKey('confidence', $snapshot['explain']);
        $this->assertSame([], $snapshot['categories']['routes']);
        $this->assertFileExists($service->snapshotPath('pre-generate'));
    }

    public function test_capture_records_routes_and_explain_metadata_for_compiled_project(): void
    {
        $this->seedFeature();

        $paths = Paths::fromCwd($this->project->root);
        $extensions = ExtensionRegistry::forPaths($paths);
        $graph = (new GraphCompiler($paths, $extensions))->compile(new CompileOptions(emit: true))->graph;
        $service = new ExplainSnapshotService($paths, new ApiSurfaceRegistry());

        $snapshot = $service->capture('post-generate', $graph, $extensions, GeneratorRegistry::forExtensions($extensions));

        $this->assertSame('feature:publish_post', $snapshot['explain']['subject']['id']);
        $this->assertSame('POST /posts', $snapshot['categories']['routes'][0]['label']);
        $this->assertSame($graph->graphVersion(), $snapshot['metadata']['graph_version']);
        $this->assertSame($graph->sourceHash(), $snapshot['metadata']['source_hash']);
        $this->assertSame(1, $snapshot['application']['summary']['features']);
        $this->assertGreaterThan(0, $snapshot['application']['summary']['nodes']);
        $this->assertSame($snapshot['explain']['confidence'], $snapshot['confidence']);
    }

    public function test_capture_supports_round_trip_loads_for_canonical_labels(): void
    {
        $this->seedFeature();

        $paths = Paths::fromCwd($this->project->root);
        $extensions = ExtensionRegistry::forPaths($paths);
        $graph = (new GraphCompiler($paths, $extensions))->compile(new CompileOptions(emit: true))->graph;
        $service = new ExplainSnapshotService($paths, new ApiSurfaceRegistry());

        $snapshot = $service->capture('post-generate', $graph, $extensions, GeneratorRegistry::forExtensions($extensions), 'route:POST /posts');
        $loaded = $service->load('post-generate');

        $this->assertSame($snapshot['label'], $loaded['label']);
        $this->assertSame($snapshot['metadata'], $loaded['metadata']);
        $this->assertSame($snapshot['application']['summary'], $loaded['application']['summary']);
        $this->assertSame('route:POST /posts', $snapshot['metadata']['target']['raw']);
    }

    public function test_load_rejects_missing_corrupt_and_invalid_snapshots(): void
    {
        $paths = Paths::fromCwd($this->project->root);
        $service = new ExplainSnapshotService($paths, new ApiSurfaceRegistry());

        try {
            $service->load('pre-generate');
            self::fail('Expected missing snapshot failure.');
        } catch (\Foundry\Support\FoundryError $error) {
            $this->assertSame('EXPLAIN_SNAPSHOT_NOT_FOUND', $error->errorCode);
        }

        mkdir($this->project->root . '/.foundry/snapshots', 0777, true);
        file_put_contents($service->snapshotPath('pre-generate'), '{bad json');

        try {
            $service->load('pre-generate');
            self::fail('Expected corrupt snapshot failure.');
        } catch (\Foundry\Support\FoundryError $error) {
            $this->assertSame('EXPLAIN_SNAPSHOT_CORRUPT', $error->errorCode);
        }

        file_put_contents($service->snapshotPath('pre-generate'), json_encode(['schema_version' => 'bad'], JSON_THROW_ON_ERROR));

        try {
            $service->load('pre-generate');
            self::fail('Expected invalid snapshot failure.');
        } catch (\Foundry\Support\FoundryError $error) {
            $this->assertSame('EXPLAIN_SNAPSHOT_INVALID', $error->errorCode);
        }
    }

    private function seedFeature(): void
    {
        $feature = $this->project->root . '/app/features/publish_post';
        mkdir($feature . '/tests', 0777, true);

        file_put_contents($feature . '/feature.yaml', <<<'YAML'
version: 1
feature: publish_post
kind: http
description: Publish a post
route:
  method: POST
  path: /posts
input:
  schema: app/features/publish_post/input.schema.json
output:
  schema: app/features/publish_post/output.schema.json
auth:
  required: true
  strategies: [bearer]
  permissions: [posts.create]
database:
  reads: []
  writes: []
  queries: [insert_post]
  transactions: required
cache:
  invalidate: [posts:list]
events:
  emit: [post.created]
jobs:
  dispatch: [notify_followers]
tests:
  required: [contract, feature, auth]
YAML);
        file_put_contents($feature . '/input.schema.json', '{"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object","additionalProperties":false,"properties":{}}');
        file_put_contents($feature . '/output.schema.json', '{"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object","additionalProperties":false,"properties":{}}');
        file_put_contents($feature . '/action.php', '<?php declare(strict_types=1); namespace App\\Features\\PublishPost; use Foundry\\Auth\\AuthContext; use Foundry\\Feature\\FeatureAction; use Foundry\\Feature\\FeatureServices; use Foundry\\Http\\RequestContext; final class Action implements FeatureAction { public function handle(array $input, RequestContext $request, AuthContext $auth, FeatureServices $services): array { return []; } }');
        file_put_contents($feature . '/queries.sql', "-- name: insert_post\nINSERT INTO posts(id) VALUES(:id);\n");
        file_put_contents($feature . '/cache.yaml', "version: 1\nentries:\n  - key: posts:list\n    kind: computed\n    ttl_seconds: 300\n    invalidated_by: [publish_post]\n");
        file_put_contents($feature . '/events.yaml', "version: 1\nemit:\n  - name: post.created\n    schema:\n      type: object\n      additionalProperties: false\n      properties: {}\nsubscribe: []\n");
        file_put_contents($feature . '/jobs.yaml', "version: 1\ndispatch:\n  - name: notify_followers\n    input_schema:\n      type: object\n      additionalProperties: false\n      properties: {}\n    queue: default\n");
        file_put_contents($feature . '/permissions.yaml', "version: 1\npermissions: [posts.create]\nrules: {}\n");
        file_put_contents($feature . '/tests/publish_post_contract_test.php', '<?php declare(strict_types=1);');
        file_put_contents($feature . '/tests/publish_post_feature_test.php', '<?php declare(strict_types=1);');
        file_put_contents($feature . '/tests/publish_post_auth_test.php', '<?php declare(strict_types=1);');
    }
}
