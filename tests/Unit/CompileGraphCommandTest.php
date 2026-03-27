<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\CLI\CommandContext;
use Foundry\CLI\Commands\CompileGraphCommand;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class CompileGraphCommandTest extends TestCase
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

    public function test_run_supports_no_cache_and_cache_hit_messages(): void
    {
        $this->createFeature('publish_post', 'POST', '/posts');

        $command = new CompileGraphCommand();
        $context = new CommandContext();

        $disabled = $command->run(['compile', 'graph', '--no-cache'], $context);
        $this->assertSame(0, $disabled['status']);
        $this->assertStringContainsString('without using the compile cache', (string) $disabled['message']);

        $hit = $command->run(['compile', 'graph'], $context);
        $this->assertSame(0, $hit['status']);
        $this->assertStringContainsString('Compile cache hit', (string) $hit['message']);
        $this->assertSame('hit', $hit['payload']['cache']['status']);
    }

    public function test_run_parses_both_feature_flag_forms(): void
    {
        $this->createFeature('publish_post', 'POST', '/posts');
        $this->createFeature('list_posts', 'GET', '/posts');

        $command = new CompileGraphCommand();
        $context = new CommandContext();

        $command->run(['compile', 'graph'], $context);

        $manifestPath = $this->project->root . '/app/features/list_posts/feature.yaml';
        $manifest = (string) file_get_contents($manifestPath);
        file_put_contents($manifestPath, str_replace('description: test', 'description: updated', $manifest));

        $withEquals = $command->run(['compile', 'graph', '--feature=list_posts'], $context);
        $this->assertSame(0, $withEquals['status']);
        $this->assertContains('list_posts', $withEquals['payload']['plan']['selected_features']);

        $withSpace = $command->run(['compile', 'graph', '--feature', 'list_posts'], $context);
        $this->assertSame(0, $withSpace['status']);
        $this->assertContains('list_posts', $withSpace['payload']['plan']['selected_features']);
    }

    public function test_run_reports_invalidated_and_error_messages(): void
    {
        $this->createFeature('publish_post', 'POST', '/posts');
        $this->createFeature('create_post', 'POST', '/drafts');

        $command = new CompileGraphCommand();
        $context = new CommandContext();

        $command->run(['compile', 'graph'], $context);

        $manifestPath = $this->project->root . '/app/features/create_post/feature.yaml';
        $manifest = (string) file_get_contents($manifestPath);
        file_put_contents($manifestPath, str_replace('path: /drafts', 'path: /posts', $manifest));

        $invalidated = $command->run(['compile', 'graph'], $context);
        $this->assertSame(1, $invalidated['status']);
        $this->assertStringContainsString('Graph compiled with errors.', (string) $invalidated['message']);
        $this->assertSame('invalidated', $invalidated['payload']['cache']['status']);
    }

    public function test_run_reports_successful_cache_invalidation_message(): void
    {
        $this->createFeature('publish_post', 'POST', '/posts');

        $command = new CompileGraphCommand();
        $context = new CommandContext();

        $command->run(['compile', 'graph'], $context);

        $manifestPath = $this->project->root . '/app/features/publish_post/feature.yaml';
        $manifest = (string) file_get_contents($manifestPath);
        file_put_contents($manifestPath, str_replace('description: test', 'description: updated', $manifest));

        $invalidated = $command->run(['compile', 'graph'], $context);

        $this->assertSame(0, $invalidated['status']);
        $this->assertStringContainsString('after cache invalidation', (string) $invalidated['message']);
        $this->assertSame('invalidated', $invalidated['payload']['cache']['status']);
    }

    public function test_run_treats_empty_feature_option_as_unfiltered_compile(): void
    {
        $this->createFeature('publish_post', 'POST', '/posts');

        $command = new CompileGraphCommand();
        $context = new CommandContext();

        $result = $command->run(['compile', 'graph', '--feature='], $context);

        $this->assertSame(0, $result['status']);
        $this->assertContains('publish_post', $result['payload']['plan']['selected_features']);
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
