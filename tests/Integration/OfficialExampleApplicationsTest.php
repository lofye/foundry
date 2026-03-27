<?php
declare(strict_types=1);

namespace Foundry\Tests\Integration;

use Foundry\CLI\Application;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class OfficialExampleApplicationsTest extends TestCase
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

    public function test_hello_world_example_compiles_and_supports_architecture_commands(): void
    {
        $this->importExampleApp('hello-world');
        $app = new Application();

        $compile = $this->runCommand($app, ['foundry', 'compile', 'graph', '--json']);
        $this->assertSame(0, $compile['status']);

        $inspect = $this->runCommand($app, ['foundry', 'inspect', 'graph', '--command', 'GET /hello', '--json']);
        $this->assertSame(0, $inspect['status']);
        $this->assertSame('command', $inspect['payload']['view']);
        $this->assertSame('GET /hello', $inspect['payload']['command_filter']);
        $this->assertContains('say_hello', $inspect['payload']['summary']['features']);

        $doctor = $this->runCommand($app, ['foundry', 'doctor', '--feature=say_hello', '--json']);
        $this->assertSame(0, $doctor['status']);
        $this->assertTrue($doctor['payload']['ok']);

        $this->assertSame(0, $this->runCommand($app, ['foundry', 'verify', 'graph', '--json'])['status']);
        $this->assertSame(0, $this->runCommand($app, ['foundry', 'verify', 'pipeline', '--json'])['status']);
    }

    public function test_blog_api_example_compiles_and_supports_command_and_doctor_inspection(): void
    {
        $this->importExampleApp('blog-api');
        $app = new Application();

        $compile = $this->runCommand($app, ['foundry', 'compile', 'graph', '--json']);
        $this->assertSame(0, $compile['status']);

        $inspect = $this->runCommand($app, ['foundry', 'inspect', 'graph', '--command', 'GET /posts', '--json']);
        $this->assertSame(0, $inspect['status']);
        $this->assertSame('command', $inspect['payload']['view']);
        $this->assertSame('GET /posts', $inspect['payload']['command_filter']);
        $this->assertContains('list_posts', $inspect['payload']['summary']['features']);

        $doctor = $this->runCommand($app, ['foundry', 'doctor', '--feature=list_posts', '--json']);
        $this->assertSame(0, $doctor['status']);
        $this->assertTrue($doctor['payload']['ok']);

        $this->assertSame(0, $this->runCommand($app, ['foundry', 'verify', 'graph', '--json'])['status']);
        $this->assertSame(0, $this->runCommand($app, ['foundry', 'verify', 'contracts', '--json'])['status']);
    }

    public function test_workflow_events_example_compiles_and_supports_event_and_workflow_inspection(): void
    {
        $this->importExampleApp('workflow-events');
        $app = new Application();

        $compile = $this->runCommand($app, ['foundry', 'compile', 'graph', '--json']);
        $this->assertSame(0, $compile['status']);

        $event = $this->runCommand($app, ['foundry', 'inspect', 'graph', '--event', 'story.review_requested', '--json']);
        $this->assertSame(0, $event['status']);
        $this->assertSame('events', $event['payload']['view']);
        $this->assertSame('story.review_requested', $event['payload']['event_filter']);

        $workflow = $this->runCommand($app, ['foundry', 'graph', 'inspect', '--workflow=editorial', '--json']);
        $this->assertSame(0, $workflow['status']);
        $this->assertSame('workflows', $workflow['payload']['view']);
        $this->assertSame('editorial', $workflow['payload']['workflow_filter']);
        $this->assertContains('editorial', $workflow['payload']['summary']['workflows']);

        $doctor = $this->runCommand($app, ['foundry', 'doctor', '--feature=publish_story', '--json']);
        $this->assertSame(0, $doctor['status']);
        $this->assertTrue($doctor['payload']['ok']);

        $this->assertSame(0, $this->runCommand($app, ['foundry', 'verify', 'graph', '--json'])['status']);
        $this->assertSame(0, $this->runCommand($app, ['foundry', 'verify', 'workflows', '--json'])['status']);
        $this->assertSame(0, $this->runCommand($app, ['foundry', 'verify', 'pipeline', '--json'])['status']);
    }

    public function test_dashboard_example_compiles_and_supports_architecture_commands(): void
    {
        $this->importExampleApp('dashboard');
        $app = new Application();

        $compile = $this->runCommand($app, ['foundry', 'compile', 'graph', '--json']);
        $this->assertSame(0, $compile['status']);

        $inspect = $this->runCommand($app, ['foundry', 'inspect', 'graph', '--command', 'POST /login', '--json']);
        $this->assertSame(0, $inspect['status']);
        $this->assertSame('command', $inspect['payload']['view']);
        $this->assertSame('POST /login', $inspect['payload']['command_filter']);
        $this->assertContains('login', $inspect['payload']['summary']['features']);

        $doctor = $this->runCommand($app, ['foundry', 'doctor', '--feature=login', '--json']);
        $this->assertSame(0, $doctor['status']);
        $this->assertTrue($doctor['payload']['ok']);

        $this->assertSame(0, $this->runCommand($app, ['foundry', 'verify', 'graph', '--json'])['status']);
        $this->assertSame(0, $this->runCommand($app, ['foundry', 'verify', 'pipeline', '--json'])['status']);
    }

    public function test_ai_pipeline_example_compiles_and_supports_architecture_commands(): void
    {
        $this->importExampleApp('ai-pipeline');
        $app = new Application();

        $compile = $this->runCommand($app, ['foundry', 'compile', 'graph', '--json']);
        $this->assertSame(0, $compile['status']);

        $inspect = $this->runCommand($app, ['foundry', 'inspect', 'graph', '--feature', 'submit_document', '--json']);
        $this->assertSame(0, $inspect['status']);
        $this->assertSame('dependencies', $inspect['payload']['view']);
        $this->assertSame('submit_document', $inspect['payload']['feature_filter']);
        $this->assertContains('submit_document', $inspect['payload']['summary']['features']);

        $doctor = $this->runCommand($app, ['foundry', 'doctor', '--feature=classify_document', '--json']);
        $this->assertSame(0, $doctor['status']);
        $this->assertTrue($doctor['payload']['ok']);

        $this->assertSame(0, $this->runCommand($app, ['foundry', 'verify', 'graph', '--json'])['status']);
        $this->assertSame(0, $this->runCommand($app, ['foundry', 'verify', 'pipeline', '--json'])['status']);
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

    private function importExampleApp(string $slug): void
    {
        $source = dirname(__DIR__, 2) . '/examples/' . $slug . '/app';
        $this->copyDirectory($source, $this->project->root . '/app');
    }

    private function copyDirectory(string $source, string $target): void
    {
        if (!is_dir($source)) {
            self::fail('Missing example app source directory: ' . $source);
        }

        if (!is_dir($target)) {
            mkdir($target, 0777, true);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        /** @var \SplFileInfo $item */
        foreach ($iterator as $item) {
            $relativePath = substr($item->getPathname(), strlen($source) + 1);
            $destination = $target . '/' . $relativePath;

            if ($item->isDir()) {
                if (!is_dir($destination)) {
                    mkdir($destination, 0777, true);
                }

                continue;
            }

            $directory = dirname($destination);
            if (!is_dir($directory)) {
                mkdir($directory, 0777, true);
            }

            copy($item->getPathname(), $destination);
        }
    }
}
