<?php
declare(strict_types=1);

namespace Foundry\Tests\Integration;

use Foundry\CLI\Application;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class CLIApplicationTest extends TestCase
{
    private TempProject $project;
    private string $cwd;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->cwd = getcwd() ?: '.';
        chdir($this->project->root);
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        $this->project->cleanup();
    }

    public function test_generate_inspect_and_verify_commands(): void
    {
        $spec = $this->project->root . '/publish_post.yaml';
        file_put_contents($spec, <<<'YAML'
version: 1
feature: publish_post
kind: http
description: Create a post
route:
  method: POST
  path: /posts
input:
  fields:
    title:
      type: string
      required: true
output:
  fields:
    id:
      type: string
      required: true
auth:
  required: true
  strategies: [bearer]
  permissions: [posts.create]
database:
  reads: []
  writes: []
  queries: []
  transactions: required
cache:
  invalidate: []
events:
  emit: []
jobs:
  dispatch: []
tests:
  required: [contract, feature, auth]
YAML);

        $app = new Application();

        $this->assertSame(0, $this->runCommand($app, ['foundry', 'generate', 'feature', $spec, '--json'])['status']);
        $this->assertFileExists($this->project->root . '/app/features/publish_post/feature.yaml');

        $this->assertSame(0, $this->runCommand($app, ['foundry', 'generate', 'indexes', '--json'])['status']);

        $inspect = $this->runCommand($app, ['foundry', 'inspect', 'feature', 'publish_post', '--json']);
        $this->assertSame(0, $inspect['status']);
        $this->assertSame('publish_post', $inspect['payload']['feature']);

        $verify = $this->runCommand($app, ['foundry', 'verify', 'feature', 'publish_post', '--json']);
        $this->assertSame(0, $verify['status']);
        $this->assertTrue($verify['payload']['ok']);

        $context = $this->runCommand($app, ['foundry', 'generate', 'context', 'publish_post', '--json']);
        $this->assertSame(0, $context['status']);

        $affected = $this->runCommand($app, ['foundry', 'affected-files', 'publish_post', '--json']);
        $this->assertSame(0, $affected['status']);
        $this->assertNotEmpty($affected['payload']['affected_files']);

        $impacted = $this->runCommand($app, ['foundry', 'impacted-features', 'posts.create', '--json']);
        $this->assertSame(0, $impacted['status']);
        $this->assertContains('publish_post', $impacted['payload']['features']);
    }

    public function test_unknown_command_returns_structured_error(): void
    {
        $app = new Application();
        $result = $this->runCommand($app, ['foundry', 'unknown', '--json']);

        $this->assertSame(1, $result['status']);
        $this->assertSame('CLI_COMMAND_NOT_FOUND', $result['payload']['error']['code']);
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
