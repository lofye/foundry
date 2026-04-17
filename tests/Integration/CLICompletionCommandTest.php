<?php

declare(strict_types=1);

namespace Foundry\Tests\Integration;

use Foundry\CLI\Application;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class CLICompletionCommandTest extends TestCase
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

    public function test_completion_bash_emits_deterministic_output(): void
    {
        $app = new Application();

        $first = $this->runCommandRaw($app, ['foundry', 'completion', 'bash']);
        $second = $this->runCommandRaw($app, ['foundry', 'completion', 'bash']);

        $this->assertSame(0, $first['status']);
        $this->assertSame($first['output'], $second['output']);
        $this->assertStringContainsString('_foundry_completion_bash', $first['output']);
        $this->assertStringContainsString('foundry completion bash --complete', $first['output']);
        $this->assertStringContainsString('complete -F _foundry_completion_bash foundry', $first['output']);
    }

    public function test_completion_zsh_emits_deterministic_output(): void
    {
        $app = new Application();

        $result = $this->runCommandRaw($app, ['foundry', 'completion', 'zsh']);

        $this->assertSame(0, $result['status']);
        $this->assertStringContainsString('#compdef foundry', $result['output']);
        $this->assertStringContainsString('_foundry_completion_zsh', $result['output']);
        $this->assertStringContainsString('foundry completion zsh --complete', $result['output']);
        $this->assertStringContainsString('compdef _foundry_completion_zsh foundry', $result['output']);
    }

    public function test_unsupported_shell_fails_clearly(): void
    {
        $result = $this->runCommand(['foundry', 'completion', 'fish', '--json']);

        $this->assertSame(1, $result['status']);
        $this->assertSame('CLI_COMPLETION_SHELL_UNSUPPORTED', $result['payload']['error']['code']);
    }

    public function test_static_completion_includes_expected_top_level_commands_and_subcommands(): void
    {
        $topLevel = $this->runCommand([
            'foundry',
            'completion',
            'bash',
            '--complete',
            '--index=1',
            '--current=',
            '--',
            'foundry',
            '--json',
        ]);

        $this->assertSame(0, $topLevel['status']);
        $this->assertContains('completion', $topLevel['payload']['candidates']);
        $this->assertContains('compile', $topLevel['payload']['candidates']);
        $this->assertContains('implement', $topLevel['payload']['candidates']);

        $subcommands = $this->runCommand([
            'foundry',
            'completion',
            'bash',
            '--complete',
            '--index=2',
            '--current=',
            '--',
            'foundry',
            'implement',
            '',
            '--json',
        ]);

        $this->assertSame(0, $subcommands['status']);
        $this->assertSame(['feature', 'spec'], $subcommands['payload']['candidates']);
    }

    public function test_dynamic_feature_completion_lists_feature_directories_deterministically(): void
    {
        mkdir($this->project->root . '/docs/specs/event-bus', 0777, true);
        mkdir($this->project->root . '/docs/specs/payments/drafts', 0777, true);
        mkdir($this->project->root . '/docs/specs/Bad.Feature', 0777, true);

        $result = $this->runCommand([
            'foundry',
            'completion',
            'bash',
            '--complete',
            '--index=3',
            '--current=',
            '--',
            'foundry',
            'implement',
            'spec',
            '',
            '--json',
        ]);

        $this->assertSame(0, $result['status']);
        $this->assertSame(['event-bus', 'payments'], $result['payload']['candidates']);
    }

    public function test_dynamic_spec_id_completion_lists_active_ids_only_and_excludes_drafts(): void
    {
        $this->writeExecutionSpec('event-bus', '001-first');
        $this->writeExecutionSpec('event-bus', '015.001-nested-work');
        $this->writeDraftExecutionSpec('event-bus', '002-draft-only');

        $result = $this->runCommand([
            'foundry',
            'completion',
            'bash',
            '--complete',
            '--index=4',
            '--current=',
            '--',
            'foundry',
            'implement',
            'spec',
            'event-bus',
            '--json',
        ]);

        $this->assertSame(0, $result['status']);
        $this->assertSame(['001', '015.001'], $result['payload']['candidates']);
    }

    /**
     * @param array<int,string> $argv
     * @return array{status:int,payload:array<string,mixed>}
     */
    private function runCommand(array $argv): array
    {
        ob_start();
        $status = (new Application())->run($argv);
        $output = ob_get_clean() ?: '';

        /** @var array<string,mixed> $payload */
        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        return ['status' => $status, 'payload' => $payload];
    }

    /**
     * @param array<int,string> $argv
     * @return array{status:int,output:string}
     */
    private function runCommandRaw(Application $app, array $argv): array
    {
        ob_start();
        $status = $app->run($argv);
        $output = (string) (ob_get_clean() ?: '');

        return ['status' => $status, 'output' => $output];
    }

    private function writeExecutionSpec(string $feature, string $name): void
    {
        $directory = $this->project->root . '/docs/specs/' . $feature;
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($directory . '/' . $name . '.md', <<<MD
# Execution Spec: {$name}

## Feature

- {$feature}

## Purpose

- Execute a bounded implementation step.

## Scope

- Add deterministic completion support.

## Constraints

- Keep execution deterministic.

## Requested Changes

- Add deterministic completion support.
MD);
    }

    private function writeDraftExecutionSpec(string $feature, string $name): void
    {
        $directory = $this->project->root . '/docs/specs/' . $feature . '/drafts';
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($directory . '/' . $name . '.md', <<<MD
# Execution Spec: {$name}

## Feature

- {$feature}

## Purpose

- Execute a bounded implementation step.

## Scope

- Add deterministic completion support.

## Constraints

- Keep execution deterministic.

## Requested Changes

- Add deterministic completion support.
MD);
    }
}
