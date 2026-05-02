<?php

declare(strict_types=1);

namespace Foundry\Tests\Integration;

use Foundry\CLI\Application;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class CLIImplementFeatureCommandTest extends TestCase
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

    public function test_implement_feature_succeeds_with_valid_context(): void
    {
        $this->runCommand(['foundry', 'context', 'init', 'event-bus', '--json']);
        $this->writeMeaningfulContext('event-bus');

        $result = $this->runCommand(['foundry', 'implement', 'feature', 'event-bus', '--json']);
        $verify = $this->runCommand(['foundry', 'verify', 'context', '--feature=event-bus', '--json']);

        $this->assertSame(0, $result['status']);
        $this->assertSame([
            'feature',
            'status',
            'can_proceed',
            'requires_repair',
            'repair_attempted',
            'repair_successful',
            'actions_taken',
            'issues',
            'required_actions',
            'quality_gate',
        ], array_keys($result['payload']));
        $this->assertSame('completed', $result['payload']['status']);
        $this->assertTrue($result['payload']['can_proceed']);
        $this->assertFalse($result['payload']['requires_repair']);
        $this->assertTrue($result['payload']['quality_gate']['passed']);
        $this->assertSame('passed', $result['payload']['quality_gate']['changed_surface']['status']);
        $this->assertFileExists($this->project->root . '/app/features/event-bus/feature.yaml');
        $this->assertSame(0, $verify['status']);
        $this->assertSame('pass', $verify['payload']['status']);
    }

    public function test_implement_feature_normalizes_underscore_cli_input_to_canonical_directory(): void
    {
        $this->runCommand(['foundry', 'context', 'init', 'event_bus', '--json']);
        $this->writeMeaningfulContext('event-bus');

        $result = $this->runCommand(['foundry', 'implement', 'feature', 'event_bus', '--json']);

        $this->assertSame(0, $result['status']);
        $this->assertSame('event-bus', $result['payload']['feature']);
        $this->assertFileExists($this->project->root . '/app/features/event-bus/feature.yaml');
        $this->assertFileExists($this->project->root . '/app/features/event-bus/tests/event_bus_contract_test.php');
        $this->assertFileDoesNotExist($this->project->root . '/app/features/event_bus/feature.yaml');
    }

    public function test_blocked_feature_returns_correct_blocked_result(): void
    {
        $result = $this->runCommand(['foundry', 'implement', 'feature', 'event-bus', '--json']);

        $this->assertSame(1, $result['status']);
        $this->assertSame('blocked', $result['payload']['status']);
        $this->assertFalse($result['payload']['can_proceed']);
        $this->assertTrue($result['payload']['requires_repair']);
        $this->assertSame('context_not_consumable', $result['payload']['reason']);
        $this->assertSame(
            'Run `php bin/foundry verify context --json` and resolve all issues before proceeding.',
            $result['payload']['required_action'],
        );
        $this->assertContains('Create missing spec file: docs/features/event-bus/event-bus.spec.md', $result['payload']['required_actions']);
    }

    public function test_repair_enables_recovery_when_repairable(): void
    {
        $this->runCommand(['foundry', 'context', 'init', 'event-bus', '--json']);
        $this->writeMeaningfulContext('event-bus');
        unlink($this->project->root . '/docs/features/event-bus/event-bus.md');

        $result = $this->runCommand(['foundry', 'implement', 'feature', 'event-bus', '--repair', '--json']);

        $this->assertSame(0, $result['status']);
        $this->assertSame('repaired', $result['payload']['status']);
        $this->assertTrue($result['payload']['repair_attempted']);
        $this->assertTrue($result['payload']['repair_successful']);
    }

    public function test_auto_repair_enables_safe_recovery_when_repairable(): void
    {
        $this->runCommand(['foundry', 'context', 'init', 'event-bus', '--json']);
        $this->writeMeaningfulContext('event-bus');
        $path = $this->project->root . '/docs/features/event-bus/event-bus.spec.md';
        file_put_contents($path, str_replace('# Feature Spec: event-bus', '# Spec: event-bus', (string) file_get_contents($path)));

        $result = $this->runCommand(['foundry', 'implement', 'feature', 'event-bus', '--auto-repair', '--json']);

        $this->assertSame(0, $result['status']);
        $this->assertSame('repaired', $result['payload']['status']);
        $this->assertTrue($result['payload']['repair_attempted']);
        $this->assertTrue($result['payload']['repair_successful']);
    }

    public function test_state_and_decisions_are_updated_after_execution(): void
    {
        $this->runCommand(['foundry', 'context', 'init', 'event-bus', '--json']);
        $this->writeMeaningfulContext('event-bus');

        $result = $this->runCommand(['foundry', 'implement', 'feature', 'event-bus', '--json']);
        $state = (string) file_get_contents($this->project->root . '/docs/features/event-bus/event-bus.md');
        $decisions = (string) file_get_contents($this->project->root . '/docs/features/event-bus/event-bus.decisions.md');

        $this->assertSame(0, $result['status']);
        $this->assertStringContainsString('## Current State', $state);
        $this->assertStringContainsString('Implemented Event bus feature scaffolding exists in the app.', $state);
        $this->assertStringContainsString('### Decision: context-driven execution for event-bus', $decisions);
    }

    public function test_implement_feature_fails_when_quality_gate_does_not_pass(): void
    {
        $this->runCommand(['foundry', 'context', 'init', 'event-bus', '--json']);
        $this->writeMeaningfulContext('event-bus');
        file_put_contents($this->project->root . '/.foundry-test-coverage-lines', "89.50\n");

        $result = $this->runCommand(['foundry', 'implement', 'feature', 'event-bus', '--json']);

        $this->assertSame(1, $result['status']);
        $this->assertSame('completed_with_issues', $result['payload']['status']);
        $this->assertSame('IMPLEMENTATION_QUALITY_GATE_GLOBAL_COVERAGE_BELOW_THRESHOLD', $result['payload']['issues'][0]['code']);
        $this->assertFalse($result['payload']['quality_gate']['passed']);
    }

    public function test_non_consumable_context_returns_refusal_payload(): void
    {
        $this->runCommand(['foundry', 'context', 'init', 'event-bus', '--json']);

        $result = $this->runCommand(['foundry', 'implement', 'feature', 'event-bus', '--json']);

        $this->assertSame(1, $result['status']);
        $this->assertSame('blocked', $result['payload']['status']);
        $this->assertFalse($result['payload']['can_proceed']);
        $this->assertTrue($result['payload']['requires_repair']);
        $this->assertSame('context_not_consumable', $result['payload']['reason']);
        $this->assertSame(
            'Run `php bin/foundry verify context --json` and resolve all issues before proceeding.',
            $result['payload']['required_action'],
        );
        $this->assertNotSame([], $result['payload']['issues']);
        $this->assertContains(
            'Update the feature state to reflect current implementation.',
            $result['payload']['required_actions'],
        );
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
        file_put_contents($this->project->root . '/docs/features/' . $feature . '/' . $feature . '.spec.md', <<<MD
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

- Event bus feature files are present.

## Assumptions

- Initial implementation may be scaffold-first.
MD);

        file_put_contents($this->project->root . '/docs/features/' . $feature . '/' . $feature . '.md', <<<MD
# Feature: {$feature}

## Purpose

Introduce event bus handling.

## Current State

- Event bus feature implementation is pending.

## Open Questions

- None.

## Next Steps

- Event bus feature scaffolding exists in the app.
- Event bus feature files are present.
MD);
    }
}
