<?php
declare(strict_types=1);

namespace Foundry\Tests\Integration;

use Foundry\CLI\Application;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class CLIPhase1CommandsTest extends TestCase
{
    private TempProject $project;
    private string $cwd;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->cwd = getcwd() ?: '.';
        chdir($this->project->root);

        mkdir($this->project->root . '/specs', 0777, true);
        file_put_contents($this->project->root . '/specs/posts.resource.yaml', <<<'YAML'
version: 1
resource: posts
style: server-rendered
model:
  table: posts
  primary_key: id
fields:
  title:
    type: string
    required: true
    maxLength: 200
    list: true
    form: text
    search: true
    sort: true
  slug:
    type: string
    required: true
    unique: true
    list: true
    form: text
    search: true
    sort: true
  body_markdown:
    type: text
    required: true
    form: textarea
  published_at:
    type: datetime
    required: false
    form: datetime
    filter: true
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

    public function test_phase1_generation_commands_compile_and_verify(): void
    {
        $app = new Application();

        $starter = $this->runCommand($app, ['foundry', 'generate', 'starter', 'server-rendered', '--name=demo-app', '--json']);
        $this->assertSame(0, $starter['status']);
        $this->assertContains('register_user', $starter['payload']['features']);

        $resource = $this->runCommand($app, ['foundry', 'generate', 'resource', 'posts', '--spec=specs/posts.resource.yaml', '--json']);
        $this->assertSame(0, $resource['status']);
        $this->assertContains('list_posts', $resource['payload']['features']);
        $this->assertFileExists($this->project->root . '/app/specs/resources/posts.resource.yaml');
        $this->assertFileExists($this->project->root . '/app/specs/listing/posts.list.yaml');

        $admin = $this->runCommand($app, ['foundry', 'generate', 'admin-resource', 'posts', '--json']);
        $this->assertSame(0, $admin['status']);
        $this->assertContains('admin_list_posts', $admin['payload']['features']);

        $uploads = $this->runCommand($app, ['foundry', 'generate', 'uploads', 'avatar', '--json']);
        $this->assertSame(0, $uploads['status']);
        $this->assertContains('upload_avatar', $uploads['payload']['features']);
        $this->assertFileExists($this->project->root . '/app/specs/uploads/avatar.uploads.yaml');

        $compile = $this->runCommand($app, ['foundry', 'compile', 'graph', '--json']);
        $this->assertSame(0, $compile['status']);

        $inspectResource = $this->runCommand($app, ['foundry', 'inspect', 'resource', 'posts', '--json']);
        $this->assertSame(0, $inspectResource['status']);
        $this->assertSame('resource', $inspectResource['payload']['resource']['type']);

        $verifyResource = $this->runCommand($app, ['foundry', 'verify', 'resource', 'posts', '--json']);
        $this->assertSame(0, $verifyResource['status']);
        $this->assertTrue($verifyResource['payload']['ok']);

        $inspectSpecFormat = $this->runCommand($app, ['foundry', 'inspect', 'spec-format', 'resource_spec', '--json']);
        $this->assertSame(0, $inspectSpecFormat['status']);
        $this->assertSame('resource_spec', $inspectSpecFormat['payload']['spec_format']['name']);

        $codemod = $this->runCommand($app, ['foundry', 'codemod', 'run', 'phase1-spec-v1-normalize', '--dry-run', '--json']);
        $this->assertSame(0, $codemod['status']);
        $this->assertSame('phase1-spec-v1-normalize', $codemod['payload']['codemod']);
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
