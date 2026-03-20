<?php
declare(strict_types=1);

namespace Foundry\Tests\Integration;

use Foundry\CLI\Application;
use Foundry\Core\RuntimeFactory;
use Foundry\Http\RequestContext;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
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

    #[RunInSeparateProcess]
    public function test_new_command_scaffolds_standard_project_with_docs_and_bootable_routes(): void
    {
        $app = new Application();
        $target = $this->project->root . '/customer-portal';

        $result = $this->runCommand($app, ['foundry', 'new', $target, '--name=acme/customer-portal', '--json']);

        $this->assertSame(0, $result['status']);
        $this->assertSame($target, $result['payload']['project_root']);
        $this->assertSame('acme/customer-portal', $result['payload']['project_name']);
        $this->assertSame('standard', $result['payload']['starter_mode']);
        $this->assertContains('dashboard', $result['payload']['features']);
        $this->assertContains('GET /dashboard', $result['payload']['routes']);
        $this->assertContains('php vendor/bin/foundry compile graph --json', $result['payload']['next_steps']);
        $this->assertContains('php vendor/bin/foundry doctor --json', $result['payload']['next_steps']);
        $this->assertContains('php vendor/bin/phpunit -c phpunit.xml.dist', $result['payload']['next_steps']);

        $this->assertFileExists($target . '/AGENTS.md');
        $this->assertFileExists($target . '/README.md');
        $this->assertFileExists($target . '/composer.json');
        $this->assertFileExists($target . '/phpunit.xml.dist');
        $this->assertFileExists($target . '/tests/Smoke/AppBootTest.php');
        $this->assertFileExists($target . '/app/platform/public/index.php');
        $this->assertFileExists($target . '/app/platform/config/auth.php');
        $this->assertFileExists($target . '/app/definitions/inspect-ui/dev.inspect-ui.yaml');
        $this->assertFileExists($target . '/app/features/home/context.manifest.json');
        $this->assertFileExists($target . '/app/features/project_docs/feature.yaml');
        $this->assertFileExists($target . '/app/features/submit_feedback/feature.yaml');
        $this->assertFileExists($target . '/app/features/dashboard/feature.yaml');
        $this->assertFileExists($target . '/app/features/current_user/feature.yaml');
        $this->assertFileExists($target . '/app/generated/routes.php');
        $this->assertFileExists($target . '/app/.foundry/build/projections/feature_index.php');
        $this->assertFileExists($target . '/docs/generated/features.md');
        $this->assertFileExists($target . '/docs/generated/cli-reference.md');
        $this->assertFileExists($target . '/docs/inspect-ui/index.html');

        $readme = file_get_contents($target . '/README.md');
        $this->assertIsString($readme);
        $this->assertStringContainsString('This Foundry project was scaffolded in `Standard` mode.', $readme);
        $this->assertStringContainsString('x-user-id', $readme);
        $this->assertStringContainsString('php vendor/bin/foundry compile graph --json', $readme);

        $this->seedInstalledApp($target);

        $pipeline = $this->runCommand($app, ['foundry', 'inspect', 'pipeline', '--json'], $target);
        $this->assertSame(0, $pipeline['status']);

        $doctor = $this->runCommand($app, ['foundry', 'doctor', '--json'], $target);
        $this->assertSame(0, $doctor['status']);
        $this->assertArrayHasKey('checks', $doctor['payload']);

        $public = $this->bootRequest($target, 'GET', '/');
        $this->assertSame(200, $public['status']);
        $this->assertSame('standard', $public['body']['starter']);

        $protected = $this->bootRequest($target, 'GET', '/dashboard', ['x-user-id' => 'demo-user']);
        $this->assertSame(200, $protected['status']);
        $this->assertSame('demo-user', $protected['body']['user_id']);
    }

    #[RunInSeparateProcess]
    public function test_init_app_supports_minimal_starter_mode(): void
    {
        $app = new Application();
        $target = $this->project->root . '/starter-minimal';

        $result = $this->runCommand($app, ['foundry', 'init', 'app', $target, '--starter=minimal', '--json']);

        $this->assertSame(0, $result['status']);
        $this->assertSame('minimal', $result['payload']['starter_mode']);
        $this->assertContains('submit_feedback', $result['payload']['features']);
        $this->assertNotContains('dashboard', $result['payload']['features']);
        $this->assertFileExists($target . '/app/features/submit_feedback/feature.yaml');
        $this->assertFileDoesNotExist($target . '/app/features/dashboard/feature.yaml');

        $public = $this->bootRequest($target, 'GET', '/');
        $this->assertSame(200, $public['status']);
        $this->assertSame('minimal', $public['body']['starter']);
    }

    #[RunInSeparateProcess]
    public function test_new_command_supports_api_first_starter_mode(): void
    {
        $app = new Application();
        $target = $this->project->root . '/api-starter';

        $result = $this->runCommand($app, ['foundry', 'new', $target, '--starter=api-first', '--json']);

        $this->assertSame(0, $result['status']);
        $this->assertSame('api-first', $result['payload']['starter_mode']);
        $this->assertContains('api_overview', $result['payload']['features']);
        $this->assertContains('api_me', $result['payload']['features']);
        $this->assertContains('GET /api/me', $result['payload']['routes']);
        $this->assertFileExists($target . '/app/features/api_overview/feature.yaml');
        $this->assertFileExists($target . '/app/features/api_echo/feature.yaml');
        $this->assertFileExists($target . '/app/features/api_me/feature.yaml');
        $this->assertFileDoesNotExist($target . '/app/features/dashboard/feature.yaml');

        $public = $this->bootRequest($target, 'GET', '/');
        $this->assertSame(200, $public['status']);
        $this->assertSame('api-first', $public['body']['starter']);

        $protected = $this->bootRequest($target, 'GET', '/api/me', ['x-user-id' => 'api-user']);
        $this->assertSame(200, $protected['status']);
        $this->assertSame('api-user', $protected['body']['user_id']);
    }

    /**
     * @param array<int,string> $argv
     * @return array{status:int,payload:array<string,mixed>}
     */
    private function runCommand(Application $app, array $argv, ?string $cwd = null): array
    {
        $previous = getcwd() ?: '.';
        if ($cwd !== null) {
            chdir($cwd);
        }

        try {
            ob_start();
            $status = $app->run($argv);
            $output = ob_get_clean() ?: '';
        } finally {
            if ($cwd !== null) {
                chdir($previous);
            }
        }

        /** @var array<string,mixed> $payload */
        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        return ['status' => $status, 'payload' => $payload];
    }

    /**
     * @param array<string,string> $headers
     * @return array<string,mixed>
     */
    private function bootRequest(string $target, string $method, string $path, array $headers = []): array
    {
        $kernel = RuntimeFactory::httpKernel(new Paths($target));

        return $kernel->handle(new RequestContext($method, $path, $headers));
    }

    private function seedInstalledApp(string $target): void
    {
        @mkdir($target . '/vendor/bin', 0777, true);
        file_put_contents($target . '/vendor/autoload.php', "<?php\n");
        file_put_contents($target . '/vendor/bin/foundry', "#!/usr/bin/env php\n<?php\n");
    }
}
