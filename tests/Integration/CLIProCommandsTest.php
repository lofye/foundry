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

final class CLIProCommandsTest extends TestCase
{
    private TempProject $project;
    private string $cwd;
    private ?string $previousFoundryHome = null;
    private ?string $previousLicensePath = null;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->cwd = getcwd() ?: '.';
        chdir($this->project->root);

        $this->previousFoundryHome = getenv('FOUNDRY_HOME') !== false ? (string) getenv('FOUNDRY_HOME') : null;
        $this->previousLicensePath = getenv('FOUNDRY_LICENSE_PATH') !== false ? (string) getenv('FOUNDRY_LICENSE_PATH') : null;
        putenv('FOUNDRY_HOME=' . $this->project->root . '/.foundry-home');
        putenv('FOUNDRY_LICENSE_PATH');
        mkdir($this->project->root . '/.foundry-home', 0777, true);

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
        file_put_contents($this->project->root . '/app/platform/logs/trace.log', "publish:started\npublish:finished\ncache:flush\n");

        $paths = Paths::fromCwd($this->project->root);
        (new IndexGenerator($paths))->generate();
        $manifest = Yaml::parseFile($feature . '/feature.yaml');
        (new ContextManifestGenerator($paths))->write('publish_post', $manifest);
    }

    protected function tearDown(): void
    {
        $this->restoreEnv('FOUNDRY_HOME', $this->previousFoundryHome);
        $this->restoreEnv('FOUNDRY_LICENSE_PATH', $this->previousLicensePath);
        chdir($this->cwd);
        $this->project->cleanup();
    }

    public function test_pro_commands_are_gated_without_license(): void
    {
        $app = new Application();

        $status = $this->runCommand($app, ['foundry', 'pro', 'status', '--json']);
        $this->assertSame(0, $status['status']);
        $this->assertFalse($status['payload']['license']['valid']);

        $explain = $this->runCommand($app, ['foundry', 'explain', 'publish_post', '--json']);
        $this->assertSame(1, $explain['status']);
        $this->assertSame('PRO_LICENSE_REQUIRED', $explain['payload']['error']['code']);

        $deep = $this->runCommand($app, ['foundry', 'doctor', '--deep', '--json']);
        $this->assertSame(1, $deep['status']);
        $this->assertSame('PRO_LICENSE_REQUIRED', $deep['payload']['error']['code']);

        $generate = $this->runCommand($app, ['foundry', 'generate', 'Add', 'bookmark', 'support', '--json']);
        $this->assertSame(1, $generate['status']);
        $this->assertSame('PRO_LICENSE_REQUIRED', $generate['payload']['error']['code']);

        $help = $this->runCommandRaw($app, ['foundry', 'help']);
        $this->assertSame(0, $help['status']);
        $this->assertStringContainsString(' [Pro]', $help['output']);
        $this->assertStringContainsString('generate <prompt> [Pro]', $help['output']);
    }

    public function test_pro_commands_run_with_valid_local_license(): void
    {
        $app = new Application();

        $enable = $this->runCommand($app, ['foundry', 'pro', 'enable', $this->validKey(), '--json']);
        $this->assertSame(0, $enable['status']);
        $this->assertTrue($enable['payload']['license']['valid']);

        $compile = $this->runCommand($app, ['foundry', 'compile', 'graph', '--json']);
        $this->assertSame(0, $compile['status']);

        $explain = $this->runCommand($app, ['foundry', 'explain', 'publish_post', '--json']);
        $this->assertSame(0, $explain['status']);
        $this->assertSame('feature:publish_post', $explain['payload']['resolved_node_id']);
        $this->assertArrayHasKey('pro', $explain['payload']);

        $trace = $this->runCommand($app, ['foundry', 'trace', 'publish', '--json']);
        $this->assertSame(0, $trace['status']);
        $this->assertSame(2, $trace['payload']['matched_events']);

        $generate = $this->runCommand($app, ['foundry', 'generate', 'Add', 'bookmark', 'support', '--feature-context', '--dry-run', '--json']);
        $this->assertSame(0, $generate['status']);
        $this->assertSame('pro_generate', $generate['payload']['mode']);

        $deep = $this->runCommand($app, ['foundry', 'doctor', '--deep', '--json']);
        $this->assertSame(0, $deep['status']);
        $this->assertTrue($deep['payload']['deep']);
        $this->assertArrayHasKey('deep_diagnostics', $deep['payload']['pro']);

        file_put_contents(
            $this->project->root . '/app/features/publish_post/feature.yaml',
            str_replace('description: test', 'description: updated test', (string) file_get_contents($this->project->root . '/app/features/publish_post/feature.yaml')),
        );

        $diff = $this->runCommand($app, ['foundry', 'diff', '--json']);
        $this->assertSame(0, $diff['status']);
        $this->assertGreaterThanOrEqual(1, $diff['payload']['summary']['changed_nodes']);
    }

    private function validKey(): string
    {
        $body = 'FPRO-ABCD-EFGH-IJKL-MNOP';

        return $body . '-' . strtoupper(substr(hash('sha256', 'foundry-pro:' . $body), 0, 8));
    }

    private function restoreEnv(string $name, ?string $value): void
    {
        if ($value === null) {
            putenv($name);

            return;
        }

        putenv($name . '=' . $value);
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

    /**
     * @param array<int,string> $argv
     * @return array{status:int,output:string}
     */
    private function runCommandRaw(Application $app, array $argv): array
    {
        ob_start();
        $status = $app->run($argv);
        $output = (string) (ob_get_clean() ?: '');

        return ['status' => $status, 'output' => $output];
    }
}
