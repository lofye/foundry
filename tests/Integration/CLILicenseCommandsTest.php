<?php

declare(strict_types=1);

namespace Foundry\Tests\Integration;

use Foundry\CLI\Application;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class CLILicenseCommandsTest extends TestCase
{
    private TempProject $project;
    private string $cwd;
    private ?string $previousFoundryHome = null;
    private ?string $previousLicensePath = null;
    private ?string $previousLicenseKey = null;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->cwd = getcwd() ?: '.';
        chdir($this->project->root);

        $this->previousFoundryHome = getenv('FOUNDRY_HOME') !== false ? (string) getenv('FOUNDRY_HOME') : null;
        $this->previousLicensePath = getenv('FOUNDRY_LICENSE_PATH') !== false ? (string) getenv('FOUNDRY_LICENSE_PATH') : null;
        $this->previousLicenseKey = getenv('FOUNDRY_LICENSE_KEY') !== false ? (string) getenv('FOUNDRY_LICENSE_KEY') : null;

        putenv('FOUNDRY_HOME=' . $this->project->root . '/.foundry-home');
        putenv('FOUNDRY_LICENSE_PATH');
        putenv('FOUNDRY_LICENSE_KEY');
        mkdir($this->project->root . '/.foundry-home', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->restoreEnv('FOUNDRY_HOME', $this->previousFoundryHome);
        $this->restoreEnv('FOUNDRY_LICENSE_PATH', $this->previousLicensePath);
        $this->restoreEnv('FOUNDRY_LICENSE_KEY', $this->previousLicenseKey);
        chdir($this->cwd);
        $this->project->cleanup();
    }

    public function test_license_commands_manage_local_state_and_help_surface(): void
    {
        $app = new Application();

        $status = $this->runCommand($app, ['foundry', 'license:status', '--json']);
        $this->assertSame(0, $status['status']);
        $this->assertFalse($status['payload']['license']['valid']);
        $this->assertSame('none', $status['payload']['license']['source']);

        $help = $this->runCommand($app, ['foundry', 'help', 'license', '--json']);
        $this->assertSame(0, $help['status']);
        $this->assertSame('license', $help['payload']['group']['name']);
        $licenseStatus = array_find(
            $help['payload']['group']['commands']['experimental'],
            static fn(array $row): bool => (string) ($row['signature'] ?? '') === 'license:status',
        );
        $this->assertIsArray($licenseStatus);
        $this->assertSame('Pro', $licenseStatus['category']);

        $activate = $this->runCommand($app, ['foundry', 'license:activate', $this->validKey(), '--json']);
        $this->assertSame(0, $activate['status']);
        $this->assertTrue($activate['payload']['license']['valid']);
        $this->assertSame('file', $activate['payload']['license']['source']);
        $this->assertSame('foundry license:status', $activate['payload']['license']['upgrade']['status_command']);

        $legacy = $this->runCommand($app, ['foundry', 'pro', 'status', '--json']);
        $this->assertSame(0, $legacy['status']);
        $this->assertTrue($legacy['payload']['license']['valid']);
        $this->assertContains('license:deactivate', $legacy['payload']['license_commands']);

        $deactivate = $this->runCommand($app, ['foundry', 'license:deactivate', '--json']);
        $this->assertSame(0, $deactivate['status']);
        $this->assertTrue($deactivate['payload']['license']['deactivated']);
        $this->assertFalse($deactivate['payload']['license']['valid']);
        $this->assertSame('none', $deactivate['payload']['license']['source']);
    }

    public function test_license_status_prefers_environment_license_key(): void
    {
        $app = new Application();
        putenv('FOUNDRY_LICENSE_KEY=' . $this->validKey());

        $status = $this->runCommand($app, ['foundry', 'license:status', '--json']);
        $this->assertSame(0, $status['status']);
        $this->assertTrue($status['payload']['license']['valid']);
        $this->assertSame('environment', $status['payload']['license']['source']);

        $deactivate = $this->runCommand($app, ['foundry', 'license:deactivate', '--json']);
        $this->assertSame(0, $deactivate['status']);
        $this->assertTrue($deactivate['payload']['license']['valid']);
        $this->assertSame('environment', $deactivate['payload']['license']['source']);
        $this->assertStringContainsString('Environment-based licensing remains active', (string) $deactivate['payload']['license']['message']);
    }

    private function validKey(): string
    {
        $body = 'FPRO-ABCD-EFGH-IJKL-MNOP';

        return $body . '-' . strtoupper(substr(hash('sha256', 'foundry-pro:' . $body), 0, 8));
    }

    private function restoreEnv(string $name, ?string $value): void
    {
        if ($value === null) {
            putenv($name);

            return;
        }

        putenv($name . '=' . $value);
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
