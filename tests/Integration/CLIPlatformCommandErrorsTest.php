<?php
declare(strict_types=1);

namespace Foundry\Tests\Integration;

use Foundry\CLI\Application;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class CLIPlatformCommandErrorsTest extends TestCase
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

    public function test_platform_commands_return_structured_errors_for_invalid_arguments(): void
    {
        $app = new Application();

        $billingMissingProvider = $this->runCommand($app, ['foundry', 'generate', 'billing', '--json']);
        $this->assertSame(1, $billingMissingProvider['status']);
        $this->assertSame('CLI_BILLING_PROVIDER_REQUIRED', $billingMissingProvider['payload']['error']['code']);

        $workflowMissingSpec = $this->runCommand($app, ['foundry', 'generate', 'workflow', 'posts', '--json']);
        $this->assertSame(1, $workflowMissingSpec['status']);
        $this->assertSame('CLI_WORKFLOW_SPEC_REQUIRED', $workflowMissingSpec['payload']['error']['code']);

        $orchestrationMissingName = $this->runCommand($app, ['foundry', 'generate', 'orchestration', '--json']);
        $this->assertSame(1, $orchestrationMissingName['status']);
        $this->assertSame('CLI_ORCHESTRATION_REQUIRED', $orchestrationMissingName['payload']['error']['code']);

        $searchMissingSpec = $this->runCommand($app, ['foundry', 'generate', 'search-index', 'posts', '--json']);
        $this->assertSame(1, $searchMissingSpec['status']);
        $this->assertSame('CLI_SEARCH_SPEC_REQUIRED', $searchMissingSpec['payload']['error']['code']);

        $streamMissingName = $this->runCommand($app, ['foundry', 'generate', 'stream', '--json']);
        $this->assertSame(1, $streamMissingName['status']);
        $this->assertSame('CLI_STREAM_REQUIRED', $streamMissingName['payload']['error']['code']);

        $localeInvalid = $this->runCommand($app, ['foundry', 'generate', 'locale', 'english', '--json']);
        $this->assertSame(1, $localeInvalid['status']);
        $this->assertSame('LOCALE_INVALID', $localeInvalid['payload']['error']['code']);

        $policyMissing = $this->runCommand($app, ['foundry', 'generate', 'policy', '--json']);
        $this->assertSame(1, $policyMissing['status']);
        $this->assertSame('CLI_POLICY_REQUIRED', $policyMissing['payload']['error']['code']);

        $inspectWorkflowMissingName = $this->runCommand($app, ['foundry', 'inspect', 'workflow', '--json']);
        $this->assertSame(1, $inspectWorkflowMissingName['status']);
        $this->assertSame('CLI_INSPECT_NAME_REQUIRED', $inspectWorkflowMissingName['payload']['error']['code']);

        $inspectUnknownNode = $this->runCommand($app, ['foundry', 'inspect', 'billing', '--provider=missing', '--json']);
        $this->assertSame(1, $inspectUnknownNode['status']);
        $this->assertSame('INSPECT_NODE_NOT_FOUND', $inspectUnknownNode['payload']['error']['code']);
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
