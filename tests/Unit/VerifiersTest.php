<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use Foundry\Verification\AuthVerifier;
use Foundry\Verification\CacheVerifier;
use Foundry\Verification\ContractsVerifier;
use Foundry\Verification\EventsVerifier;
use Foundry\Verification\FeatureVerifier;
use Foundry\Verification\JobsVerifier;
use Foundry\Verification\MigrationsVerifier;
use PHPUnit\Framework\TestCase;

final class VerifiersTest extends TestCase
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
  writes: [posts]
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
rate_limit: {}
tests:
  required: [contract, feature, auth]
llm:
  editable: true
  risk: medium
YAML);

        file_put_contents($base . '/input.schema.json', '{"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object","additionalProperties":false,"properties":{}}');
        file_put_contents($base . '/output.schema.json', '{"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object","additionalProperties":false,"properties":{}}');
        file_put_contents($base . '/action.php', '<?php declare(strict_types=1);');
        file_put_contents($base . '/context.manifest.json', '{"version":1,"feature":"publish_post","kind":"http","relevant_files":[],"generated_files":[],"upstream_dependencies":[],"downstream_dependents":[],"contracts":{},"tests":[],"forbidden_paths":[],"risk_level":"medium"}');
        file_put_contents($base . '/queries.sql', "-- name: insert_post\nINSERT INTO posts(id) VALUES(:id);\n");
        file_put_contents($base . '/permissions.yaml', "version: 1\npermissions: [posts.create]\nrules: {}\n");
        file_put_contents($base . '/cache.yaml', "version: 1\nentries:\n  - key: posts:list\n    kind: computed\n    ttl_seconds: 300\n    invalidated_by: [publish_post]\n");
        file_put_contents($base . '/events.yaml', "version: 1\nemit:\n  - name: post.created\n    schema:\n      type: object\n      additionalProperties: false\n      properties: {}\nsubscribe: []\n");
        file_put_contents($base . '/jobs.yaml', "version: 1\ndispatch:\n  - name: notify_followers\n    input_schema:\n      type: object\n      additionalProperties: false\n      properties: {}\n    queue: default\n    retry:\n      max_attempts: 3\n      backoff_seconds: [1,5,30]\n    timeout_seconds: 30\n");

        file_put_contents($base . '/tests/publish_post_contract_test.php', '<?php declare(strict_types=1);');
        file_put_contents($base . '/tests/publish_post_feature_test.php', '<?php declare(strict_types=1);');
        file_put_contents($base . '/tests/publish_post_auth_test.php', '<?php declare(strict_types=1);');

        file_put_contents($this->project->root . '/app/generated/schema_index.php', '<?php return [];');
        file_put_contents($this->project->root . '/database/migrations/20260101000000_add.sql', 'CREATE TABLE x (id TEXT);');
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function test_feature_verifier_passes_for_valid_feature(): void
    {
        $result = (new FeatureVerifier(Paths::fromCwd($this->project->root)))->verify('publish_post');
        $this->assertTrue($result->ok);
    }

    public function test_other_verifiers_run(): void
    {
        $paths = Paths::fromCwd($this->project->root);

        $this->assertTrue((new ContractsVerifier($paths))->verify()->ok);
        $this->assertTrue((new AuthVerifier($paths))->verify()->ok);
        $this->assertTrue((new CacheVerifier($paths))->verify()->ok);
        $this->assertTrue((new EventsVerifier($paths))->verify()->ok);
        $this->assertTrue((new JobsVerifier($paths))->verify()->ok);
        $this->assertTrue((new MigrationsVerifier($paths))->verify()->ok);
    }
}
