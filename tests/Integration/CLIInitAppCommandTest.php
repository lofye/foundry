<?php
declare(strict_types=1);

namespace Foundry\Tests\Integration;

use Foundry\CLI\Application;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class CLIInitAppCommandTest extends TestCase
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

    public function test_init_app_scaffolds_upgrade_friendly_project(): void
    {
        $app = new Application();
        $target = $this->project->root . '/marketing-site';
        $result = $this->runCommand($app, ['foundry', 'init', 'app', $target, '--name=acme/marketing-site', '--json']);

        $this->assertSame(0, $result['status']);
        $this->assertSame($target, $result['payload']['project_root']);
        $this->assertSame('acme/marketing-site', $result['payload']['project_name']);
        $this->assertFileExists($target . '/composer.json');
        $this->assertFileExists($target . '/app/platform/public/index.php');
        $this->assertFileExists($target . '/app/features/home/feature.yaml');
        $this->assertFileExists($target . '/app/features/home/context.manifest.json');
        $this->assertFileExists($target . '/app/generated/routes.php');

        $composer = file_get_contents($target . '/composer.json');
        $this->assertIsString($composer);
        $this->assertStringContainsString('"foundry/framework": "dev-main"', $composer);
        $this->assertStringContainsString('"type": "path"', $composer);

        /** @var array<string,mixed> $routes */
        $routes = require $target . '/app/generated/routes.php';
        $this->assertArrayHasKey('GET /', $routes);
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
