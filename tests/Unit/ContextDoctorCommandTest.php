<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\CLI\CommandContext;
use Foundry\CLI\Commands\ContextDoctorCommand;
use Foundry\Support\FoundryError;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class ContextDoctorCommandTest extends TestCase
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

    public function test_run_requires_exactly_one_targeting_mode(): void
    {
        $command = new ContextDoctorCommand();

        try {
            $command->run(['context', 'doctor'], new CommandContext());
            self::fail('Expected missing target validation.');
        } catch (FoundryError $error) {
            $this->assertSame('CLI_CONTEXT_DOCTOR_TARGET_REQUIRED', $error->errorCode);
        }

        try {
            $command->run(['context', 'doctor', '--feature=demo', '--all'], new CommandContext());
            self::fail('Expected target conflict validation.');
        } catch (FoundryError $error) {
            $this->assertSame('CLI_CONTEXT_DOCTOR_TARGET_CONFLICT', $error->errorCode);
        }
    }

    public function test_run_renders_feature_status_in_human_mode(): void
    {
        $result = (new ContextDoctorCommand())->run(
            ['context', 'doctor', '--feature', 'event-bus'],
            new CommandContext(),
        );

        $this->assertSame(1, $result['status']);
        $this->assertNull($result['payload']);
        $this->assertStringContainsString('Context doctor: event-bus', (string) $result['message']);
        $this->assertStringContainsString('Files:', (string) $result['message']);
        $this->assertStringContainsString('Required actions:', (string) $result['message']);
    }

    public function test_run_can_return_feature_payload_in_json_mode(): void
    {
        $result = (new ContextDoctorCommand())->run(
            ['context', 'doctor', '--feature=event-bus'],
            new CommandContext(null, true),
        );

        $this->assertSame(1, $result['status']);
        $this->assertNull($result['message']);
        $this->assertSame('event-bus', $result['payload']['feature']);
        $this->assertArrayHasKey('files', $result['payload']);
        $this->assertArrayHasKey('required_actions', $result['payload']);
    }

    public function test_run_renders_all_mode_when_no_features_are_present(): void
    {
        $result = (new ContextDoctorCommand())->run(
            ['context', 'doctor', '--all'],
            new CommandContext(),
        );

        $this->assertSame(0, $result['status']);
        $this->assertStringContainsString('Context doctor: all', (string) $result['message']);
        $this->assertStringContainsString('Features:', (string) $result['message']);
        $this->assertStringContainsString('- none', (string) $result['message']);
        $this->assertStringContainsString('Required actions:', (string) $result['message']);
    }
}
