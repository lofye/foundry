<?php

declare(strict_types=1);

namespace Foundry\Tests\Integration;

use Foundry\CLI\Application;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class CLIPlanFeatureCommandTest extends TestCase
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

    public function test_plan_feature_generates_next_execution_spec_file(): void
    {
        $this->runCommand(['foundry', 'context', 'init', 'event-bus', '--json']);
        $this->writeMeaningfulContext('event-bus');

        $result = $this->runCommand(['foundry', 'plan', 'feature', 'event-bus', '--json']);

        $this->assertSame(0, $result['status']);
        $this->assertSame([
            'feature',
            'status',
            'can_proceed',
            'requires_repair',
            'spec_id',
            'spec_path',
            'actions_taken',
            'issues',
            'required_actions',
        ], array_keys($result['payload']));
        $this->assertSame('planned', $result['payload']['status']);
        $this->assertSame('event-bus/001-add-contract-test-coverage', $result['payload']['spec_id']);
        $this->assertSame('docs/specs/event-bus/001-add-contract-test-coverage.md', $result['payload']['spec_path']);
        $this->assertSame(['generated execution spec'], $result['payload']['actions_taken']);
        $this->assertFileExists($this->project->root . '/docs/specs/event-bus/001-add-contract-test-coverage.md');

        $contents = (string) file_get_contents($this->project->root . '/docs/specs/event-bus/001-add-contract-test-coverage.md');
        $this->assertStringContainsString('# Execution Spec: event-bus/001-add-contract-test-coverage', $contents);
        $this->assertStringContainsString('## Feature', $contents);
        $this->assertStringContainsString('## Purpose', $contents);
        $this->assertStringContainsString('## Scope', $contents);
        $this->assertStringContainsString('## Constraints', $contents);
        $this->assertStringContainsString('## Requested Changes', $contents);
    }

    public function test_blocked_feature_returns_correct_result(): void
    {
        $result = $this->runCommand(['foundry', 'plan', 'feature', 'event-bus', '--json']);

        $this->assertSame(1, $result['status']);
        $this->assertSame('blocked', $result['payload']['status']);
        $this->assertFalse($result['payload']['can_proceed']);
        $this->assertTrue($result['payload']['requires_repair']);
        $this->assertNull($result['payload']['spec_id']);
        $this->assertContains('Create missing spec file: docs/features/event-bus.spec.md', $result['payload']['required_actions']);
    }

    public function test_generated_spec_is_executable_via_implement_spec(): void
    {
        $this->runCommand(['foundry', 'context', 'init', 'event-bus', '--json']);
        $this->writeMeaningfulContext('event-bus');

        $planned = $this->runCommand(['foundry', 'plan', 'feature', 'event-bus', '--json']);
        $implemented = $this->runCommand([
            'foundry',
            'implement',
            'spec',
            (string) $planned['payload']['spec_id'],
            '--json',
        ]);

        $this->assertSame(0, $implemented['status']);
        $this->assertSame('completed', $implemented['payload']['status']);
        $this->assertFileExists($this->project->root . '/app/features/event-bus/feature.yaml');
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

    private function writeMeaningfulContext(string $feature): void
    {
        file_put_contents($this->project->root . '/docs/features/' . $feature . '.spec.md', <<<MD
# Feature Spec: {$feature}

## Purpose

Introduce event bus handling.

## Goals

- Add deterministic event bus feature scaffolding.

## Non-Goals

- Do not add async delivery.

## Constraints

- Keep output deterministic.

## Expected Behavior

- Event bus feature scaffolding exists in the app.

## Acceptance Criteria

- Add contract test coverage for the event bus feature.

## Assumptions

- Initial implementation may be scaffold-first.
MD);

        file_put_contents($this->project->root . '/docs/features/' . $feature . '.md', <<<MD
# Feature: {$feature}

## Purpose

Introduce event bus handling.

## Current State

- Event bus feature implementation is pending.

## Open Questions

- None.

## Next Steps

- Add contract test coverage for the event bus feature.
MD);
    }
}
