<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Compiler\Analysis\ArchitectureDoctor;
use Foundry\Compiler\CompileOptions;
use Foundry\Compiler\GraphCompiler;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class ArchitectureDoctorTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->seedPublishPost();
        $this->seedUpdateFeed();
        $this->seedListPosts();
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function test_doctor_reports_architecture_findings_from_graph(): void
    {
        $compiler = new GraphCompiler(Paths::fromCwd($this->project->root));
        $compiled = $compiler->compile(new CompileOptions());

        $doctor = new ArchitectureDoctor(
            analyzers: $compiler->extensionRegistry()->graphAnalyzers(),
            impactAnalyzer: $compiler->impactAnalyzer(),
        );
        $report = $doctor->analyze($compiled->graph);

        $this->assertSame('high', $report['risk']);
        $this->assertNull($report['impact_preview']);
        $this->assertArrayHasKey('dependency_cycles', $report['analyzers']);
        $this->assertArrayHasKey('auth_coverage', $report['analyzers']);
        $this->assertArrayHasKey('schema_integrity', $report['analyzers']);
        $this->assertArrayHasKey('dead_code', $report['analyzers']);
        $this->assertArrayHasKey('cache_topology', $report['analyzers']);
        $this->assertArrayHasKey('test_coverage', $report['analyzers']);

        $codes = array_values(array_map(
            static fn (array $row): string => (string) ($row['code'] ?? ''),
            (array) ($report['diagnostics']['items'] ?? []),
        ));
        sort($codes);

        $this->assertContains('FDY9001_FEATURE_DEPENDENCY_CYCLE', $codes);
        $this->assertContains('FDY9002_ROUTE_AUTH_MISSING', $codes);
        $this->assertContains('FDY9003_PERMISSION_WITHOUT_ROLE_GRANT', $codes);
        $this->assertContains('FDY9004_SCHEMA_QUERY_MISMATCH', $codes);
        $this->assertContains('FDY9006_QUERY_DEAD_CODE', $codes);
        $this->assertContains('FDY9008_CACHE_NEVER_INVALIDATED', $codes);
        $this->assertContains('FDY9009_CACHE_INVALIDATION_GAP', $codes);
        $this->assertContains('FDY9010_FEATURE_TESTS_MISSING', $codes);
        $this->assertContains('FDY9011_FEATURE_TEST_KIND_MISSING', $codes);
    }

    public function test_doctor_feature_filter_scopes_findings_and_impact_preview(): void
    {
        $compiler = new GraphCompiler(Paths::fromCwd($this->project->root));
        $compiled = $compiler->compile(new CompileOptions());

        $doctor = new ArchitectureDoctor(
            analyzers: $compiler->extensionRegistry()->graphAnalyzers(),
            impactAnalyzer: $compiler->impactAnalyzer(),
        );
        $report = $doctor->analyze($compiled->graph, 'list_posts');

        $this->assertSame('list_posts', $report['feature_filter']);
        $this->assertSame('feature:list_posts', $report['impact_preview']['node_id']);
        $this->assertSame(0, $report['analyzers']['dependency_cycles']['result']['cycle_count']);

        $authRows = (array) ($report['analyzers']['auth_coverage']['result']['unguarded_routes'] ?? []);
        $this->assertNotEmpty($authRows);
        $this->assertSame('list_posts', $authRows[0]['feature']);
    }

    private function seedPublishPost(): void
    {
        $base = $this->project->root . '/app/features/publish_post';
        mkdir($base . '/tests', 0777, true);

        file_put_contents($base . '/feature.yaml', <<<'YAML'
version: 2
feature: publish_post
kind: http
description: publish
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
  transactions: required
  queries: [insert_post]
cache:
  reads: []
  writes: []
  invalidate: [posts:list]
events:
  emit: [post.created]
  subscribe: [feed.updated]
jobs:
  dispatch: []
rate_limit: {}
tests:
  required: [feature, auth]
llm:
  editable: true
  risk: medium
YAML);

        file_put_contents($base . '/input.schema.json', '{"type":"object","properties":{"title":{"type":"string"}}}');
        file_put_contents($base . '/output.schema.json', '{"type":"object","required":["id"],"properties":{"id":{"type":"string"}}}');
        file_put_contents($base . '/queries.sql', "-- name: insert_post\nINSERT INTO posts(title) VALUES(:title);\n\n-- name: unused_query\nSELECT 1;\n");
        file_put_contents($base . '/permissions.yaml', "version: 1\npermissions: [posts.create]\nrules:\n  admin: [posts.read]\n");
        file_put_contents($base . '/events.yaml', "version: 1\nemit:\n  - name: post.created\n    schema: {type: object}\nsubscribe: [feed.updated]\n");
        file_put_contents($base . '/jobs.yaml', "version: 1\ndispatch: []\n");
        file_put_contents($base . '/cache.yaml', "version: 1\nentries:\n  - key: posts:list\n    kind: computed\n    ttl_seconds: 60\n    invalidated_by: [publish_post]\n  - key: posts:detail\n    kind: computed\n    ttl_seconds: 60\n    invalidated_by: []\n  - key: posts:orphan\n    kind: computed\n    ttl_seconds: 60\n    invalidated_by: []\n");
        file_put_contents($base . '/tests/publish_post_feature_test.php', '<?php declare(strict_types=1);');
    }

    private function seedUpdateFeed(): void
    {
        $base = $this->project->root . '/app/features/update_feed';
        mkdir($base, 0777, true);

        file_put_contents($base . '/feature.yaml', <<<'YAML'
version: 2
feature: update_feed
kind: http
description: feed
route:
  method: POST
  path: /feed
input:
  schema: app/features/update_feed/input.schema.json
output:
  schema: app/features/update_feed/output.schema.json
auth:
  required: true
  strategies: [bearer]
  permissions: []
database:
  reads: []
  writes: []
  transactions: required
  queries: []
cache:
  reads: []
  writes: []
  invalidate: []
events:
  emit: [feed.updated]
  subscribe: [post.created]
jobs:
  dispatch: []
rate_limit: {}
tests:
  required: []
llm:
  editable: true
  risk: low
YAML);

        file_put_contents($base . '/input.schema.json', '{"type":"object"}');
        file_put_contents($base . '/output.schema.json', '{"type":"object"}');
        file_put_contents($base . '/events.yaml', "version: 1\nemit:\n  - name: feed.updated\n    schema: {type: object}\nsubscribe: [post.created]\n");
        file_put_contents($base . '/jobs.yaml', "version: 1\ndispatch: []\n");
        file_put_contents($base . '/cache.yaml', "version: 1\nentries: []\n");
        file_put_contents($base . '/permissions.yaml', "version: 1\npermissions: []\nrules: {}\n");
    }

    private function seedListPosts(): void
    {
        $base = $this->project->root . '/app/features/list_posts';
        mkdir($base, 0777, true);

        file_put_contents($base . '/feature.yaml', <<<'YAML'
version: 2
feature: list_posts
kind: http
description: list
route:
  method: GET
  path: /posts
input:
  schema: app/features/list_posts/input.schema.json
output:
  schema: app/features/list_posts/output.schema.json
auth:
  required: false
  strategies: []
  permissions: []
database:
  reads: []
  writes: []
  transactions: required
  queries: []
cache:
  reads: []
  writes: []
  invalidate: []
events:
  emit: []
  subscribe: []
jobs:
  dispatch: []
rate_limit: {}
tests:
  required: [feature]
llm:
  editable: true
  risk: low
YAML);

        file_put_contents($base . '/input.schema.json', '{"type":"object"}');
        file_put_contents($base . '/output.schema.json', '{"type":"object"}');
        file_put_contents($base . '/events.yaml', "version: 1\nemit: []\nsubscribe: []\n");
        file_put_contents($base . '/jobs.yaml', "version: 1\ndispatch: []\n");
        file_put_contents($base . '/cache.yaml', "version: 1\nentries: []\n");
        file_put_contents($base . '/permissions.yaml', "version: 1\npermissions: []\nrules: {}\n");
    }
}

