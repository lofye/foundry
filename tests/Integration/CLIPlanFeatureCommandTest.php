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
        $this->assertSame('event-bus/001-contract-test-coverage', $result['payload']['spec_id']);
        $this->assertSame('docs/specs/event-bus/001-contract-test-coverage.md', $result['payload']['spec_path']);
        $this->assertSame(['generated execution spec'], $result['payload']['actions_taken']);
        $this->assertFileExists($this->project->root . '/docs/specs/event-bus/001-contract-test-coverage.md');

        $contents = (string) file_get_contents($this->project->root . '/docs/specs/event-bus/001-contract-test-coverage.md');
        $this->assertStringContainsString('# Execution Spec: 001-contract-test-coverage', $contents);
        $this->assertStringContainsString('## Feature', $contents);
        $this->assertStringContainsString('## Purpose', $contents);
        $this->assertStringContainsString('## Scope', $contents);
        $this->assertStringContainsString('## Constraints', $contents);
        $this->assertStringContainsString('## Requested Changes', $contents);
        $this->assertStringContainsString('## Non-Goals', $contents);
        $this->assertStringContainsString('## Completion Signals', $contents);
        $this->assertStringContainsString('## Post-Execution Expectations', $contents);
        $this->assertStringContainsString('- Current State does not yet reflect contract test coverage for the event bus feature, so this is the next bounded step now.', $contents);
        $this->assertStringContainsString('- Event bus contract-test coverage and generated verification.', $contents);
        $this->assertStringContainsString('- Add contract test coverage for the event bus feature.', $contents);
        $this->assertStringContainsString('- Do not change canonical feature context authority.', $contents);
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

    public function test_plan_feature_output_is_identical_for_identical_projects(): void
    {
        $first = $this->planFeatureInProject($this->project->root, 'event-bus');

        $otherProject = new TempProject();

        try {
            $second = $this->planFeatureInProject($otherProject->root, 'event-bus');
        } finally {
            $otherProject->cleanup();
            chdir($this->project->root);
        }

        $this->assertSame($first['payload'], $second['payload']);
        $this->assertSame($first['contents'], $second['contents']);
    }

    public function test_plan_feature_blocks_when_only_non_actionable_gap_remains(): void
    {
        $this->runCommand(['foundry', 'context', 'init', 'context-persistence', '--json']);
        $this->writeGenericPlanningContext('context-persistence');

        $result = $this->runCommand(['foundry', 'plan', 'feature', 'context-persistence', '--json']);

        $this->assertSame(1, $result['status']);
        $this->assertSame('blocked', $result['payload']['status']);
        $this->assertSame('PLANNING_NO_BOUNDED_STEP', $result['payload']['issues'][0]['code']);
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

    /**
     * @return array{payload:array<string,mixed>,contents:string}
     */
    private function planFeatureInProject(string $root, string $feature): array
    {
        chdir($root);
        $this->runCommand(['foundry', 'context', 'init', $feature, '--json']);
        $this->writeMeaningfulContextInRoot($root, $feature);
        $result = $this->runCommand(['foundry', 'plan', 'feature', $feature, '--json']);

        return [
            'payload' => $result['payload'],
            'contents' => (string) file_get_contents($root . '/' . (string) $result['payload']['spec_path']),
        ];
    }

    private function writeMeaningfulContextInRoot(string $root, string $feature): void
    {
        file_put_contents($root . '/docs/features/' . $feature . '.spec.md', <<<MD
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

        file_put_contents($root . '/docs/features/' . $feature . '.md', <<<MD
# Feature: {$feature}

## Purpose

Introduce event bus handling.

## Current State

- Event bus feature scaffolding exists in the app.

## Open Questions

- None.

## Next Steps

- Add contract test coverage for the event bus feature.
MD);
    }

    private function writeGenericPlanningContext(string $feature): void
    {
        file_put_contents($this->project->root . '/docs/features/' . $feature . '.spec.md', <<<MD
# Feature Spec: {$feature}

## Purpose

Preserve feature intent across sessions.

## Goals

- Introduce deterministic planning.

## Non-Goals

- Do not add prompt-only execution.

## Constraints

- Must remain deterministic.

## Expected Behavior

- Plan feature generates the next bounded execution spec deterministically under docs/specs/<feature>/<id>-<slug>.md.
- Later execution systems can consume canonical feature context files safely.

## Acceptance Criteria

- Plan feature returns deterministic planned or blocked results.

## Assumptions

- Execution specs remain secondary.
MD);

        file_put_contents($this->project->root . '/docs/features/' . $feature . '.md', <<<MD
# Feature: {$feature}

## Purpose

Preserve feature intent across sessions.

## Current State

- Plan feature generates the next bounded execution spec deterministically under docs/specs/<feature>/<id>-<slug>.md.
- Plan feature returns deterministic planned or blocked results.

## Open Questions

- None.

## Next Steps

- Keep later execution systems safely consumable from canonical feature context files.
MD);
    }
}
