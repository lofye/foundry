<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Context\ContextExecutionService;
use Foundry\Context\ContextInitService;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class ContextExecutionServiceTest extends TestCase
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

    public function test_execution_is_blocked_when_context_cannot_proceed(): void
    {
        $result = $this->service()->execute('event-bus')->toArray();

        $this->assertSame('blocked', $result['status']);
        $this->assertFalse($result['can_proceed']);
        $this->assertTrue($result['requires_repair']);
        $this->assertFalse($result['repair_attempted']);
        $this->assertContains('Create missing spec file: docs/features/event-bus.spec.md', $result['required_actions']);
    }

    public function test_execution_proceeds_when_context_is_valid(): void
    {
        $this->writeMeaningfulContext('event-bus');

        $result = $this->service()->execute('event-bus')->toArray();

        $this->assertSame('completed', $result['status']);
        $this->assertTrue($result['can_proceed']);
        $this->assertFalse($result['requires_repair']);
        $this->assertFalse($result['repair_attempted']);
        $this->assertFalse($result['repair_successful']);
        $this->assertFileExists($this->project->root . '/app/features/event_bus/feature.yaml');
        $this->assertStringContainsString('Implemented Event bus feature scaffolding exists in the app.', (string) file_get_contents($this->project->root . '/docs/features/event-bus.md'));
        $this->assertStringContainsString('### Decision: context-driven execution for event-bus', (string) file_get_contents($this->project->root . '/docs/features/event-bus.decisions.md'));
    }

    public function test_guided_repair_resolves_simple_issues_deterministically(): void
    {
        $this->writeMeaningfulContext('event-bus');
        unlink($this->project->root . '/docs/features/event-bus.md');

        $result = $this->service()->execute('event-bus', repair: true)->toArray();

        $this->assertSame('repaired', $result['status']);
        $this->assertTrue($result['can_proceed']);
        $this->assertFalse($result['requires_repair']);
        $this->assertTrue($result['repair_attempted']);
        $this->assertTrue($result['repair_successful']);
        $this->assertStringContainsString('Created missing context file: docs/features/event-bus.md', $result['actions_taken'][0]);
    }

    public function test_auto_repair_performs_safe_deterministic_fixes(): void
    {
        $this->writeMeaningfulContext('event-bus');
        $path = $this->project->root . '/docs/features/event-bus.spec.md';
        file_put_contents($path, str_replace('# Feature Spec: event-bus', '# Spec: event-bus', (string) file_get_contents($path)));

        $result = $this->service()->execute('event-bus', autoRepair: true)->toArray();

        $this->assertSame('repaired', $result['status']);
        $this->assertTrue($result['repair_attempted']);
        $this->assertTrue($result['repair_successful']);
        $this->assertContains('Fixed malformed spec heading: docs/features/event-bus.spec.md', $result['actions_taken']);
    }

    public function test_execution_input_is_deterministic(): void
    {
        $this->writeMeaningfulContext('event-bus');

        $first = $this->service()->buildExecutionInput('event-bus');
        $second = $this->service()->buildExecutionInput('event-bus');

        $this->assertSame($first, $second);
        $this->assertSame('event-bus', $first['feature']);
        $this->assertSame('event_bus', $first['app_feature']);
    }

    public function test_result_shape_is_stable(): void
    {
        $this->writeMeaningfulContext('event-bus');

        $result = $this->service()->execute('event-bus')->toArray();

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
        ], array_keys($result));
    }

    public function test_failed_revalidation_returns_completed_with_issues(): void
    {
        $this->initService()->init('event-bus');

        $result = $this->service()->execute('event-bus')->toArray();

        $this->assertSame('completed_with_issues', $result['status']);
        $this->assertTrue($result['can_proceed']);
        $this->assertFalse($result['requires_repair']);
        $this->assertNotSame([], $result['issues']);
        $this->assertContains(
            'Update the spec to reflect the decision-backed behavior if it is now intended behavior.',
            $result['required_actions'],
        );
    }

    private function service(): ContextExecutionService
    {
        return new ContextExecutionService(new Paths($this->project->root));
    }

    private function initService(): ContextInitService
    {
        return new ContextInitService(new Paths($this->project->root));
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

- Event bus feature files are present.

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

- Event bus feature scaffolding exists in the app.
- Event bus feature files are present.
MD);
    }
}
