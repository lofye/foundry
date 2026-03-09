<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Compiler\Analysis\ImpactAnalyzer;
use Foundry\Compiler\CompileOptions;
use Foundry\Compiler\GraphCompiler;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class ImpactAnalyzerTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();

        $base = $this->project->root . '/app/features/publish_post';
        mkdir($base . '/tests', 0777, true);

        file_put_contents($base . '/feature.yaml', <<<'YAML'
version: 1
feature: publish_post
kind: http
description: test
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

        file_put_contents($base . '/input.schema.json', '{"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object","additionalProperties":false,"properties":{}}');
        file_put_contents($base . '/output.schema.json', '{"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object","additionalProperties":false,"properties":{}}');
        file_put_contents($base . '/queries.sql', "-- name: insert_post\nINSERT INTO posts(id) VALUES(:id);\n");
        file_put_contents($base . '/permissions.yaml', "version: 1\npermissions: [posts.create]\nrules: {}\n");
        file_put_contents($base . '/cache.yaml', "version: 1\nentries:\n  - key: posts:list\n    kind: computed\n    ttl_seconds: 300\n    invalidated_by: [publish_post]\n");
        file_put_contents($base . '/events.yaml', "version: 1\nemit:\n  - name: post.created\n    schema:\n      type: object\n      additionalProperties: false\n      properties: {}\nsubscribe: []\n");
        file_put_contents($base . '/jobs.yaml', "version: 1\ndispatch:\n  - name: notify_followers\n    input_schema:\n      type: object\n      additionalProperties: false\n      properties: {}\n    queue: default\n    retry:\n      max_attempts: 2\n      backoff_seconds: [1,2]\n    timeout_seconds: 30\n");
        file_put_contents($base . '/tests/publish_post_contract_test.php', '<?php declare(strict_types=1);');
        file_put_contents($base . '/tests/publish_post_feature_test.php', '<?php declare(strict_types=1);');
        file_put_contents($base . '/tests/publish_post_auth_test.php', '<?php declare(strict_types=1);');
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function test_reports_node_and_file_impact(): void
    {
        $compiler = new GraphCompiler(Paths::fromCwd($this->project->root));
        $result = $compiler->compile(new CompileOptions());

        $analyzer = new ImpactAnalyzer(Paths::fromCwd($this->project->root));
        $nodeReport = $analyzer->reportForNode($result->graph, 'feature:publish_post');

        $this->assertSame('feature:publish_post', $nodeReport['node_id']);
        $this->assertContains('publish_post', $nodeReport['affected_features']);
        $this->assertContains('feature_index.php', $nodeReport['affected_projections']);
        $this->assertNotEmpty($nodeReport['recommended_verification']);

        $fileReport = $analyzer->reportForFile($result->graph, 'app/features/publish_post/feature.yaml');
        $this->assertSame('app/features/publish_post/feature.yaml', $fileReport['file']);
        $this->assertNotEmpty($fileReport['nodes']);

        $tests = $analyzer->affectedTests($result->graph, 'feature:publish_post');
        $this->assertContains('publish_post_contract_test', $tests);

        $features = $analyzer->affectedFeatures($result->graph, 'feature:publish_post');
        $this->assertSame(['publish_post'], $features);
    }
}
