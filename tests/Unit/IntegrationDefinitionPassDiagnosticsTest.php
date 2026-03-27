<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Compiler\CompileOptions;
use Foundry\Compiler\GraphCompiler;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class IntegrationDefinitionPassDiagnosticsTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();

        $this->createFeature('dispatch_welcome_email', 'GET', '/dispatch/welcome');
        $this->createFeature('api_list_posts', 'GET', '/posts');

        mkdir($this->project->root . '/app/definitions/notifications', 0777, true);
        mkdir($this->project->root . '/app/definitions/api', 0777, true);

        file_put_contents($this->project->root . '/app/definitions/notifications/welcome_email.notification.yaml', <<<'YAML'
version: 2
notification: welcome_email
channel: sms
queue: ""
template: missing_template
input_schema: app/notifications/schemas/missing.input.schema.json
dispatch_features: [missing_feature]
YAML);

        file_put_contents($this->project->root . '/app/definitions/api/posts.api-resource.yaml', <<<'YAML'
version: 2
resource: posts
style: server-rendered
features: [list]
feature_names:
  list: api_list_posts
YAML);
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function test_integration_definition_pass_emits_expected_diagnostics_for_invalid_definitions(): void
    {
        $compiler = new GraphCompiler(Paths::fromCwd($this->project->root));
        $result = $compiler->compile(new CompileOptions());

        $codes = array_values(array_map(
            static fn(array $row): string => (string) ($row['code'] ?? ''),
            $result->diagnostics->toArray(),
        ));

        $this->assertContains('FDY2301_NOTIFICATION_DEFINITION_VERSION_UNSUPPORTED', $codes);
        $this->assertContains('FDY2302_NOTIFICATION_CHANNEL_UNSUPPORTED', $codes);
        $this->assertContains('FDY2303_NOTIFICATION_QUEUE_MISSING', $codes);
        $this->assertContains('FDY2304_NOTIFICATION_FEATURE_MISSING', $codes);
        $this->assertContains('FDY2305_NOTIFICATION_TEMPLATE_MISSING', $codes);
        $this->assertContains('FDY2306_NOTIFICATION_SCHEMA_MISSING', $codes);
        $this->assertContains('FDY2310_API_RESOURCE_DEFINITION_VERSION_UNSUPPORTED', $codes);
        $this->assertContains('FDY2312_API_FEATURE_ROUTE_NOT_API', $codes);
        $this->assertContains('FDY2314_API_RESOURCE_STYLE_MISMATCH', $codes);
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
