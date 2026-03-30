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
        $definition = $this->project->root . '/publish_post.yaml';
        file_put_contents($definition, <<<'YAML'
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

        $this->assertSame(0, $this->runCommand($app, ['foundry', 'generate', 'feature', $definition, '--json'])['status']);
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

    public function test_help_and_api_surface_outputs_expose_stability_metadata(): void
    {
        $app = new Application();

        $helpIndex = $this->runCommand($app, ['foundry', 'help', '--json']);
        $this->assertSame(0, $helpIndex['status']);
        $this->assertArrayHasKey('commands', $helpIndex['payload']);
        $this->assertGreaterThan(0, (int) $helpIndex['payload']['summary']['stable']);
        $compileGraph = array_find(
            $helpIndex['payload']['commands']['stable'],
            static fn(array $row): bool => (string) ($row['signature'] ?? '') === 'compile graph',
        );
        $this->assertIsArray($compileGraph);
        $this->assertSame('Architecture', $compileGraph['category']);
        $this->assertSame('compile', $compileGraph['command_type']);

        $inspectGroupHelp = $this->runCommand($app, ['foundry', 'help', 'inspect', '--json']);
        $this->assertSame(0, $inspectGroupHelp['status']);
        $this->assertSame('inspect', $inspectGroupHelp['payload']['group']['name']);
        $this->assertGreaterThan(0, (int) $inspectGroupHelp['payload']['group']['counts']['stable']);
        $inspectGraph = array_find(
            $inspectGroupHelp['payload']['group']['commands']['stable'],
            static fn(array $row): bool => (string) ($row['signature'] ?? '') === 'inspect graph',
        );
        $this->assertIsArray($inspectGraph);
        $this->assertSame('Architecture', $inspectGraph['category']);

        $verifyGroupHelp = $this->runCommand($app, ['foundry', 'help', 'verify', '--json']);
        $this->assertSame(0, $verifyGroupHelp['status']);
        $this->assertSame('verify', $verifyGroupHelp['payload']['group']['name']);
        $this->assertGreaterThan(0, (int) $verifyGroupHelp['payload']['group']['counts']['stable']);
        $verifyGraph = array_find(
            $verifyGroupHelp['payload']['group']['commands']['stable'],
            static fn(array $row): bool => (string) ($row['signature'] ?? '') === 'verify graph',
        );
        $this->assertIsArray($verifyGraph);
        $this->assertSame('Verification', $verifyGraph['category']);

        $generateGroupHelp = $this->runCommand($app, ['foundry', 'help', 'generate', '--json']);
        $this->assertSame(0, $generateGroupHelp['status']);
        $this->assertSame('generate', $generateGroupHelp['payload']['group']['name']);
        $this->assertGreaterThan(0, (int) $generateGroupHelp['payload']['group']['counts']['stable']);
        $generateDocs = array_find(
            $generateGroupHelp['payload']['group']['commands']['stable'],
            static fn(array $row): bool => (string) ($row['signature'] ?? '') === 'generate docs',
        );
        $this->assertIsArray($generateDocs);
        $this->assertSame('Docs', $generateDocs['category']);

        $commandHelp = $this->runCommand($app, ['foundry', 'help', 'graph', 'visualize', '--json']);
        $this->assertSame(0, $commandHelp['status']);
        $this->assertSame('graph visualize', $commandHelp['payload']['command']['signature']);
        $this->assertSame('stable', $commandHelp['payload']['command']['stability']);
        $this->assertSame('Architecture', $commandHelp['payload']['command']['category']);
        $this->assertSame('graph', $commandHelp['payload']['command']['command_type']);
        $this->assertTrue($commandHelp['payload']['command']['supports_pipeline_stage_filter']);
        $this->assertTrue($commandHelp['payload']['command']['supports_extension_filter']);

        $inspectHelp = $this->runCommand($app, ['foundry', 'help', 'graph', 'inspect', '--json']);
        $this->assertSame(0, $inspectHelp['status']);
        $this->assertSame('graph inspect', $inspectHelp['payload']['command']['signature']);
        $this->assertSame('stable', $inspectHelp['payload']['command']['stability']);

        $exportHelp = $this->runCommand($app, ['foundry', 'help', 'export', 'graph', '--json']);
        $this->assertSame(0, $exportHelp['status']);
        $this->assertSame('export graph', $exportHelp['payload']['command']['signature']);
        $this->assertSame('stable', $exportHelp['payload']['command']['stability']);

        $newHelp = $this->runCommand($app, ['foundry', 'help', 'new', '--json']);
        $this->assertSame(0, $newHelp['status']);
        $this->assertSame('new', $newHelp['payload']['command']['signature']);
        $this->assertSame('stable', $newHelp['payload']['command']['stability']);
        $this->assertSame('App Scaffolding', $newHelp['payload']['command']['category']);
        $this->assertSame('new', $newHelp['payload']['command']['command_type']);

        $upgradeHelp = $this->runCommand($app, ['foundry', 'help', 'upgrade-check', '--json']);
        $this->assertSame(0, $upgradeHelp['status']);
        $this->assertSame('upgrade-check', $upgradeHelp['payload']['command']['signature']);
        $this->assertSame('stable', $upgradeHelp['payload']['command']['stability']);

        $cacheHelp = $this->runCommand($app, ['foundry', 'help', 'cache', 'inspect', '--json']);
        $this->assertSame(0, $cacheHelp['status']);
        $this->assertSame('cache inspect', $cacheHelp['payload']['command']['signature']);
        $this->assertSame('stable', $cacheHelp['payload']['command']['stability']);

        $explainHelp = $this->runCommand($app, ['foundry', 'help', 'explain', '--json']);
        $this->assertSame(0, $explainHelp['status']);
        $this->assertSame('explain', $explainHelp['payload']['command']['signature']);
        $this->assertSame('pro', $explainHelp['payload']['command']['availability']);
        $this->assertStringContainsString('--neighbors', $explainHelp['payload']['command']['usage']);
        $this->assertSame('Architecture', $explainHelp['payload']['command']['category']);
        $this->assertSame('explain', $explainHelp['payload']['command']['command_type']);
        $this->assertTrue($explainHelp['payload']['command']['supports_pipeline_stage_filter']);

        $proHelp = $this->runCommand($app, ['foundry', 'help', 'pro', '--json']);
        $this->assertSame(0, $proHelp['status']);
        $this->assertSame('pro', $proHelp['payload']['command']['signature']);
        $this->assertSame('pro', $proHelp['payload']['command']['availability']);

        $generatePromptHelp = $this->runCommand($app, ['foundry', 'help', 'generate', 'Add', '--json']);
        $this->assertSame(0, $generatePromptHelp['status']);
        $this->assertSame('generate <prompt>', $generatePromptHelp['payload']['command']['signature']);
        $this->assertSame('pro', $generatePromptHelp['payload']['command']['availability']);
        $this->assertStringContainsString('--deterministic', $generatePromptHelp['payload']['command']['usage']);
        $this->assertStringContainsString('--provider=<name>', $generatePromptHelp['payload']['command']['usage']);

        $apiSurface = $this->runCommand($app, ['foundry', 'inspect', 'api-surface', '--command=compile graph', '--json']);
        $this->assertSame(0, $apiSurface['status']);
        $this->assertSame('compile graph', $apiSurface['payload']['matches']['cli_command']['signature']);
        $this->assertSame('stable', $apiSurface['payload']['matches']['cli_command']['stability']);

        $cliSurfaceHelp = $this->runCommand($app, ['foundry', 'help', 'inspect', 'cli-surface', '--json']);
        $this->assertSame(0, $cliSurfaceHelp['status']);
        $this->assertSame('inspect cli-surface', $cliSurfaceHelp['payload']['command']['signature']);

        $inspectCliSurface = $this->runCommand($app, ['foundry', 'inspect', 'cli-surface', '--json']);
        $this->assertSame(0, $inspectCliSurface['status']);
        $this->assertGreaterThan(0, (int) $inspectCliSurface['payload']['summary']['total_signatures']);
        $helpRow = array_find(
            $inspectCliSurface['payload']['signatures'],
            static fn(array $row): bool => (string) ($row['signature'] ?? '') === 'help',
        );
        $this->assertIsArray($helpRow);
        $this->assertSame('Application::helpResult', $helpRow['handler']);

        $verifyCliSurface = $this->runCommand($app, ['foundry', 'verify', 'cli-surface', '--json']);
        $this->assertSame(0, $verifyCliSurface['status']);
        $this->assertSame(0, $verifyCliSurface['payload']['invalid']);
        $this->assertSame(0, $verifyCliSurface['payload']['ambiguous']);
        $this->assertSame(0, $verifyCliSurface['payload']['orphan_handlers']);
        $this->assertSame(1, $verifyCliSurface['payload']['coverage']);
    }

    public function test_non_json_cache_commands_emit_human_readable_output(): void
    {
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
        file_put_contents($base . '/context.manifest.json', '{"version":1,"feature":"publish_post","kind":"http"}');
        file_put_contents($base . '/tests/publish_post_contract_test.php', '<?php declare(strict_types=1);');
        file_put_contents($base . '/tests/publish_post_feature_test.php', '<?php declare(strict_types=1);');
        file_put_contents($base . '/tests/publish_post_auth_test.php', '<?php declare(strict_types=1);');

        $app = new Application();

        $cacheInspect = $this->runCommandRaw($app, ['foundry', 'cache', 'inspect']);
        $this->assertSame(0, $cacheInspect['status']);
        $this->assertStringContainsString('Compile cache status: miss', $cacheInspect['output']);

        $noCacheCompile = $this->runCommandRaw($app, ['foundry', 'compile', 'graph', '--no-cache']);
        $this->assertSame(0, $noCacheCompile['status']);
        $this->assertStringContainsString('Graph compiled without using the compile cache.', $noCacheCompile['output']);

        $cacheHitCompile = $this->runCommandRaw($app, ['foundry', 'compile', 'graph']);
        $this->assertSame(0, $cacheHitCompile['status']);
        $this->assertStringContainsString('Compile cache hit; reused existing build.', $cacheHitCompile['output']);

        $cacheClear = $this->runCommandRaw($app, ['foundry', 'cache', 'clear']);
        $this->assertSame(0, $cacheClear['status']);
        $this->assertStringContainsString('Compile cache cleared.', $cacheClear['output']);
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
