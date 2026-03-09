<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Compiler\CompileOptions;
use Foundry\Compiler\GraphCompiler;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class CompilerPassCoverageTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->seedComplexFeature();
        $this->seedDuplicateRouteFeature();
        $this->seedBadFeature();
        $this->seedSubscriberOnlyFeature();
        $this->seedBadOptionalFilesFeature();
        $this->seedInvalidManifestFeature();
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function test_compiler_passes_emit_expected_diagnostics_for_edge_cases(): void
    {
        $compiler = new GraphCompiler(Paths::fromCwd($this->project->root));
        $result = $compiler->compile(new CompileOptions());

        $codes = array_values(array_unique(array_map(
            static fn (array $row): string => (string) ($row['code'] ?? ''),
            $result->diagnostics->toArray(),
        )));
        sort($codes);

        $this->assertContains('FDY0102_MANIFEST_INVALID', $codes);
        $this->assertContains('FDY0103_INPUT_SCHEMA_INVALID', $codes);
        $this->assertContains('FDY0104_OUTPUT_SCHEMA_INVALID', $codes);
        $this->assertContains('FDY0105_PERMISSIONS_INVALID', $codes);
        $this->assertContains('FDY0106_EVENTS_INVALID', $codes);
        $this->assertContains('FDY0107_JOBS_INVALID', $codes);
        $this->assertContains('FDY0108_CACHE_INVALID', $codes);
        $this->assertContains('FDY0109_SCHEDULER_INVALID', $codes);
        $this->assertContains('FDY0110_WEBHOOKS_INVALID', $codes);
        $this->assertContains('FDY0111_CONTEXT_MANIFEST_INVALID', $codes);
        $this->assertContains('FDY0113_QUERY_PARSE_ERROR', $codes);

        $this->assertContains('FDY0201_FEATURE_NAME_MISMATCH', $codes);

        $this->assertContains('FDY1001_DUPLICATE_ROUTE', $codes);
        $this->assertContains('FDY1002_INVALID_FEATURE_KIND', $codes);
        $this->assertContains('FDY1004_ROUTE_PATH_INVALID', $codes);
        $this->assertContains('FDY1005_AUTH_STRATEGY_UNKNOWN', $codes);
        $this->assertContains('FDY1006_PERMISSION_REFERENCE_MISSING', $codes);
        $this->assertContains('FDY1007_JOB_REFERENCE_UNKNOWN', $codes);
        $this->assertContains('FDY1008_CACHE_REFERENCE_UNKNOWN', $codes);
        $this->assertContains('FDY1101_SCHEMA_NOT_FOUND_OR_INVALID', $codes);
        $this->assertContains('FDY1201_QUERY_REFERENCE_MISSING', $codes);
        $this->assertContains('FDY1202_QUERY_UNUSED', $codes);
        $this->assertContains('FDY1301_EVENT_SUBSCRIBE_UNKNOWN', $codes);
        $this->assertContains('FDY1302_EVENT_NO_SUBSCRIBERS', $codes);
        $this->assertContains('FDY1401_JOB_NAME_REUSED', $codes);
        $this->assertContains('FDY1501_PERMISSION_UNUSED', $codes);
        $this->assertContains('FDY1601_CONTEXT_MANIFEST_MISSING', $codes);

        $this->assertArrayHasKey('routes', $result->projections);
        $this->assertArrayHasKey('webhook', $result->projections);
        $this->assertArrayHasKey('scheduler', $result->projections);
        $this->assertArrayHasKey('query', $result->projections);

        $this->assertTrue($result->plan->mode === 'full');
        $this->assertNotEmpty($result->integrityHashes);
    }

    private function seedComplexFeature(): void
    {
        $feature = 'complex_feature';
        $base = $this->project->root . '/app/features/' . $feature;
        mkdir($base . '/tests', 0777, true);

        file_put_contents($base . '/feature.yaml', <<<'YAML'
version: 1
feature: wrong_name
kind: http
description: complex
route:
  method: POST
  path: /posts
input:
  schema: app/features/complex_feature/input.schema.json
output:
  schema: app/features/complex_feature/output.schema.json
auth:
  required: true
  strategies: [bearer]
  permissions: [posts.create]
database:
  reads: []
  writes: []
  transactions: required
  queries: [insert_post, missing_query]
cache:
  reads: []
  writes: []
  invalidate: [posts:list]
events:
  emit: [post.created]
  subscribe: [unknown.event]
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

        file_put_contents($base . '/queries.sql', <<<'SQL'
-- name: insert_post
INSERT INTO posts(id) VALUES(:id);

-- name: unused_query
SELECT 1;
SQL);

        file_put_contents($base . '/permissions.yaml', "version: 1\npermissions: [posts.create, posts.unused]\nrules: {}\n");
        file_put_contents($base . '/events.yaml', "version: 1\nemit:\n  - name: post.created\n    schema:\n      type: object\n      additionalProperties: false\n      properties: {}\nsubscribe: [unknown.event]\n");
        file_put_contents($base . '/jobs.yaml', "version: 1\ndispatch:\n  - name: notify_followers\n    input_schema:\n      type: object\n      additionalProperties: false\n      properties: {}\n    queue: default\n    retry:\n      max_attempts: 2\n      backoff_seconds: [1,2]\n    timeout_seconds: 30\n  - name: shared_job\n    input_schema:\n      type: object\n      additionalProperties: false\n      properties: {}\n    queue: default\n    retry:\n      max_attempts: 2\n      backoff_seconds: [1,2]\n    timeout_seconds: 30\n");
        file_put_contents($base . '/cache.yaml', "version: 1\nentries:\n  - key: posts:list\n    kind: computed\n    ttl_seconds: 300\n    invalidated_by: [complex_feature]\n");
        file_put_contents($base . '/scheduler.yaml', "version: 1\ntasks:\n  - name: nightly_rebuild\n    cron: \"0 0 * * *\"\n    job: shared_job\n");
        file_put_contents($base . '/webhooks.yaml', "version: 1\nincoming:\n  - name: stripe.incoming\n    path: /webhooks/stripe\n    method: post\noutgoing:\n  - name: billing.outgoing\n    event: post.created\n");
        file_put_contents($base . '/tests/complex_feature_contract_test.php', '<?php declare(strict_types=1);');
        file_put_contents($base . '/tests/complex_feature_feature_test.php', '<?php declare(strict_types=1);');
        file_put_contents($base . '/tests/complex_feature_auth_test.php', '<?php declare(strict_types=1);');
    }

    private function seedDuplicateRouteFeature(): void
    {
        $feature = 'duplicate_route';
        $base = $this->project->root . '/app/features/' . $feature;
        mkdir($base, 0777, true);

        file_put_contents($base . '/feature.yaml', <<<'YAML'
version: 1
feature: duplicate_route
kind: http
description: dup route
route:
  method: POST
  path: /posts
input:
  schema: app/features/duplicate_route/input.schema.json
output:
  schema: app/features/duplicate_route/output.schema.json
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
  dispatch: [shared_job]
rate_limit: {}
tests:
  required: []
llm:
  editable: true
  risk: low
YAML);
        file_put_contents($base . '/input.schema.json', '{"type":"object"}');
        file_put_contents($base . '/output.schema.json', '{"type":"object"}');
        file_put_contents($base . '/jobs.yaml', "version: 1\ndispatch:\n  - name: shared_job\n    input_schema:\n      type: object\n      additionalProperties: false\n      properties: {}\n    queue: default\n    retry:\n      max_attempts: 1\n      backoff_seconds: [1]\n    timeout_seconds: 30\n");
    }

    private function seedBadFeature(): void
    {
        $feature = 'bad_feature';
        $base = $this->project->root . '/app/features/' . $feature;
        mkdir($base, 0777, true);

        file_put_contents($base . '/feature.yaml', <<<'YAML'
version: 1
feature: bad_feature
kind: not_valid
description: bad
route:
  method: GET
  path: relative
input:
  schema: app/features/bad_feature/missing_input.schema.json
output:
  schema: app/features/bad_feature/missing_output.schema.json
auth:
  required: true
  strategies: [unknown_strategy]
  permissions: [missing.permission]
database:
  reads: []
  writes: []
  transactions: required
  queries: []
cache:
  reads: []
  writes: []
  invalidate: [missing:key]
events:
  emit: []
  subscribe: []
jobs:
  dispatch: [unknown_job]
rate_limit: {}
tests:
  required: []
llm:
  editable: false
  risk: low
YAML);

        file_put_contents($base . '/permissions.yaml', "version: 1\npermissions: [other.permission]\nrules: {}\n");
    }

    private function seedSubscriberOnlyFeature(): void
    {
        $feature = 'subscriber_only';
        $base = $this->project->root . '/app/features/' . $feature;
        mkdir($base, 0777, true);

        file_put_contents($base . '/feature.yaml', <<<'YAML'
version: 1
feature: subscriber_only
kind: http
description: subscriber only
route:
  method: GET
  path: /subscriber
input:
  schema: app/features/subscriber_only/input.schema.json
output:
  schema: app/features/subscriber_only/output.schema.json
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
  subscribe: [never.emitted]
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
        file_put_contents($base . '/events.yaml', "version: 1\nemit: []\nsubscribe: [never.emitted]\n");
    }

    private function seedBadOptionalFilesFeature(): void
    {
        $feature = 'bad_optional';
        $base = $this->project->root . '/app/features/' . $feature;
        mkdir($base, 0777, true);

        file_put_contents($base . '/feature.yaml', <<<'YAML'
version: 1
feature: bad_optional
kind: http
description: bad optional files
route:
  method: GET
  path: /bad-optional
input:
  schema: app/features/bad_optional/input.schema.json
output:
  schema: app/features/bad_optional/output.schema.json
auth:
  required: false
  strategies: []
  permissions: []
database:
  reads: []
  writes: []
  transactions: required
  queries: [dup_query]
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
  required: []
llm:
  editable: true
  risk: low
YAML);

        file_put_contents($base . '/input.schema.json', '{');
        file_put_contents($base . '/output.schema.json', '{');
        file_put_contents($base . '/permissions.yaml', "version: 1\npermissions: [ok]\nrules: [\n");
        file_put_contents($base . '/events.yaml', "version: 1\nemit: [\n");
        file_put_contents($base . '/jobs.yaml', "version: 1\ndispatch: [\n");
        file_put_contents($base . '/cache.yaml', "version: 1\nentries: [\n");
        file_put_contents($base . '/scheduler.yaml', "version: 1\ntasks: [\n");
        file_put_contents($base . '/webhooks.yaml', "version: 1\nincoming: [\n");
        file_put_contents($base . '/context.manifest.json', '{');
        file_put_contents($base . '/queries.sql', <<<'SQL'
-- name: dup_query
SELECT 1;

-- name: dup_query
SELECT 2;
SQL);
    }

    private function seedInvalidManifestFeature(): void
    {
        $feature = 'invalid_manifest';
        $base = $this->project->root . '/app/features/' . $feature;
        mkdir($base, 0777, true);
        file_put_contents($base . '/feature.yaml', "version: 1\nfeature: invalid_manifest\nkind: http\nroute: [\n");
    }
}
