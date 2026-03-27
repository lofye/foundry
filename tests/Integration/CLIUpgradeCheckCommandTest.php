<?php
declare(strict_types=1);

namespace Foundry\Tests\Integration;

use Foundry\CLI\Application;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class CLIUpgradeCheckCommandTest extends TestCase
{
    private TempProject $project;
    private string $cwd;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->cwd = getcwd() ?: '.';
        chdir($this->project->root);

        $this->seedFeatureManifestV1();
        $this->seedLegacyConfig();
        $this->seedComposerScript();
        $this->seedUpgradeExtension();
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        $this->project->cleanup();
    }

    public function test_upgrade_check_exposes_json_and_human_reports(): void
    {
        $app = new Application();

        $json = $this->runCommand($app, ['foundry', 'upgrade-check', '--json']);
        $this->assertSame(1, $json['status']);
        $this->assertFalse($json['payload']['ok']);
        $this->assertSame('1.0.0', $json['payload']['target_version']);
        $this->assertArrayHasKey('summary', $json['payload']);
        $this->assertArrayHasKey('issues', $json['payload']);
        $this->assertArrayHasKey('checks', $json['payload']);
        $this->assertNotEmpty($json['payload']['issues']);

        $first = (array) ($json['payload']['issues'][0] ?? []);
        $this->assertArrayHasKey('summary', $first);
        $this->assertArrayHasKey('affected', $first);
        $this->assertArrayHasKey('why_it_matters', $first);
        $this->assertArrayHasKey('introduced_in', $first);
        $this->assertArrayHasKey('migration', $first);

        $human = $this->runRawCommand($app, ['foundry', 'upgrade-check']);
        $this->assertSame(1, $human['status']);
        $this->assertStringContainsString('Upgrade check found blocking issues.', $human['output']);
        $this->assertStringContainsString('Target framework: 1.0.0', $human['output']);
        $this->assertStringContainsString('Introduced in: 0.4.0', $human['output']);
        $this->assertStringNotContainsString('"issues"', $human['output']);
    }

    public function test_upgrade_check_validates_target_version(): void
    {
        $result = $this->runCommand(new Application(), ['foundry', 'upgrade-check', '--target=next', '--json']);

        $this->assertSame(1, $result['status']);
        $this->assertSame('CLI_UPGRADE_TARGET_INVALID', $result['payload']['error']['code']);
    }

    private function seedFeatureManifestV1(): void
    {
        $base = $this->project->root . '/app/features/publish_post';
        mkdir($base . '/tests', 0777, true);

        file_put_contents($base . '/feature.yaml', <<<'YAML'
version: 1
feature: publish_post
kind: http
description: Publish a post.
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
tests:
  required: [feature]
YAML);

        file_put_contents($base . '/input.schema.json', '{"type":"object","additionalProperties":false,"properties":{}}');
        file_put_contents($base . '/output.schema.json', '{"type":"object","additionalProperties":false,"properties":{}}');
        file_put_contents($base . '/action.php', '<?php declare(strict_types=1);');
        file_put_contents($base . '/queries.sql', "-- name: insert_post\nINSERT INTO posts(id) VALUES(:id);\n");
        file_put_contents($base . '/permissions.yaml', "version: 1\npermissions: [posts.create]\nrules: {}\n");
        file_put_contents($base . '/cache.yaml', "version: 1\nentries:\n  - key: posts:list\n    kind: computed\n    ttl_seconds: 300\n    invalidated_by: [publish_post]\n");
        file_put_contents($base . '/events.yaml', "version: 1\nemit:\n  - name: post.created\n    schema:\n      type: object\n      additionalProperties: false\n      properties: {}\nsubscribe: []\n");
        file_put_contents($base . '/jobs.yaml', "version: 1\ndispatch:\n  - name: notify_followers\n    input_schema:\n      type: object\n      additionalProperties: false\n      properties: {}\n    queue: default\n    retry:\n      max_attempts: 2\n      backoff_seconds: [1, 2]\n    timeout_seconds: 30\n");
    }

    private function seedLegacyConfig(): void
    {
        mkdir($this->project->root . '/config', 0777, true);
        file_put_contents($this->project->root . '/config/storage.php', <<<'PHP'
<?php
declare(strict_types=1);

return [
    'default' => 'local',
    'local_root' => 'storage/files',
];
PHP);
    }

    private function seedComposerScript(): void
    {
        file_put_contents($this->project->root . '/composer.json', <<<'JSON'
{
  "name": "foundry/tests-app",
  "type": "project",
  "require": {
    "php": "^8.4",
    "ext-json": "*",
    "ext-pdo": "*"
  },
  "scripts": {
    "bootstrap-app": "foundry init app demo-app --starter=minimal"
  }
}
JSON);
    }

    private function seedUpgradeExtension(): void
    {
        file_put_contents($this->project->root . '/foundry.extensions.php', <<<'PHP'
<?php
declare(strict_types=1);

return [
    \Foundry\Tests\Fixtures\CustomUpgradeExtension::class,
];
PHP);
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
    private function runRawCommand(Application $app, array $argv): array
    {
        ob_start();
        $status = $app->run($argv);
        $output = ob_get_clean() ?: '';

        return ['status' => $status, 'output' => $output];
    }
}
