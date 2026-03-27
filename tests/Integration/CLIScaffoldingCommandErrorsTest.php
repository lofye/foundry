<?php
declare(strict_types=1);

namespace Foundry\Tests\Integration;

use Foundry\CLI\Application;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class CLIScaffoldingCommandErrorsTest extends TestCase
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

    public function test_scaffolding_commands_return_structured_errors_for_invalid_arguments(): void
    {
        $app = new Application();

        $newMissingPath = $this->runCommand($app, ['foundry', 'new', '--json']);
        $this->assertSame(1, $newMissingPath['status']);
        $this->assertSame('CLI_INIT_APP_TARGET_NOT_EMPTY', $newMissingPath['payload']['error']['code']);

        $invalidStarter = $this->runCommand($app, ['foundry', 'new', 'demo-app', '--starter=desktop', '--json']);
        $this->assertSame(1, $invalidStarter['status']);
        $this->assertSame('CLI_INIT_APP_STARTER_INVALID', $invalidStarter['payload']['error']['code']);

        $starterMissing = $this->runCommand($app, ['foundry', 'generate', 'starter', '--json']);
        $this->assertSame(1, $starterMissing['status']);
        $this->assertSame('CLI_STARTER_REQUIRED', $starterMissing['payload']['error']['code']);

        $starterInvalid = $this->runCommand($app, ['foundry', 'generate', 'starter', 'desktop', '--json']);
        $this->assertSame(1, $starterInvalid['status']);
        $this->assertSame('STARTER_INVALID', $starterInvalid['payload']['error']['code']);

        $resourceMissingDefinition = $this->runCommand($app, ['foundry', 'generate', 'resource', 'posts', '--json']);
        $this->assertSame(1, $resourceMissingDefinition['status']);
        $this->assertSame('CLI_RESOURCE_DEFINITION_REQUIRED', $resourceMissingDefinition['payload']['error']['code']);

        $adminMissingName = $this->runCommand($app, ['foundry', 'generate', 'admin-resource', '--json']);
        $this->assertSame(1, $adminMissingName['status']);
        $this->assertSame('CLI_ADMIN_RESOURCE_REQUIRED', $adminMissingName['payload']['error']['code']);

        $uploadsMissing = $this->runCommand($app, ['foundry', 'generate', 'uploads', '--json']);
        $this->assertSame(1, $uploadsMissing['status']);
        $this->assertSame('CLI_UPLOAD_PROFILE_REQUIRED', $uploadsMissing['payload']['error']['code']);

        $inspectMissing = $this->runCommand($app, ['foundry', 'inspect', 'resource', '--json']);
        $this->assertSame(1, $inspectMissing['status']);
        $this->assertSame('CLI_RESOURCE_REQUIRED', $inspectMissing['payload']['error']['code']);

        $verifyMissing = $this->runCommand($app, ['foundry', 'verify', 'resource', '--json']);
        $this->assertSame(1, $verifyMissing['status']);
        $this->assertSame('CLI_RESOURCE_REQUIRED', $verifyMissing['payload']['error']['code']);
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
