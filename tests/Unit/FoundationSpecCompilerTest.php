<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Compiler\CompileOptions;
use Foundry\Compiler\GraphCompiler;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class FoundationSpecCompilerTest extends TestCase
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

    public function test_foundation_specs_compile_into_graph_and_projections(): void
    {
        foreach ([
            'list_posts',
            'view_post',
            'create_post',
            'update_post',
            'delete_post',
            'admin_list_posts',
            'admin_view_post',
            'admin_update_post',
            'admin_delete_post',
            'admin_bulk_update_posts',
            'upload_avatar',
            'attach_avatar',
        ] as $feature) {
            $this->createFeature($feature, 'GET', '/' . str_replace('_', '/', $feature));
        }

        $this->writeSpecs();

        $compiler = new GraphCompiler(Paths::fromCwd($this->project->root));
        $result = $compiler->compile(new CompileOptions());

        $this->assertNotNull($result->graph->node('resource:posts'));
        $this->assertNotNull($result->graph->node('listing_config:posts'));
        $this->assertNotNull($result->graph->node('admin_resource:posts'));
        $this->assertNotNull($result->graph->node('upload_profile:avatar'));
        $this->assertNotNull($result->graph->node('starter_kit:server-rendered'));
        $this->assertNotNull($result->graph->node('form_definition:posts:create'));
        $this->assertNotNull($result->graph->node('form_definition:posts:update'));

        $this->assertFileExists($this->project->root . '/app/.foundry/build/projections/resource_index.php');
        $this->assertFileExists($this->project->root . '/app/.foundry/build/projections/admin_resource_index.php');
        $this->assertFileExists($this->project->root . '/app/.foundry/build/projections/upload_profile_index.php');
        $this->assertFileExists($this->project->root . '/app/.foundry/build/projections/starter_index.php');
        $this->assertFileExists($this->project->root . '/app/.foundry/build/projections/listing_index.php');
        $this->assertFileExists($this->project->root . '/app/.foundry/build/projections/form_index.php');

        $resourceProjection = require $this->project->root . '/app/.foundry/build/projections/resource_index.php';
        $this->assertArrayHasKey('posts', $resourceProjection);

        $listingProjection = require $this->project->root . '/app/.foundry/build/projections/listing_index.php';
        $this->assertArrayHasKey('posts', $listingProjection);

        $this->assertSame(0, $result->diagnostics->summary()['error']);
    }

    private function writeSpecs(): void
    {
        mkdir($this->project->root . '/app/specs/resources', 0777, true);
        mkdir($this->project->root . '/app/specs/admin', 0777, true);
        mkdir($this->project->root . '/app/specs/uploads', 0777, true);
        mkdir($this->project->root . '/app/specs/listing', 0777, true);
        mkdir($this->project->root . '/app/specs/starters', 0777, true);

        file_put_contents($this->project->root . '/app/specs/resources/posts.resource.yaml', <<<'YAML'
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
    maxLength: 200
    list: true
    form: text
    search: true
    sort: true
  slug:
    type: string
    required: true
    unique: true
    list: true
    form: text
    search: true
    sort: true
  body_markdown:
    type: text
    required: true
    form: textarea
  published_at:
    type: datetime
    required: false
    form: datetime
    filter: true
auth:
  list: posts.view
  view: posts.view
  create: posts.create
  update: posts.update
  delete: posts.delete
features: [list, view, create, update, delete]
feature_names:
  list: list_posts
  view: view_post
  create: create_post
  update: update_post
  delete: delete_post
YAML);

        file_put_contents($this->project->root . '/app/specs/listing/posts.list.yaml', <<<'YAML'
version: 1
resource: posts
search:
  fields: [title, slug]
filters:
  published_at:
    type: date
sort:
  allowed: [title, slug]
  default: -title
pagination:
  mode: page
  per_page: 25
YAML);

        file_put_contents($this->project->root . '/app/specs/admin/posts.admin.yaml', <<<'YAML'
version: 1
resource: posts
table:
  columns: [title, slug]
filters: [published_at]
bulk_actions: [delete]
row_actions: [edit, delete]
YAML);

        file_put_contents($this->project->root . '/app/specs/uploads/avatar.uploads.yaml', <<<'YAML'
version: 1
profile: avatar
disk: local
allowed_mime_types: [image/jpeg, image/png]
max_size_kb: 2048
feature_names:
  upload: upload_avatar
  attach: attach_avatar
YAML);

        file_put_contents($this->project->root . '/app/specs/starters/server-rendered.starter.yaml', <<<'YAML'
version: 1
starter: server-rendered
auth_mode: session
features: [list_posts, view_post, create_post]
pipeline_defaults:
  csrf: true
YAML);
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
