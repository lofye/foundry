<?php
declare(strict_types=1);

namespace Foundry\Tests\Integration;

use Foundry\CLI\Application;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class CLIIntegrationCommandErrorsTest extends TestCase
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

    public function test_integration_commands_return_structured_errors_for_invalid_arguments(): void
    {
        $app = new Application();

        $notificationMissing = $this->runCommand($app, ['foundry', 'generate', 'notification', '--json']);
        $this->assertSame(1, $notificationMissing['status']);
        $this->assertSame('CLI_NOTIFICATION_REQUIRED', $notificationMissing['payload']['error']['code']);

        $apiResourceMissingSpec = $this->runCommand($app, ['foundry', 'generate', 'api-resource', 'posts', '--json']);
        $this->assertSame(1, $apiResourceMissingSpec['status']);
        $this->assertSame('CLI_API_RESOURCE_SPEC_REQUIRED', $apiResourceMissingSpec['payload']['error']['code']);

        $docsInvalidFormat = $this->runCommand($app, ['foundry', 'generate', 'docs', '--format=txt', '--json']);
        $this->assertSame(1, $docsInvalidFormat['status']);
        $this->assertSame('CLI_DOCS_FORMAT_INVALID', $docsInvalidFormat['payload']['error']['code']);

        $previewMissing = $this->runCommand($app, ['foundry', 'preview', 'notification', '--json']);
        $this->assertSame(1, $previewMissing['status']);
        $this->assertSame('CLI_NOTIFICATION_REQUIRED', $previewMissing['payload']['error']['code']);

        $inspectApiMissing = $this->runCommand($app, ['foundry', 'inspect', 'api', '--json']);
        $this->assertSame(1, $inspectApiMissing['status']);
        $this->assertSame('CLI_API_RESOURCE_REQUIRED', $inspectApiMissing['payload']['error']['code']);

        $inspectNotificationMissing = $this->runCommand($app, ['foundry', 'inspect', 'notification', '--json']);
        $this->assertSame(1, $inspectNotificationMissing['status']);
        $this->assertSame('CLI_NOTIFICATION_REQUIRED', $inspectNotificationMissing['payload']['error']['code']);

        $openApiInvalidFormat = $this->runCommand($app, ['foundry', 'export', 'openapi', '--format=xml', '--json']);
        $this->assertSame(1, $openApiInvalidFormat['status']);
        $this->assertSame('CLI_OPENAPI_FORMAT_INVALID', $openApiInvalidFormat['payload']['error']['code']);
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
