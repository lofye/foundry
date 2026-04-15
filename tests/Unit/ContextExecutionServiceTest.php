<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Context\ContextExecutionService;
use Foundry\Context\ContextInitService;
use Foundry\Context\ExecutionSpec;
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
        $this->assertFileExists($this->project->root . '/app/features/event-bus/feature.yaml');
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
        $this->assertSame('app/features/event-bus', $first['paths']['feature_base']);
    }

    public function test_execution_normalizes_underscore_input_but_keeps_code_safe_identifiers_snake_case(): void
    {
        $this->writeMeaningfulContext('event-bus');

        $result = $this->service()->execute('event_bus')->toArray();

        $this->assertSame('completed', $result['status']);
        $this->assertFileExists($this->project->root . '/app/features/event-bus/feature.yaml');
        $this->assertFileExists($this->project->root . '/app/features/event-bus/tests/event_bus_contract_test.php');
        $this->assertFileDoesNotExist($this->project->root . '/app/features/event_bus/feature.yaml');
    }

    public function test_execution_spec_conflict_with_canonical_spec_is_blocked(): void
    {
        $this->writeMeaningfulContext('event-bus');
        $specPath = $this->project->root . '/docs/features/event-bus.spec.md';
        file_put_contents($specPath, str_replace(
            '- Do not add async delivery.',
            '- Do not make execution specs authoritative after implementation.',
            (string) file_get_contents($specPath),
        ));

        $result = $this->service()->executeSpec(
            new ExecutionSpec(
                specId: 'event-bus/001-initial',
                feature: 'event-bus',
                path: 'docs/specs/event-bus/001-initial.md',
                requestedChanges: ['Make execution specs authoritative after implementation.'],
            ),
        );

        $this->assertSame('blocked', $result['status']);
        $this->assertFalse($result['can_proceed']);
        $this->assertTrue($result['requires_repair']);
        $this->assertSame('EXECUTION_SPEC_CONFLICTS_WITH_CANONICAL_SPEC', $result['issues'][0]['code']);
    }

    public function test_execution_spec_repair_mode_reuses_feature_execution_pipeline(): void
    {
        $this->writeMeaningfulContext('event-bus');
        unlink($this->project->root . '/docs/features/event-bus.md');

        $result = $this->service()->executeSpec(
            new ExecutionSpec(
                specId: 'event-bus/001-initial',
                feature: 'event-bus',
                path: 'docs/specs/event-bus/001-initial.md',
                requestedChanges: ['Add deterministic event bus scaffolding.'],
            ),
            repair: true,
        );

        $this->assertSame('repaired', $result['status']);
        $this->assertTrue($result['repair_attempted']);
        $this->assertTrue($result['repair_successful']);
    }

    public function test_execution_spec_auto_repair_reuses_feature_execution_pipeline(): void
    {
        $this->writeMeaningfulContext('event-bus');
        $path = $this->project->root . '/docs/features/event-bus.spec.md';
        file_put_contents($path, str_replace('# Feature Spec: event-bus', '# Spec: event-bus', (string) file_get_contents($path)));

        $result = $this->service()->executeSpec(
            new ExecutionSpec(
                specId: 'event-bus/001-initial',
                feature: 'event-bus',
                path: 'docs/specs/event-bus/001-initial.md',
                requestedChanges: ['Add deterministic event bus scaffolding.'],
            ),
            autoRepair: true,
        );

        $this->assertSame('repaired', $result['status']);
        $this->assertTrue($result['repair_attempted']);
        $this->assertTrue($result['repair_successful']);
    }

    public function test_execution_spec_skips_implementation_log_for_draft_paths(): void
    {
        $this->writeMeaningfulContext('event-bus');

        $result = $this->service()->executeSpec(
            new ExecutionSpec(
                specId: 'event-bus/001-initial',
                feature: 'event-bus',
                path: 'docs/specs/event-bus/drafts/001-initial.md',
                requestedChanges: ['Add deterministic event bus scaffolding.'],
            ),
        );

        $this->assertSame('completed', $result['status']);
        $this->assertFileDoesNotExist($this->project->root . '/docs/specs/implementation-log.md');
    }

    public function test_execution_spec_log_write_failure_returns_completed_with_issues(): void
    {
        $this->writeMeaningfulContext('event-bus');
        mkdir($this->project->root . '/docs/specs/implementation-log.md', 0777, true);

        $result = $this->service()->executeSpec(
            new ExecutionSpec(
                specId: 'event-bus/001-initial',
                feature: 'event-bus',
                path: 'docs/specs/event-bus/001-initial.md',
                requestedChanges: ['Add deterministic event bus scaffolding.'],
            ),
        );

        $this->assertSame('completed_with_issues', $result['status']);
        $this->assertSame('EXECUTION_SPEC_IMPLEMENTATION_LOG_WRITE_FAILED', $result['issues'][0]['code']);
        $this->assertContains(
            'Restore write access to docs/specs/implementation-log.md and record the missing implementation entry.',
            $result['required_actions'],
        );
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
