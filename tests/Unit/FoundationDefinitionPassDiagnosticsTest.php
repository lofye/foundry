<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Compiler\CompileOptions;
use Foundry\Compiler\GraphCompiler;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class FoundationDefinitionPassDiagnosticsTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->createFeature('list_posts');

        mkdir($this->project->root . '/app/definitions/resources', 0777, true);
        mkdir($this->project->root . '/app/definitions/admin', 0777, true);
        mkdir($this->project->root . '/app/definitions/uploads', 0777, true);
        mkdir($this->project->root . '/app/definitions/listing', 0777, true);
        mkdir($this->project->root . '/app/definitions/starters', 0777, true);

        file_put_contents($this->project->root . '/app/definitions/resources/posts.resource.yaml', <<<'YAML'
version: 2
resource: posts
style: server-rendered
model:
  table: posts
fields:
  title:
    type: unsupported_type
    required: true
features: [list, view, create, update, delete]
feature_names:
  list: list_posts
  view: view_post
  create: create_post
  update: update_post
  delete: delete_post
YAML);

        file_put_contents($this->project->root . '/app/definitions/listing/posts.list.yaml', <<<'YAML'
version: 2
resource: posts
search:
  fields: [missing_search]
filters:
  missing_filter:
    type: exact
sort:
  allowed: [missing_sort]
  default: -missing_sort
pagination:
  mode: page
  per_page: 25
YAML);

        file_put_contents($this->project->root . '/app/definitions/admin/posts.admin.yaml', <<<'YAML'
version: 1
resource: posts
table:
  columns: [missing_column]
filters: [missing_filter]
bulk_actions: [delete]
row_actions: [edit, delete]
YAML);

        file_put_contents($this->project->root . '/app/definitions/uploads/avatar.uploads.yaml', <<<'YAML'
version: 1
profile: avatar
disk: unknown_disk
feature_names:
  upload: missing_upload_feature
  attach: missing_attach_feature
YAML);

        file_put_contents($this->project->root . '/app/definitions/starters/server-rendered.starter.yaml', <<<'YAML'
version: 1
starter: server-rendered
features: [list_posts, missing_feature]
YAML);
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function test_foundation_definition_pass_emits_diagnostics_for_invalid_definitions(): void
    {
        $compiler = new GraphCompiler(Paths::fromCwd($this->project->root));
        $result = $compiler->compile(new CompileOptions());

        $codes = array_values(array_map(
            static fn(array $row): string => (string) ($row['code'] ?? ''),
            $result->diagnostics->toArray(),
        ));

        $this->assertContains('FDY2201_RESOURCE_DEFINITION_VERSION_UNSUPPORTED', $codes);
        $this->assertContains('FDY2212_RESOURCE_FIELD_TYPE_INVALID', $codes);
        $this->assertContains('FDY2202_RESOURCE_FEATURE_MISSING', $codes);
        $this->assertContains('FDY2203_LISTING_DEFINITION_VERSION_UNSUPPORTED', $codes);
        $this->assertContains('FDY2204_LISTING_SEARCH_FIELD_INVALID', $codes);
        $this->assertContains('FDY2205_LISTING_SORT_FIELD_INVALID', $codes);
        $this->assertContains('FDY2206_LISTING_FILTER_FIELD_INVALID', $codes);
        $this->assertContains('FDY2207_ADMIN_FIELD_INVALID', $codes);
        $this->assertContains('FDY2208_ADMIN_FEATURE_MISSING', $codes);
        $this->assertContains('FDY2209_UPLOAD_DISK_INVALID', $codes);
        $this->assertContains('FDY2210_UPLOAD_FEATURE_MISSING', $codes);
        $this->assertContains('FDY2211_STARTER_FEATURE_MISSING', $codes);
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
  path: /posts
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
