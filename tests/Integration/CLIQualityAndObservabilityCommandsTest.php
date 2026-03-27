<?php

declare(strict_types=1);

namespace Foundry\Tests\Integration;

use Foundry\CLI\Application;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class CLIQualityAndObservabilityCommandsTest extends TestCase
{
    private TempProject $project;
    private string $cwd;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->cwd = getcwd() ?: '.';
        chdir($this->project->root);
        $this->seedFeature();
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        $this->project->cleanup();
    }

    public function test_observe_history_and_compare_commands_persist_records(): void
    {
        $this->writePassingQualityTools();
        $app = new Application();

        $trace = $this->runCommand($app, ['foundry', 'observe:trace', 'publish_post', '--json']);
        $this->assertSame(0, $trace['status']);
        $this->assertSame(1, $trace['payload']['summary']['execution_paths']);
        $this->assertSame('execution_plan:feature:publish_post', $trace['payload']['execution_paths'][0]['graph_mapping']['execution_plan']);

        $profile = $this->runCommand($app, ['foundry', 'observe:profile', 'publish_post', '--json']);
        $this->assertSame(0, $profile['status']);
        $this->assertArrayHasKey('compile_ms', $profile['payload']['timings']);
        $this->assertNotEmpty($profile['payload']['record']['id']);

        $history = $this->runCommand($app, ['foundry', 'history', '--json']);
        $this->assertSame(0, $history['status']);
        $historyIds = array_values(array_map(
            static fn(array $row): string => (string) ($row['id'] ?? ''),
            $history['payload']['entries'],
        ));
        $this->assertContains($trace['payload']['record']['id'], $historyIds);
        $this->assertContains($profile['payload']['record']['id'], $historyIds);

        $compare = $this->runCommand($app, [
            'foundry',
            'observe:compare',
            $trace['payload']['record']['id'],
            $trace['payload']['record']['id'],
            '--json',
        ]);
        $this->assertSame(0, $compare['status']);
        $this->assertSame([], $compare['payload']['changed_execution_paths']);
        $this->assertNotEmpty($compare['payload']['record']['id']);
    }

    public function test_doctor_quality_and_regressions_detect_static_regressions(): void
    {
        $app = new Application();
        $this->writePassingQualityTools();

        $baselineQuality = $this->runCommand($app, ['foundry', 'doctor', '--quality', '--json']);
        $this->assertSame(0, $baselineQuality['status']);
        $this->assertSame('passed', $baselineQuality['payload']['static_analysis']['status']);
        $this->assertSame('passed', $baselineQuality['payload']['style_violations']['status']);

        $this->assertSame(0, $this->runCommand($app, ['foundry', 'observe:profile', '--json'])['status']);

        $this->writeFailingPhpstan();
        $failingQuality = $this->runCommand($app, ['foundry', 'doctor', '--quality', '--json']);
        $this->assertSame(1, $failingQuality['status']);
        $this->assertSame(1, $failingQuality['payload']['static_analysis']['summary']['total']);

        $regressions = $this->runCommand($app, ['foundry', 'regressions', '--json']);
        $this->assertSame(1, $regressions['status']);
        $this->assertSame(1, $regressions['payload']['summary']['static_analysis_regressions']);
        $this->assertIsArray($regressions['payload']['quality_comparison']);
    }

    private function seedFeature(): void
    {
        $base = $this->project->root . '/app/features/publish_post';
        mkdir($base . '/tests', 0777, true);

        file_put_contents($base . '/feature.yaml', <<<'YAML'
version: 2
feature: publish_post
kind: http
description: publish
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
  required: [feature]
llm:
  editable: true
  risk: low
YAML);
        file_put_contents($base . '/input.schema.json', '{"type":"object","properties":{"title":{"type":"string"}}}');
        file_put_contents($base . '/output.schema.json', '{"type":"object","properties":{"id":{"type":"string"}}}');
        file_put_contents($base . '/action.php', <<<'PHP'
<?php
declare(strict_types=1);

namespace App\Features\PublishPost;

use Foundry\Auth\AuthContext;
use Foundry\Feature\FeatureAction;
use Foundry\Feature\FeatureServices;
use Foundry\Http\RequestContext;

final class Action implements FeatureAction
{
    public function handle(array $input, RequestContext $request, AuthContext $auth, FeatureServices $services): array
    {
        return ['id' => 'post-1'];
    }
}
PHP);
        file_put_contents($base . '/events.yaml', "version: 1\nemit: []\nsubscribe: []\n");
        file_put_contents($base . '/jobs.yaml', "version: 1\ndispatch: []\n");
        file_put_contents($base . '/cache.yaml', "version: 1\nentries: []\n");
        file_put_contents($base . '/permissions.yaml', "version: 1\npermissions: [posts.create]\nrules:\n  admin: [posts.create]\n");
        file_put_contents($base . '/context.manifest.json', '{"version":1,"feature":"publish_post","kind":"http"}');
        file_put_contents($base . '/tests/publish_post_feature_test.php', '<?php declare(strict_types=1);');
    }

    private function writePassingQualityTools(): void
    {
        $this->writeExecutable($this->project->root . '/vendor/bin/phpstan', <<<'SH'
#!/bin/sh
cat <<'JSON'
{"totals":{"errors":0,"file_errors":0},"files":[]}
JSON
exit 0
SH);
        $this->writeExecutable($this->project->root . '/vendor/bin/pint', <<<'SH'
#!/bin/sh
cat <<'JSON'
{"files":[]}
JSON
exit 0
SH);
    }

    private function writeFailingPhpstan(): void
    {
        $this->writeExecutable($this->project->root . '/vendor/bin/phpstan', <<<'SH'
#!/bin/sh
cat <<'JSON'
{"totals":{"errors":0,"file_errors":1},"files":{"app/features/publish_post/action.php":{"errors":1,"messages":[{"message":"Undefined method call.","line":12,"identifier":"method.notFound","tip":"Fix the receiver type."}]}}}
JSON
exit 1
SH);
    }

    private function writeExecutable(string $path, string $contents): void
    {
        file_put_contents($path, $contents);
        chmod($path, 0755);
    }

    /**
     * @param array<int,string> $argv
     * @return array{status:int,payload:array<string,mixed>}
     */
    private function runCommand(Application $app, array $argv): array
    {
        ob_start();
        $status = $app->run($argv);
        $output = (string) ob_get_clean();

        /** @var array<string,mixed> $payload */
        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        return ['status' => $status, 'payload' => $payload];
    }
}
