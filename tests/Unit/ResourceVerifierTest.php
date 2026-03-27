<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Compiler\CompileOptions;
use Foundry\Compiler\GraphCompiler;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use Foundry\Verification\ResourceVerifier;
use PHPUnit\Framework\TestCase;

final class ResourceVerifierTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->createFeature('list_posts');
        $this->createFeature('view_post');
        $this->createFeature('create_post');
        $this->createFeature('update_post');
        $this->createFeature('delete_post');

        mkdir($this->project->root . '/app/definitions/resources', 0777, true);
        mkdir($this->project->root . '/app/definitions/listing', 0777, true);

        file_put_contents($this->project->root . '/app/definitions/resources/posts.resource.yaml', <<<'YAML'
version: 1
resource: posts
style: server-rendered
model:
  table: posts
  primary_key: id
fields:
  title:
    type: string
    required: true
    list: true
    form: text
features: [list, view, create, update, delete]
feature_names:
  list: list_posts
  view: view_post
  create: create_post
  update: update_post
  delete: delete_post
YAML);

        file_put_contents($this->project->root . '/app/definitions/listing/posts.list.yaml', <<<'YAML'
version: 1
resource: posts
search:
  fields: [title]
filters: {}
sort:
  allowed: [title]
  default: -title
pagination:
  mode: page
  per_page: 20
YAML);

        $compiler = new GraphCompiler(Paths::fromCwd($this->project->root));
        $compiler->compile(new CompileOptions());
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function test_verify_passes_for_compiled_resource(): void
    {
        $verifier = new ResourceVerifier(new GraphCompiler(Paths::fromCwd($this->project->root)));
        $result = $verifier->verify('posts');

        $this->assertTrue($result->ok);
        $this->assertSame([], $result->errors);
    }

    public function test_verify_fails_for_missing_resource(): void
    {
        $verifier = new ResourceVerifier(new GraphCompiler(Paths::fromCwd($this->project->root)));
        $result = $verifier->verify('missing');

        $this->assertFalse($result->ok);
        $this->assertNotEmpty($result->errors);
    }

    private function createFeature(string $feature): void
    {
        $base = $this->project->root . '/app/features/' . $feature;
        mkdir($base . '/tests', 0777, true);

        file_put_contents($base . '/feature.yaml', <<<YAML
version: 2
feature: {$feature}
kind: http
description: test
route:
  method: GET
  path: /{$feature}
input:
  schema: app/features/{$feature}/input.schema.json
output:
  schema: app/features/{$feature}/output.schema.json
auth:
  required: true
  strategies: [session]
  permissions: []
database:
  reads: []
  writes: []
  transactions: optional
  queries: [q]
cache:
  reads: []
  writes: []
  invalidate: []
events:
  emit: []
  subscribe: []
jobs:
  dispatch: []
rate_limit:
  strategy: user
  bucket: {$feature}
  cost: 1
tests:
  required: [contract]
llm:
  editable: true
  risk_level: low
YAML);

        file_put_contents($base . '/action.php', '<?php declare(strict_types=1);');
        file_put_contents($base . '/input.schema.json', '{"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object","additionalProperties":false,"properties":{}}');
        file_put_contents($base . '/output.schema.json', '{"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object","additionalProperties":false,"properties":{}}');
        file_put_contents($base . '/queries.sql', "-- name: q\nSELECT 1;\n");
        file_put_contents($base . '/permissions.yaml', "version: 1\npermissions: []\nrules: {}\n");
        file_put_contents($base . '/cache.yaml', "version: 1\nentries: []\n");
        file_put_contents($base . '/events.yaml', "version: 1\nemit: []\nsubscribe: []\n");
        file_put_contents($base . '/jobs.yaml', "version: 1\ndispatch: []\n");
        file_put_contents($base . '/context.manifest.json', '{"version":1,"feature":"' . $feature . '","kind":"http"}');
        file_put_contents($base . '/tests/' . $feature . '_contract_test.php', '<?php declare(strict_types=1);');
    }
}
