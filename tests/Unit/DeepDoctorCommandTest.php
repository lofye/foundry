<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\CLI\CommandContext;
use Foundry\CLI\Commands\DeepDoctorCommand;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class DeepDoctorCommandTest extends TestCase
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

    public function test_run_returns_deep_json_payload_when_project_is_healthy(): void
    {
        $this->createFeature('publish_post', 'POST', '/posts');

        $result = (new DeepDoctorCommand())->run(
            ['doctor', '--deep'],
            new CommandContext(null, true),
        );

        $this->assertSame(0, $result['status']);
        $this->assertNull($result['message']);
        $this->assertTrue($result['payload']['deep']);
        $this->assertSame('free', $result['payload']['monetization']['license']['tier']);
        $this->assertArrayHasKey('graph', $result['payload']['monetization']['deep_diagnostics']);
        $this->assertArrayHasKey('hotspots', $result['payload']['monetization']['deep_diagnostics']);
    }

    public function test_run_renders_human_summary_for_successful_deep_diagnostics(): void
    {
        $this->createFeature('publish_post', 'POST', '/posts');

        $result = (new DeepDoctorCommand())->run(
            ['doctor', '--deep'],
            new CommandContext(),
        );

        $this->assertSame(0, $result['status']);
        $this->assertStringContainsString('Foundry doctor completed with warnings and deep diagnostics.', (string) $result['message']);
        $this->assertStringContainsString('Summary:', (string) $result['message']);
        $this->assertStringContainsString('Graph:', (string) $result['message']);
    }

    public function test_run_uses_error_headline_when_base_doctor_detects_issues(): void
    {
        $this->createFeature('publish_post', 'POST', '/posts');
        $this->createFeature('create_post', 'POST', '/posts');

        $result = (new DeepDoctorCommand())->run(
            ['doctor', '--deep'],
            new CommandContext(),
        );

        $this->assertSame(1, $result['status']);
        $this->assertStringContainsString('Foundry doctor found issues during deep diagnostics.', (string) $result['message']);
        $this->assertStringContainsString('Summary:', (string) $result['message']);
    }

    private function createFeature(string $feature, string $method, string $path): void
    {
        $base = $this->project->root . '/app/features/' . $feature;
        mkdir($base . '/tests', 0777, true);

        file_put_contents($base . '/feature.yaml', <<<YAML
version: 1
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
  permissions: [posts.create]
database:
  reads: []
  writes: []
  transactions: required
  queries: []
cache:
  invalidate: []
events:
  emit: []
jobs:
  dispatch: []
tests:
  required: [contract, feature, auth]
YAML);
        file_put_contents($base . '/input.schema.json', '{"type":"object"}');
        file_put_contents($base . '/output.schema.json', '{"type":"object"}');
        file_put_contents($base . '/action.php', '<?php declare(strict_types=1);');
        file_put_contents($base . '/cache.yaml', "version: 1\nentries: []\n");
        file_put_contents($base . '/events.yaml', "version: 1\nemit: []\nsubscribe: []\n");
        file_put_contents($base . '/jobs.yaml', "version: 1\ndispatch: []\n");
        file_put_contents($base . '/permissions.yaml', "version: 1\npermissions: [posts.create]\nrules: {}\n");
        file_put_contents($base . '/context.manifest.json', '{"version":1,"feature":"' . $feature . '","kind":"http"}');
        file_put_contents($base . '/tests/' . $feature . '_contract_test.php', '<?php declare(strict_types=1);');
        file_put_contents($base . '/tests/' . $feature . '_feature_test.php', '<?php declare(strict_types=1);');
        file_put_contents($base . '/tests/' . $feature . '_auth_test.php', '<?php declare(strict_types=1);');
    }
}
