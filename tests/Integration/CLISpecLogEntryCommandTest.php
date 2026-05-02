<?php

declare(strict_types=1);

namespace Foundry\Tests\Integration;

use Foundry\CLI\Application;
use Foundry\CLI\Commands\SpecLogEntryCommand;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class CLISpecLogEntryCommandTest extends TestCase
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

    public function test_spec_log_entry_outputs_exact_canonical_entry_for_active_spec(): void
    {
        $this->writeExecutionSpec('execution-spec-system', '001-spec-auto-log-on-implementation', 'execution-spec-system');
        $app = $this->fixedClockApp('2026-04-17 12:30:45 -0400');

        $json = $this->runCommand($app, ['foundry', 'spec:log-entry', 'execution-spec-system', '001', '--json']);
        $raw = $this->runRawCommand($app, ['foundry', 'spec:log-entry', 'execution-spec-system', '001']);

        $this->assertSame(0, $json['status']);
        $this->assertSame([
            'spec_id' => 'execution-spec-system/001-spec-auto-log-on-implementation',
            'feature' => 'execution-spec-system',
            'spec_ref' => 'execution-spec-system/001-spec-auto-log-on-implementation.md',
            'spec_path' => 'docs/features/execution-spec-system/specs/001-spec-auto-log-on-implementation.md',
            'log_path' => 'docs/features/implementation-log.md',
            'timestamp' => '2026-04-17 12:30:45 -0400',
            'timestamp_heading' => '## 2026-04-17 12:30:45 -0400',
            'spec_log_line' => '- spec: execution-spec-system/001-spec-auto-log-on-implementation.md',
            'entry' => "## 2026-04-17 12:30:45 -0400\n- spec: execution-spec-system/001-spec-auto-log-on-implementation.md\n",
        ], $json['payload']);

        $this->assertSame(0, $raw['status']);
        $this->assertSame(
            "## 2026-04-17 12:30:45 -0400\n- spec: execution-spec-system/001-spec-auto-log-on-implementation.md\n",
            $raw['output'],
        );
    }

    public function test_spec_log_entry_repeated_runs_are_stable_with_fixed_timestamp(): void
    {
        $this->writeExecutionSpec('execution-spec-system', '001-spec-auto-log-on-implementation', 'execution-spec-system');
        $app = $this->fixedClockApp('2026-04-17 12:30:45 -0400');

        $first = $this->runCommand($app, ['foundry', 'spec:log-entry', 'execution-spec-system/001-spec-auto-log-on-implementation', '--json']);
        $second = $this->runCommand($app, ['foundry', 'spec:log-entry', 'execution-spec-system/001-spec-auto-log-on-implementation', '--json']);

        $this->assertSame($first, $second);
    }

    public function test_spec_log_entry_rejects_draft_only_matches_clearly(): void
    {
        $this->writeDraftExecutionSpec('execution-spec-system', '001-spec-auto-log-on-implementation', 'execution-spec-system');
        $result = $this->runCommand($this->fixedClockApp('2026-04-17 12:30:45 -0400'), ['foundry', 'spec:log-entry', 'execution-spec-system', '001', '--json']);

        $this->assertSame(1, $result['status']);
        $this->assertSame('EXECUTION_SPEC_DRAFT_ONLY', $result['payload']['error']['code']);
        $this->assertSame(
            'Draft execution specs do not require implementation-log coverage. Promote the spec only if it later becomes active and implemented.',
            $result['payload']['error']['message'],
        );
    }

    public function test_spec_log_entry_rejects_unknown_active_ids_clearly(): void
    {
        $this->writeExecutionSpec('execution-spec-system', '001-spec-auto-log-on-implementation', 'execution-spec-system');
        $result = $this->runCommand($this->fixedClockApp('2026-04-17 12:30:45 -0400'), ['foundry', 'spec:log-entry', 'execution-spec-system', '002', '--json']);

        $this->assertSame(1, $result['status']);
        $this->assertSame('EXECUTION_SPEC_NOT_FOUND', $result['payload']['error']['code']);
    }

    public function test_spec_log_entry_rejects_malformed_ids_clearly(): void
    {
        $this->writeExecutionSpec('execution-spec-system', '001-spec-auto-log-on-implementation', 'execution-spec-system');
        $result = $this->runCommand($this->fixedClockApp('2026-04-17 12:30:45 -0400'), ['foundry', 'spec:log-entry', 'execution-spec-system', '4', '--json']);

        $this->assertSame(1, $result['status']);
        $this->assertSame('EXECUTION_SPEC_ID_INVALID', $result['payload']['error']['code']);
    }

    public function test_spec_log_entry_refuses_when_feature_has_skipped_ids(): void
    {
        $this->writeExecutionSpec('execution-spec-system', '001-first', 'execution-spec-system');
        $this->writeExecutionSpec('execution-spec-system', '003-third', 'execution-spec-system');

        $result = $this->runCommand($this->fixedClockApp('2026-04-17 12:30:45 -0400'), ['foundry', 'spec:log-entry', 'execution-spec-system', '001', '--json']);

        $this->assertSame(1, $result['status']);
        $this->assertSame('EXECUTION_SPEC_ID_SEQUENCE_INVALID', $result['payload']['error']['code']);
        $this->assertStringContainsString('Skipping numbers violates execution-spec-system rules', $result['payload']['error']['message']);
    }

    public function test_spec_log_entry_requires_target_argument(): void
    {
        $result = $this->runCommand($this->fixedClockApp('2026-04-17 12:30:45 -0400'), ['foundry', 'spec:log-entry', '--json']);

        $this->assertSame(1, $result['status']);
        $this->assertSame('CLI_SPEC_LOG_ENTRY_TARGET_REQUIRED', $result['payload']['error']['code']);
    }

    public function test_spec_log_entry_rejects_too_many_arguments(): void
    {
        $result = $this->runCommand(
            $this->fixedClockApp('2026-04-17 12:30:45 -0400'),
            ['foundry', 'spec:log-entry', 'execution-spec-system', '001', 'extra', '--json'],
        );

        $this->assertSame(1, $result['status']);
        $this->assertSame('CLI_SPEC_LOG_ENTRY_ARGUMENTS_INVALID', $result['payload']['error']['code']);
    }

    public function test_spec_log_entry_rejects_feature_without_id_in_single_argument_form(): void
    {
        $this->writeExecutionSpec('execution-spec-system', '001-first', 'execution-spec-system');
        $result = $this->runCommand($this->fixedClockApp('2026-04-17 12:30:45 -0400'), ['foundry', 'spec:log-entry', 'execution-spec-system', '--json']);

        $this->assertSame(1, $result['status']);
        $this->assertSame('CLI_SPEC_LOG_ENTRY_ID_REQUIRED', $result['payload']['error']['code']);
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

    /**
     * @param array<int,string> $argv
     * @return array{status:int,output:string}
     */
    private function runRawCommand(Application $app, array $argv): array
    {
        ob_start();
        $status = $app->run($argv);
        $output = ob_get_clean() ?: '';

        return ['status' => $status, 'output' => $output];
    }

    private function fixedClockApp(string $timestamp): Application
    {
        $commands = [new SpecLogEntryCommand(
            static fn(): \DateTimeImmutable => new \DateTimeImmutable($timestamp),
        )];

        foreach (Application::registeredCommands() as $command) {
            if ($command instanceof SpecLogEntryCommand) {
                continue;
            }

            $commands[] = $command;
        }

        return new Application($commands);
    }

    private function writeExecutionSpec(string $feature, string $name, string $declaredFeature): void
    {
        $this->writeRawFile(
            'docs/features/' . $feature . '/specs/' . $name . '.md',
            <<<MD
# Execution Spec: {$name}

## Feature

- {$declaredFeature}

## Purpose

- Emit deterministic implementation-log guidance.

## Scope

- Resolve one active execution spec.

## Constraints

- Keep output deterministic.

## Requested Changes

- Emit exact canonical implementation-log entry content.
MD
            . "\n",
        );
    }

    private function writeDraftExecutionSpec(string $feature, string $name, string $declaredFeature): void
    {
        $this->writeRawFile(
            'docs/features/' . $feature . '/specs/drafts/' . $name . '.md',
            <<<MD
# Execution Spec: {$name}

## Feature

- {$declaredFeature}

## Purpose

- Emit deterministic implementation-log guidance.

## Scope

- Resolve one draft execution spec.

## Constraints

- Keep output deterministic.

## Requested Changes

- Emit exact canonical implementation-log entry content.
MD
            . "\n",
        );
    }

    private function writeRawFile(string $relativePath, string $contents): void
    {
        $absolutePath = $this->project->root . '/' . $relativePath;
        $directory = dirname($absolutePath);

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($absolutePath, $contents);
    }
}
