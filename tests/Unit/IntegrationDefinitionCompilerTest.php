<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Compiler\CompileOptions;
use Foundry\Compiler\GraphCompiler;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class IntegrationDefinitionCompilerTest extends TestCase
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

    public function test_integration_definitions_compile_into_graph_and_projections(): void
    {
        foreach (['api_list_posts', 'api_view_post', 'api_create_post', 'api_update_post', 'api_delete_post', 'dispatch_welcome_email'] as $feature) {
            $path = str_starts_with($feature, 'api_') ? '/api/' . str_replace('_', '/', substr($feature, 4)) : '/' . str_replace('_', '/', $feature);
            $method = str_contains($feature, 'create') ? 'POST' : (str_contains($feature, 'update') ? 'PUT' : (str_contains($feature, 'delete') ? 'DELETE' : 'GET'));
            $this->createFeature($feature, $method, $path);
        }

        mkdir($this->project->root . '/app/definitions/api', 0777, true);
        mkdir($this->project->root . '/app/definitions/notifications', 0777, true);
        mkdir($this->project->root . '/app/notifications/schemas', 0777, true);
        mkdir($this->project->root . '/app/notifications/templates', 0777, true);

        file_put_contents($this->project->root . '/app/definitions/api/posts.api-resource.yaml', <<<'YAML'
version: 1
resource: posts
style: api
model:
  table: posts
  primary_key: id
fields:
  title:
    type: string
    required: true
auth:
  list: posts.view
  view: posts.view
  create: posts.create
  update: posts.update
  delete: posts.delete
features: [list, view, create, update, delete]
feature_names:
  list: api_list_posts
  view: api_view_post
  create: api_create_post
  update: api_update_post
  delete: api_delete_post
YAML);

        file_put_contents($this->project->root . '/app/definitions/notifications/welcome_email.notification.yaml', <<<'YAML'
version: 1
notification: welcome_email
channel: mail
queue: default
template: welcome_email
input_schema: app/notifications/schemas/welcome_email.input.schema.json
dispatch_features: [dispatch_welcome_email]
YAML);

        file_put_contents($this->project->root . '/app/notifications/schemas/welcome_email.input.schema.json', <<<'JSON'
{"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object","additionalProperties":false,"required":["user_id"],"properties":{"user_id":{"type":"string"}}}
JSON);

        file_put_contents($this->project->root . '/app/notifications/templates/welcome_email.mail.php', <<<'PHP'
<?php
declare(strict_types=1);

return [
    'subject' => 'Welcome {{user_id}}',
    'text' => 'Welcome {{user_id}}',
    'html' => '<p>Welcome {{user_id}}</p>',
];
PHP);

        $compiler = new GraphCompiler(Paths::fromCwd($this->project->root));
        $result = $compiler->compile(new CompileOptions());

        $this->assertNotNull($result->graph->node('api_resource:posts'));
        $this->assertNotNull($result->graph->node('notification:welcome_email'));
        $this->assertNotNull($result->graph->node('schema:app/notifications/schemas/welcome_email.input.schema.json'));

        $this->assertFileExists($this->project->root . '/app/.foundry/build/projections/api_resource_index.php');
        $this->assertFileExists($this->project->root . '/app/.foundry/build/projections/notification_index.php');

        $apiProjection = require $this->project->root . '/app/.foundry/build/projections/api_resource_index.php';
        $notifications = require $this->project->root . '/app/.foundry/build/projections/notification_index.php';

        $this->assertArrayHasKey('posts', $apiProjection);
        $this->assertArrayHasKey('welcome_email', $notifications);
        $this->assertSame(0, $result->diagnostics->summary()['error']);
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
