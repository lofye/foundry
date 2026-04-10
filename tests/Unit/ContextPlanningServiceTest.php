<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Context\ContextInitService;
use Foundry\Context\ContextPlanningService;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class ContextPlanningServiceTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function test_next_execution_spec_number_is_determined_correctly(): void
    {
        $this->writeMeaningfulContext('event-bus');
        $this->writeExistingSpec('event-bus', '001-initial');
        $this->writeExistingSpec('event-bus', '002-add-handler');

        $result = $this->service()->plan('event-bus')->toArray();

        $this->assertSame('planned', $result['status']);
        $this->assertSame('event-bus/003-add-contract-test-coverage', $result['spec_id']);
        $this->assertFileExists($this->project->root . '/docs/specs/event-bus/003-add-contract-test-coverage.md');
    }

    public function test_planning_is_blocked_when_required_context_is_missing(): void
    {
        $result = $this->service()->plan('event-bus')->toArray();

        $this->assertSame('blocked', $result['status']);
        $this->assertFalse($result['can_proceed']);
        $this->assertTrue($result['requires_repair']);
        $this->assertContains('Create missing spec file: docs/features/event-bus.spec.md', $result['required_actions']);
    }

    public function test_result_shape_is_stable(): void
    {
        $this->writeMeaningfulContext('event-bus');

        $result = $this->service()->plan('event-bus')->toArray();

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
        ], array_keys($result));
    }

    private function service(): ContextPlanningService
    {
        return new ContextPlanningService(new Paths($this->project->root));
    }

    private function initService(): ContextInitService
    {
        return new ContextInitService(new Paths($this->project->root));
    }

    private function writeExistingSpec(string $feature, string $name): void
    {
        $directory = $this->project->root . '/docs/specs/' . $feature;
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($directory . '/' . $name . '.md', <<<MD
# Execution Spec: {$feature}/{$name}

## Feature
- {$feature}

## Purpose
- Existing execution spec.

## Scope
- Existing execution scope.

## Constraints
- Existing execution constraints.

## Requested Changes
- Existing execution requested change.
MD);
    }

    private function writeMeaningfulContext(string $feature): void
    {
        $this->initService()->init($feature);

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

- Event bus feature scaffolding exists in the app.

## Open Questions

- None.

## Next Steps

- Add contract test coverage for the event bus feature.
MD);
    }
}
