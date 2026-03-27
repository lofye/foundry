<?php
declare(strict_types=1);

namespace Foundry\Tests\Integration;

use Foundry\CLI\Application;
use Foundry\Generation\ContextManifestGenerator;
use Foundry\Generation\IndexGenerator;
use Foundry\Support\Paths;
use Foundry\Support\Yaml;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class CLIExtendedCommandsTest extends TestCase
{
    private TempProject $project;
    private string $cwd;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->cwd = getcwd() ?: '.';
        chdir($this->project->root);

        $feature = $this->project->root . '/app/features/publish_post';
        mkdir($feature . '/tests', 0777, true);

        file_put_contents($feature . '/feature.yaml', <<<'YAML'
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
rate_limit: {}
tests:
  required: [contract, feature, auth]
llm:
  editable: true
  risk: medium
YAML);

        file_put_contents($feature . '/input.schema.json', '{"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object","additionalProperties":false,"properties":{}}');
        file_put_contents($feature . '/output.schema.json', '{"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object","additionalProperties":false,"properties":{}}');
        file_put_contents($feature . '/action.php', '<?php declare(strict_types=1); namespace App\\Features\\PublishPost; use Foundry\\Feature\\FeatureAction; use Foundry\\Feature\\FeatureServices; use Foundry\\Auth\\AuthContext; use Foundry\\Http\\RequestContext; final class Action implements FeatureAction { public function handle(array $input, RequestContext $request, AuthContext $auth, FeatureServices $services): array { return []; } }');
        file_put_contents($feature . '/queries.sql', "-- name: insert_post\nINSERT INTO posts(id) VALUES(:id);\n");
        file_put_contents($feature . '/permissions.yaml', "version: 1\npermissions: [posts.create]\nrules: {}\n");
        file_put_contents($feature . '/cache.yaml', "version: 1\nentries:\n  - key: posts:list\n    kind: computed\n    ttl_seconds: 300\n    invalidated_by: [publish_post]\n");
        file_put_contents($feature . '/events.yaml', "version: 1\nemit:\n  - name: post.created\n    schema:\n      type: object\n      additionalProperties: false\n      properties: {}\nsubscribe: []\n");
        file_put_contents($feature . '/jobs.yaml', "version: 1\ndispatch:\n  - name: notify_followers\n    input_schema:\n      type: object\n      additionalProperties: false\n      properties: {}\n    queue: default\n    retry:\n      max_attempts: 2\n      backoff_seconds: [1,2]\n    timeout_seconds: 30\n\n");

        file_put_contents($feature . '/tests/publish_post_contract_test.php', '<?php declare(strict_types=1);');
        file_put_contents($feature . '/tests/publish_post_feature_test.php', '<?php declare(strict_types=1);');
        file_put_contents($feature . '/tests/publish_post_auth_test.php', '<?php declare(strict_types=1);');

        $paths = Paths::fromCwd($this->project->root);
        (new IndexGenerator($paths))->generate();
        $manifest = Yaml::parseFile($feature . '/feature.yaml');
        (new ContextManifestGenerator($paths))->write('publish_post', $manifest);

        file_put_contents($this->project->root . '/migration.yaml', "name: add_posts\ntable: posts\n");
        file_put_contents($this->project->root . '/storage/logs/trace.log', "event-1\n");
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        $this->project->cleanup();
    }

    public function test_inspect_variants_and_verify_targets(): void
    {
        $app = new Application();

        foreach (['auth', 'cache', 'events', 'jobs', 'context', 'dependencies'] as $target) {
            $result = $this->runCommand($app, ['foundry', 'inspect', $target, 'publish_post', '--json']);
            $this->assertSame(0, $result['status']);
        }

        foreach (['contracts', 'auth', 'cache', 'events', 'jobs', 'migrations'] as $target) {
            $result = $this->runCommand($app, ['foundry', 'verify', $target, '--json']);
            $this->assertSame(0, $result['status']);
            $this->assertArrayHasKey('ok', $result['payload']);
        }
    }

    public function test_generate_migration_and_runtime_commands(): void
    {
        $app = new Application();

        $generateTests = $this->runCommand($app, ['foundry', 'generate', 'tests', 'publish_post', '--json']);
        $this->assertSame(0, $generateTests['status']);

        $migration = $this->runCommand($app, ['foundry', 'generate', 'migration', 'migration.yaml', '--json']);
        $this->assertSame(0, $migration['status']);
        $this->assertFileExists($migration['payload']['file']);

        $this->assertSame(0, $this->runCommand($app, ['foundry', 'queue:work', '--json'])['status']);
        $this->assertSame(0, $this->runCommand($app, ['foundry', 'queue:inspect', '--json'])['status']);
        $this->assertSame(0, $this->runCommand($app, ['foundry', 'schedule:run', '--json'])['status']);
        $this->assertSame(0, $this->runCommand($app, ['foundry', 'trace:tail', '--json'])['status']);
    }

    public function test_inspect_route_success_and_failure(): void
    {
        $app = new Application();

        $ok = $this->runCommand($app, ['foundry', 'inspect', 'route', 'POST', '/posts', '--json']);
        $this->assertSame(0, $ok['status']);
        $this->assertSame('publish_post', $ok['payload']['feature']);

        $fail = $this->runCommand($app, ['foundry', 'inspect', 'route', 'GET', '/missing', '--json']);
        $this->assertSame(1, $fail['status']);
        $this->assertSame('ROUTE_NOT_FOUND', $fail['payload']['error']['code']);
    }

    public function test_plain_output_mode_is_supported(): void
    {
        $app = new Application();

        ob_start();
        $status = $app->run(['foundry', 'serve']);
        $output = ob_get_clean() ?: '';

        $this->assertSame(0, $status);
        $this->assertStringContainsString('Serve command configured.', $output);
    }

    /**
     * @param array<int,string> $argv
     * @return array{status:int,payload:array<string,mixed>}
     */
    private function runCommand(Application $app, array $argv): array
    {
        ob_start();
        $status = $app->run($argv);
        $output = ob_get_clean() ?: '';

        /** @var array<string,mixed> $payload */
        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        return ['status' => $status, 'payload' => $payload];
    }
}
