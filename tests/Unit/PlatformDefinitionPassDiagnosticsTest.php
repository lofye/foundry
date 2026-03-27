<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Compiler\CompileOptions;
use Foundry\Compiler\GraphCompiler;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class PlatformDefinitionPassDiagnosticsTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();

        $this->createFeature('list_posts');
        $this->createFeature('view_post');

        mkdir($this->project->root . '/app/definitions/billing', 0777, true);
        mkdir($this->project->root . '/app/definitions/workflows', 0777, true);
        mkdir($this->project->root . '/app/definitions/orchestrations', 0777, true);
        mkdir($this->project->root . '/app/definitions/search', 0777, true);
        mkdir($this->project->root . '/app/definitions/streams', 0777, true);
        mkdir($this->project->root . '/app/definitions/locales', 0777, true);
        mkdir($this->project->root . '/app/definitions/roles', 0777, true);
        mkdir($this->project->root . '/app/definitions/policies', 0777, true);
        mkdir($this->project->root . '/app/definitions/inspect-ui', 0777, true);

        file_put_contents($this->project->root . '/app/definitions/billing/paddle.billing.yaml', <<<'YAML'
version: 2
provider: paddle
plans:
  - key: starter
    price_id: ""
  - key: starter
    price_id: ""
feature_names:
  checkout: missing_checkout_feature
YAML);

        file_put_contents($this->project->root . '/app/definitions/workflows/posts.workflow.yaml', <<<'YAML'
version: 2
resource: posts
states: [draft]
transitions:
  publish:
    from: [review]
    to: archived
    permission: posts.publish
    emit: [post.published]
YAML);

        file_put_contents($this->project->root . '/app/definitions/workflows/empty.workflow.yaml', <<<'YAML'
version: 1
resource: empty
states: []
transitions: {}
YAML);

        file_put_contents($this->project->root . '/app/definitions/orchestrations/process.orchestration.yaml', <<<'YAML'
version: 2
name: process
steps:
  - name: a
    job: job_a
    depends_on: [b]
  - name: b
    job: job_b
    depends_on: [a]
  - name: a
    job: duplicate_a
  - name: orphan
    depends_on: [missing]
YAML);

        file_put_contents($this->project->root . '/app/definitions/search/posts.search.yaml', <<<'YAML'
version: 2
index: posts
adapter: unsupported
resource: posts
fields: []
filters: []
YAML);

        file_put_contents($this->project->root . '/app/definitions/streams/first.stream.yaml', <<<'YAML'
version: 2
stream: first
transport: websocket
route:
  path: /streams/shared
publish_features: [missing_feature]
YAML);

        file_put_contents($this->project->root . '/app/definitions/streams/second.stream.yaml', <<<'YAML'
version: 1
stream: second
transport: sse
route:
  path: /streams/shared
publish_features: [list_posts]
YAML);

        file_put_contents($this->project->root . '/app/definitions/locales/core.locale.yaml', <<<'YAML'
version: 2
bundle: core
default: en
locales: [fr]
translation_paths: [lang]
YAML);

        file_put_contents($this->project->root . '/app/definitions/roles/default.roles.yaml', <<<'YAML'
version: 2
set: default
roles:
  admin:
    permissions: [posts.unknown]
YAML);

        file_put_contents($this->project->root . '/app/definitions/roles/duplicate.roles.yaml', <<<'YAML'
version: 1
set: duplicate
roles:
  admin:
    permissions: [posts.create]
YAML);

        file_put_contents($this->project->root . '/app/definitions/policies/posts.policy.yaml', <<<'YAML'
version: 2
policy: posts
resource: posts
rules:
  ghost: [posts.view]
YAML);

        file_put_contents($this->project->root . '/app/definitions/inspect-ui/dev.inspect-ui.yaml', <<<'YAML'
version: 2
name: dev
enabled: true
base_path: /dev/inspect
YAML);
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function test_platform_definition_pass_emits_expected_diagnostics(): void
    {
        $compiler = new GraphCompiler(Paths::fromCwd($this->project->root));
        $result = $compiler->compile(new CompileOptions());

        $codes = array_values(array_map(
            static fn (array $row): string => (string) ($row['code'] ?? ''),
            $result->diagnostics->toArray(),
        ));

        $this->assertContains('FDY2401_BILLING_DEFINITION_VERSION_UNSUPPORTED', $codes);
        $this->assertContains('FDY2402_BILLING_PROVIDER_UNSUPPORTED', $codes);
        $this->assertContains('FDY2403_BILLING_PLAN_DUPLICATE', $codes);
        $this->assertContains('FDY2404_BILLING_PRICE_ID_MISSING', $codes);
        $this->assertContains('FDY2405_BILLING_FEATURE_MISSING', $codes);
        $this->assertContains('FDY2410_WORKFLOW_DEFINITION_VERSION_UNSUPPORTED', $codes);
        $this->assertContains('FDY2411_WORKFLOW_STATES_EMPTY', $codes);
        $this->assertContains('FDY2412_WORKFLOW_TRANSITION_FROM_INVALID', $codes);
        $this->assertContains('FDY2413_WORKFLOW_TRANSITION_TO_INVALID', $codes);
        $this->assertContains('FDY2414_WORKFLOW_PERMISSION_MISSING', $codes);
        $this->assertContains('FDY2415_WORKFLOW_EVENT_MISSING', $codes);
        $this->assertContains('FDY2420_ORCHESTRATION_DEFINITION_VERSION_UNSUPPORTED', $codes);
        $this->assertContains('FDY2421_ORCHESTRATION_STEP_DUPLICATE', $codes);
        $this->assertContains('FDY2422_ORCHESTRATION_JOB_MISSING', $codes);
        $this->assertContains('FDY2423_ORCHESTRATION_DEPENDENCY_UNKNOWN', $codes);
        $this->assertContains('FDY2424_ORCHESTRATION_CYCLE', $codes);
        $this->assertContains('FDY2425_ORCHESTRATION_JOB_UNKNOWN', $codes);
        $this->assertContains('FDY2430_SEARCH_DEFINITION_VERSION_UNSUPPORTED', $codes);
        $this->assertContains('FDY2431_SEARCH_ADAPTER_UNSUPPORTED', $codes);
        $this->assertContains('FDY2432_SEARCH_FIELDS_EMPTY', $codes);
        $this->assertContains('FDY2440_STREAM_DEFINITION_VERSION_UNSUPPORTED', $codes);
        $this->assertContains('FDY2441_STREAM_TRANSPORT_UNSUPPORTED', $codes);
        $this->assertContains('FDY2442_STREAM_ROUTE_CONFLICT', $codes);
        $this->assertContains('FDY2443_STREAM_FEATURE_MISSING', $codes);
        $this->assertContains('FDY2450_LOCALE_DEFINITION_VERSION_UNSUPPORTED', $codes);
        $this->assertContains('FDY2451_LOCALE_DEFAULT_INVALID', $codes);
        $this->assertContains('FDY2460_ROLES_DEFINITION_VERSION_UNSUPPORTED', $codes);
        $this->assertContains('FDY2461_ROLE_DUPLICATE', $codes);
        $this->assertContains('FDY2462_ROLE_PERMISSION_MISSING', $codes);
        $this->assertContains('FDY2470_POLICY_DEFINITION_VERSION_UNSUPPORTED', $codes);
        $this->assertContains('FDY2471_POLICY_ROLE_MISSING', $codes);
        $this->assertContains('FDY2480_INSPECT_UI_DEFINITION_VERSION_UNSUPPORTED', $codes);
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
  permissions: [posts.view]
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
        file_put_contents($base . '/permissions.yaml', "version: 1\npermissions: [posts.view]\nrules: {}\n");
        file_put_contents($base . '/cache.yaml', "version: 1\nentries: []\n");
        file_put_contents($base . '/events.yaml', "version: 1\nemit: []\nsubscribe: []\n");
        file_put_contents($base . '/jobs.yaml', "version: 1\ndispatch: []\n");
        file_put_contents($base . '/context.manifest.json', '{"version":1,"feature":"' . $feature . '","kind":"http"}');
        file_put_contents($base . '/tests/' . $feature . '_contract_test.php', '<?php declare(strict_types=1);');
    }
}
