<?php
declare(strict_types=1);

namespace Foundry\Tests\Integration;

use Foundry\CLI\Application;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class CLIPhase2CommandsTest extends TestCase
{
    private TempProject $project;
    private string $cwd;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->cwd = getcwd() ?: '.';
        chdir($this->project->root);

        mkdir($this->project->root . '/specs', 0777, true);
        file_put_contents($this->project->root . '/specs/posts.api-resource.yaml', <<<'YAML'
version: 1
resource: posts
style: api
model:
  table: posts
  primary_key: id
fields:
  title:
    type: string
    required: true
  slug:
    type: string
    required: true
auth:
  list: posts.view
  view: posts.view
  create: posts.create
  update: posts.update
  delete: posts.delete
features: [list, view, create, update, delete]
YAML);
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        $this->project->cleanup();
    }

    public function test_phase2_commands_generate_inspect_verify_export_and_docs(): void
    {
        $app = new Application();

        $notification = $this->runCommand($app, ['foundry', 'generate', 'notification', 'welcome_email', '--json']);
        $this->assertSame(0, $notification['status']);
        $this->assertSame('welcome_email', $notification['payload']['notification']);

        $apiResource = $this->runCommand($app, ['foundry', 'generate', 'api-resource', 'posts', '--spec=specs/posts.api-resource.yaml', '--json']);
        $this->assertSame(0, $apiResource['status']);
        $this->assertContains('api_create_post', $apiResource['payload']['features']);
        $this->assertFileExists($this->project->root . '/app/specs/api/posts.api-resource.yaml');

        $compile = $this->runCommand($app, ['foundry', 'compile', 'graph', '--json']);
        $this->assertSame(0, $compile['status']);

        $inspectNotification = $this->runCommand($app, ['foundry', 'inspect', 'notification', 'welcome_email', '--json']);
        $this->assertSame(0, $inspectNotification['status']);
        $this->assertSame('notification', $inspectNotification['payload']['notification']['type']);

        $preview = $this->runCommand($app, ['foundry', 'preview', 'notification', 'welcome_email', '--json']);
        $this->assertSame(0, $preview['status']);
        $this->assertSame('welcome_email', $preview['payload']['notification']);

        $inspectApi = $this->runCommand($app, ['foundry', 'inspect', 'api', 'posts', '--json']);
        $this->assertSame(0, $inspectApi['status']);
        $this->assertSame('api_resource', $inspectApi['payload']['api_resource']['type']);

        $verifyNotifications = $this->runCommand($app, ['foundry', 'verify', 'notifications', '--json']);
        $this->assertSame(0, $verifyNotifications['status']);
        $this->assertTrue($verifyNotifications['payload']['ok']);

        $verifyApi = $this->runCommand($app, ['foundry', 'verify', 'api', '--json']);
        $this->assertSame(0, $verifyApi['status']);
        $this->assertTrue($verifyApi['payload']['ok']);

        $exportOpenApi = $this->runCommand($app, ['foundry', 'export', 'openapi', '--format=json', '--json']);
        $this->assertSame(0, $exportOpenApi['status']);
        $this->assertSame('json', $exportOpenApi['payload']['format']);
        $this->assertArrayHasKey('/api/posts', $exportOpenApi['payload']['openapi']['paths']);

        $docs = $this->runCommand($app, ['foundry', 'generate', 'docs', '--format=markdown', '--json']);
        $this->assertSame(0, $docs['status']);
        $this->assertFileExists($this->project->root . '/docs/generated/features.md');

        $deepTests = $this->runCommand($app, ['foundry', 'generate', 'tests', 'api_create_post', '--mode=deep', '--json']);
        $this->assertSame(0, $deepTests['status']);
        $this->assertSame('feature', $deepTests['payload']['kind']);

        $allMissing = $this->runCommand($app, ['foundry', 'generate', 'tests', '--all-missing', '--mode=deep', '--json']);
        $this->assertSame(0, $allMissing['status']);
        $this->assertSame('deep', $allMissing['payload']['mode']);
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
