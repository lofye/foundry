<?php
declare(strict_types=1);

namespace Foundry\Tests\Integration;

use Foundry\CLI\Application;
use Foundry\Core\RuntimeFactory;
use Foundry\Http\RequestContext;
use Foundry\Support\Paths;
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
        $this->assertContains('foundry compile graph --json', $result['payload']['next_steps']);
        $this->assertContains('foundry doctor --json', $result['payload']['next_steps']);
        $this->assertContains('php vendor/bin/phpunit -c phpunit.xml.dist', $result['payload']['next_steps']);

        $this->assertFileExists($target . '/AGENTS.md');
        $this->assertFileExists($target . '/README.md');
        $this->assertFileExists($target . '/composer.json');
        $this->assertFileExists($target . '/foundry');
        $this->assertFileExists($target . '/foundry.bat');
        $this->assertFileExists($target . '/phpunit.xml.dist');
        $this->assertFileExists($target . '/tests/Smoke/AppBootTest.php');
        $this->assertFileExists($target . '/bootstrap/app.php');
        $this->assertFileExists($target . '/bootstrap/providers.php');
        $this->assertFileExists($target . '/public/index.php');
        $this->assertFileExists($target . '/config/app.php');
        $this->assertFileExists($target . '/config/auth.php');
        $this->assertFileExists($target . '/config/database.php');
        $this->assertFileExists($target . '/config/foundry/extensions.php');
        $this->assertFileExists($target . '/database/migrations/.gitignore');
        $this->assertFileExists($target . '/lang/en/messages.php');
        $this->assertFileExists($target . '/storage/files/.gitignore');
        $this->assertFileExists($target . '/storage/logs/.gitignore');
        $this->assertFileExists($target . '/storage/tmp/.gitignore');
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
        $this->assertStringContainsString('foundry compile graph --json', $readme);
        $this->assertStringContainsString('Foundry scaffolds a project-local `foundry` launcher.', $readme);

        $agents = file_get_contents($target . '/AGENTS.md');
        $this->assertIsString($agents);
        $this->assertStringContainsString('prefer `foundry ...`', $agents);
        $this->assertStringContainsString('use `./foundry ...`', $agents);

        $docsReadme = file_get_contents($target . '/docs/README.md');
        $this->assertIsString($docsReadme);
        $this->assertStringContainsString('foundry generate docs --format=markdown --json', $docsReadme);

        /** @var array<string,mixed> $composer */
        $composer = json_decode((string) file_get_contents($target . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('lofye/foundry-framework', array_key_first(array_filter(
            $composer['require'],
            static fn (string $constraint, string $package): bool => $package === 'lofye/foundry-framework',
            ARRAY_FILTER_USE_BOTH,
        )));
        $this->assertSame('@php foundry compile graph --json', $composer['scripts']['foundry:compile']);
        $this->assertSame('@php foundry doctor --json', $composer['scripts']['foundry:doctor']);
        $this->assertSame('php -S 127.0.0.1:8000 public/index.php', $composer['scripts']['serve']);

        if (DIRECTORY_SEPARATOR !== '\\') {
            $permissions = fileperms($target . '/foundry');
            $this->assertNotFalse($permissions);
            $this->assertNotSame(0, $permissions & 0111);
        }

        $this->seedInstalledApp($target);

        $pipeline = $this->runCommand($app, ['foundry', 'inspect', 'pipeline', '--json'], $target);
        $this->assertSame(0, $pipeline['status']);

        $doctor = $this->runCommand($app, ['foundry', 'doctor', '--json'], $target);
        $this->assertSame(0, $doctor['status']);
        $this->assertArrayHasKey('checks', $doctor['payload']);
        $this->assertSame('foundry', $doctor['payload']['command_prefix']);

        $public = $this->bootRequest($target, 'GET', '/');
        $this->assertSame(200, $public['status']);
        $this->assertSame('standard', $public['body']['starter']);

        $protected = $this->bootRequest($target, 'GET', '/dashboard', ['x-user-id' => 'demo-user']);
        $this->assertSame(200, $protected['status']);
        $this->assertSame('demo-user', $protected['body']['user_id']);
    }

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

        $this->seedInstalledApp($target);

        $compile = $this->runCommand($app, ['foundry', 'compile', 'graph', '--json'], $target);
        $this->assertSame(0, $compile['status']);

        $inspect = $this->runCommand($app, ['foundry', 'inspect', 'graph', '--json'], $target);
        $this->assertSame(0, $inspect['status']);
        $this->assertContains('submit_feedback', $inspect['payload']['summary']['features']);
    }

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

        $this->seedInstalledApp($target);

        $compile = $this->runCommand($app, ['foundry', 'compile', 'graph', '--json'], $target);
        $this->assertSame(0, $compile['status']);

        $inspect = $this->runCommand($app, ['foundry', 'inspect', 'graph', '--command', 'GET /api/me', '--json'], $target);
        $this->assertSame(0, $inspect['status']);
        $this->assertSame('GET /api/me', $inspect['payload']['command_filter']);
        $this->assertContains('api_me', $inspect['payload']['summary']['features']);
    }

    public function test_new_command_defaults_to_current_directory_when_path_is_omitted(): void
    {
        $app = new Application();
        $target = $this->project->root . '/current-directory-app';
        mkdir($target, 0777, true);

        $result = $this->runCommand($app, ['foundry', 'new', '--starter=standard', '--json'], $target);
        $resolvedTarget = realpath($target);
        $this->assertIsString($resolvedTarget);

        $this->assertSame(0, $result['status']);
        $this->assertSame($resolvedTarget, $result['payload']['project_root']);
        $this->assertSame('lofye/foundry-framework', $result['payload']['framework_package']);
        $this->assertNotContains('cd ' . $resolvedTarget, $result['payload']['next_steps']);
        $this->assertFileExists($target . '/public/index.php');
        $this->assertFileExists($target . '/config/database.php');
        $this->assertFileExists($target . '/database/migrations/.gitignore');
        $this->assertFileExists($target . '/storage/logs/.gitignore');
    }

    public function test_new_command_merges_existing_composer_bootstrap_and_clears_stale_lockfile(): void
    {
        $app = new Application();
        $target = $this->project->root . '/composer-first-app';
        mkdir($target, 0777, true);
        mkdir($target . '/vendor', 0777, true);

        file_put_contents($target . '/composer.json', <<<'JSON'
{
  "require": {
    "php": "^8.4",
    "lofye/foundry-framework": "^0.9"
  },
  "autoload": {
    "psr-4": {
      "Acme\\": "src/"
    }
  },
  "scripts": {
    "custom": "@php artisan custom"
  }
}
JSON);
        file_put_contents($target . '/composer.lock', "{}\n");

        $result = $this->runCommand($app, ['foundry', 'new', '--starter=minimal', '--json'], $target);
        $resolvedTarget = realpath($target);
        $this->assertIsString($resolvedTarget);

        $this->assertSame(0, $result['status']);
        $this->assertSame($resolvedTarget, $result['payload']['project_root']);
        $this->assertSame('^0.9', $result['payload']['framework_version']);
        $this->assertFileDoesNotExist($target . '/composer.lock');

        /** @var array<string,mixed> $composer */
        $composer = json_decode((string) file_get_contents($target . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('^0.9', $composer['require']['lofye/foundry-framework']);
        $this->assertArrayNotHasKey('lofye/foundry', $composer['require']);
        $this->assertSame('app/', $composer['autoload']['psr-4']['App\\']);
        $this->assertSame('src/', $composer['autoload']['psr-4']['Acme\\']);
        $this->assertSame('@php artisan custom', $composer['scripts']['custom']);
        $this->assertSame('php -S 127.0.0.1:8000 public/index.php', $composer['scripts']['serve']);
        $this->assertNotContains('cd ' . $resolvedTarget, $result['payload']['next_steps']);
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
