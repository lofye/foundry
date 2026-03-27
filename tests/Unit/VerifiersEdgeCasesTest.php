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

final class VerifiersEdgeCasesTest extends TestCase
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

    public function test_verifiers_report_contract_and_policy_errors(): void
    {
        $this->writeFeatureYaml('no_auth', <<<'YAML'
version: 1
feature: no_auth
kind: http
database:
  writes: []
YAML);

        $this->writeFeatureYaml('unguarded_write', <<<'YAML'
version: 1
feature: unguarded_write
kind: http
auth:
  required: false
  public: false
  permissions: [posts.create]
database:
  writes: [posts]
YAML);

        $this->writeFeatureYaml('cache_bad', <<<'YAML'
version: 1
feature: cache_bad
kind: http
auth:
  required: true
  permissions: []
YAML);
        file_put_contents($this->featurePath('cache_bad') . '/cache.yaml', <<<'YAML'
version: 1
entries:
  - key: "BAD KEY"
    kind: computed
    ttl_seconds: 60
    invalidated_by: [missing_feature]
YAML);

        $this->writeFeatureYaml('events_bad', <<<'YAML'
version: 1
feature: events_bad
kind: http
auth:
  required: true
  permissions: []
YAML);
        file_put_contents($this->featurePath('events_bad') . '/events.yaml', <<<'YAML'
version: 1
emit:
  - name: loop.event
    schema:
      type: object
      properties: {}
  - name: missing.schema
subscribe: [unknown.event, loop.event]
YAML);

        $this->writeFeatureYaml('jobs_bad', <<<'YAML'
version: 1
feature: jobs_bad
kind: http
auth:
  required: true
  permissions: []
YAML);
        file_put_contents($this->featurePath('jobs_bad') . '/jobs.yaml', <<<'YAML'
version: 1
dispatch:
  - not_a_job
  - name: ""
  - name: email_followers
    retry:
      max_attempts: 0
      backoff_seconds: []
    queue: ""
    timeout_seconds: 0
YAML);

        $this->writeFeatureYaml('contracts_bad', <<<'YAML'
version: 1
feature: contracts_bad
kind: http
auth:
  required: true
  permissions: []
YAML);
        file_put_contents($this->featurePath('contracts_bad') . '/input.schema.json', '{');
        file_put_contents($this->featurePath('contracts_bad') . '/jobs.yaml', <<<'YAML'
version: 1
dispatch:
  - name: x
YAML);
        file_put_contents($this->featurePath('contracts_bad') . '/events.yaml', <<<'YAML'
version: 1
emit:
  - name: x
YAML);
        file_put_contents($this->project->root . '/app/generated/schema_index.php', '<?php return "invalid";');

        file_put_contents($this->project->root . '/database/migrations/20260101000000_danger.sql', <<<'SQL'
DROP TABLE users;
DELETE FROM posts;
SQL);

        $paths = Paths::fromCwd($this->project->root);

        $auth = (new AuthVerifier($paths))->verify();
        $this->assertFalse($auth->ok);
        $this->assertNotEmpty($auth->errors);

        $cache = (new CacheVerifier($paths))->verify();
        $this->assertFalse($cache->ok);
        $this->assertNotEmpty($cache->errors);

        $events = (new EventsVerifier($paths))->verify();
        $this->assertFalse($events->ok);
        $this->assertNotEmpty($events->errors);
        $this->assertNotEmpty($events->warnings);

        $jobs = (new JobsVerifier($paths))->verify();
        $this->assertFalse($jobs->ok);
        $this->assertNotEmpty($jobs->errors);
        $this->assertNotEmpty($jobs->warnings);

        $contracts = (new ContractsVerifier($paths))->verify();
        $this->assertFalse($contracts->ok);
        $this->assertNotEmpty($contracts->errors);

        $migrations = (new MigrationsVerifier($paths))->verify();
        $this->assertNotEmpty($migrations->warnings);
    }

    public function test_feature_verifier_reports_many_manifest_and_file_issues(): void
    {
        $feature = 'feature_edge';
        $base = $this->featurePath($feature);
        mkdir($base . '/tests', 0777, true);

        file_put_contents($base . '/feature.yaml', <<<'YAML'
version: "1"
feature: mismatch_name
kind: invalid_kind
route:
  method: POST
  path: relative
auth:
  required: true
  permissions: [posts.create]
database:
  queries: [missing_query]
tests:
  required: [contract]
YAML);
        file_put_contents($base . '/action.php', '<?php declare(strict_types=1);');
        file_put_contents($base . '/context.manifest.json', '{"version":1}');
        file_put_contents($base . '/input.schema.json', '{');
        file_put_contents($base . '/output.schema.json', '{');
        file_put_contents($base . '/queries.sql', "-- name: other_query\nSELECT 1;\n");
        file_put_contents($base . '/permissions.yaml', "version: 1\npermissions: []\n");

        $paths = Paths::fromCwd($this->project->root);
        $result = (new FeatureVerifier($paths))->verify($feature);
        $this->assertFalse($result->ok);
        $this->assertNotEmpty($result->errors);

        $missing = (new FeatureVerifier($paths))->verify('unknown_feature');
        $this->assertFalse($missing->ok);
        $this->assertNotEmpty($missing->errors);
    }

    private function featurePath(string $feature): string
    {
        $path = $this->project->root . '/app/features/' . $feature;
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }

        return $path;
    }

    private function writeFeatureYaml(string $feature, string $yaml): void
    {
        file_put_contents($this->featurePath($feature) . '/feature.yaml', $yaml);
    }
}
