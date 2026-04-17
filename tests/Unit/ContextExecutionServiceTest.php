<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Context\ContextExecutionService;
use Foundry\Context\ContextInitService;
use Foundry\Context\ExecutionSpec;
use Foundry\Context\ExecutionSpecResolver;
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
        $this->assertSame('context_not_consumable', $result['reason']);
        $this->assertSame(
            'Run `php bin/foundry verify context --json` and resolve all issues before proceeding.',
            $result['required_action'],
        );
        $this->assertContains('Create missing spec file: docs/features/event-bus.spec.md', $result['required_actions']);
    }

    public function test_execution_refuses_non_consumable_context_with_standard_reason(): void
    {
        $this->initService()->init('event-bus');

        $result = $this->service()->execute('event-bus')->toArray();

        $this->assertSame('blocked', $result['status']);
        $this->assertFalse($result['can_proceed']);
        $this->assertTrue($result['requires_repair']);
        $this->assertSame('context_not_consumable', $result['reason']);
        $this->assertSame(
            'Run `php bin/foundry verify context --json` and resolve all issues before proceeding.',
            $result['required_action'],
        );
        $this->assertContains('Update the feature state to reflect current implementation.', $result['required_actions']);
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

    public function test_execution_returns_completed_with_issues_when_post_execution_revalidation_fails(): void
    {
        $this->writeMeaningfulContext('event-bus');
        unlink($this->project->root . '/docs/features/event-bus.md');

        $result = $this->finalizeExecutionFor(
            featureName: 'event-bus',
            repairAttempted: false,
            repairSuccessful: false,
            actionsTaken: ['Generated feature files.'],
        )->toArray();

        $this->assertSame('completed_with_issues', $result['status']);
        $this->assertFalse($result['can_proceed']);
        $this->assertTrue($result['requires_repair']);
        $this->assertSame('CONTEXT_FILE_MISSING', $result['issues'][0]['code']);
        $this->assertContains('Create missing state file: docs/features/event-bus.md', $result['required_actions']);
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

    public function test_auto_log_execution_spec_regression_is_not_treated_as_canonical_conflict(): void
    {
        $this->writeExecutionSpecSystemContext();
        $this->writeExecutionSpecSystemExecutionSpec();

        $conflict = $this->canonicalConflictFor(
            $this->resolver()->resolve('execution-spec-system/004-spec-auto-log-on-implementation'),
        );

        $this->assertNull($conflict);
    }

    public function test_equivalent_prohibitions_are_treated_as_aligned_not_conflicting(): void
    {
        $this->writeMeaningfulContext('execution-spec-system');
        file_put_contents($this->project->root . '/docs/features/execution-spec-system.spec.md', <<<MD
# Feature Spec: execution-spec-system

## Purpose

Keep execution-spec implementation logging deterministic.

## Goals

- Preserve canonical execution-spec lifecycle rules.

## Non-Goals

- Do not log draft specs as implemented.

## Constraints

- Keep conflict detection deterministic.

## Expected Behavior

- Active execution specs are logged after successful implementation.

## Acceptance Criteria

- Draft specs are not auto-logged.

## Assumptions

- Feature directories continue to provide execution-spec context.
MD);

        $conflict = $this->canonicalConflictFor(
            new ExecutionSpec(
                specId: 'execution-spec-system/017-conflict-detection-prohibition-awareness',
                feature: 'execution-spec-system',
                path: 'docs/specs/execution-spec-system/017-conflict-detection-prohibition-awareness.md',
                requestedChanges: ['Do not append log entries for draft specs.'],
            ),
        );

        $this->assertNull($conflict);
    }

    public function test_positive_execution_instruction_conflicts_with_canonical_prohibition(): void
    {
        $this->writeMeaningfulContext('execution-spec-system');
        file_put_contents($this->project->root . '/docs/features/execution-spec-system.spec.md', <<<MD
# Feature Spec: execution-spec-system

## Purpose

Keep execution-spec implementation logging deterministic.

## Goals

- Preserve canonical execution-spec lifecycle rules.

## Non-Goals

- Do not log draft specs as implemented.

## Constraints

- Keep conflict detection deterministic.

## Expected Behavior

- Active execution specs are logged after successful implementation.

## Acceptance Criteria

- Draft specs are not auto-logged.

## Assumptions

- Feature directories continue to provide execution-spec context.
MD);

        $conflict = $this->canonicalConflictFor(
            new ExecutionSpec(
                specId: 'execution-spec-system/017-conflict-detection-prohibition-awareness',
                feature: 'execution-spec-system',
                path: 'docs/specs/execution-spec-system/017-conflict-detection-prohibition-awareness.md',
                requestedChanges: ['Append log entries for draft specs after implementation.'],
            ),
        );

        $this->assertIsArray($conflict);
        $this->assertSame('EXECUTION_SPEC_CONFLICTS_WITH_CANONICAL_SPEC', $conflict['issue']['code']);
    }

    public function test_negative_execution_instruction_conflicts_with_positive_canonical_requirement(): void
    {
        $this->writeMeaningfulContext('execution-spec-system');
        file_put_contents($this->project->root . '/docs/features/execution-spec-system.spec.md', <<<MD
# Feature Spec: execution-spec-system

## Purpose

Keep execution-spec implementation logging deterministic.

## Goals

- Preserve canonical execution-spec lifecycle rules.

## Non-Goals

- Do not rename existing execution-spec ids once assigned.

## Constraints

- Keep conflict detection deterministic.

## Expected Behavior

- Active execution specs append one implementation-log entry after successful implementation.

## Acceptance Criteria

- Successful active execution-spec implementation appends exactly one implementation-log entry automatically.

## Assumptions

- Feature directories continue to provide execution-spec context.
MD);

        $conflict = $this->canonicalConflictFor(
            new ExecutionSpec(
                specId: 'execution-spec-system/017-conflict-detection-prohibition-awareness',
                feature: 'execution-spec-system',
                path: 'docs/specs/execution-spec-system/017-conflict-detection-prohibition-awareness.md',
                requestedChanges: ['Do not append implementation-log entries for active execution specs.'],
            ),
        );

        $this->assertIsArray($conflict);
        $this->assertSame('EXECUTION_SPEC_CONFLICTS_WITH_CANONICAL_SPEC', $conflict['issue']['code']);
    }

    public function test_true_canonical_conflict_still_detects_renaming_forbidden_ids(): void
    {
        $this->writeMeaningfulContext('execution-spec-system');
        $specPath = $this->project->root . '/docs/features/execution-spec-system.spec.md';
        file_put_contents($specPath, <<<MD
# Feature Spec: execution-spec-system

## Purpose

Keep execution-spec identity deterministic.

## Goals

- Preserve canonical execution-spec ids.

## Non-Goals

- Do not rename existing execution-spec ids once assigned.

## Constraints

- Keep conflict detection deterministic.

## Expected Behavior

- Existing ids remain unchanged.

## Acceptance Criteria

- Renaming existing ids is rejected.

## Assumptions

- Execution specs remain feature-scoped.
MD);

        $conflict = $this->canonicalConflictFor(
            new ExecutionSpec(
                specId: 'execution-spec-system/005-fix-canonical-conflict-detection',
                feature: 'execution-spec-system',
                path: 'docs/specs/execution-spec-system/005-fix-canonical-conflict-detection.md',
                requestedChanges: ['Rename existing execution-spec ids to new padded values.'],
            ),
        );

        $this->assertIsArray($conflict);
        $this->assertSame('EXECUTION_SPEC_CONFLICTS_WITH_CANONICAL_SPEC', $conflict['issue']['code']);
    }

    public function test_non_executable_canonical_requirement_still_blocks_execute_draft_specs_instruction(): void
    {
        $this->writeMeaningfulContext('execution-spec-system');
        file_put_contents($this->project->root . '/docs/features/execution-spec-system.spec.md', <<<MD
# Feature Spec: execution-spec-system

## Purpose

Keep draft execution specs non-executable.

## Goals

- Preserve canonical execution-spec lifecycle rules.

## Non-Goals

- Do not rename existing execution-spec ids once assigned.

## Constraints

- Keep conflict detection deterministic.

## Expected Behavior

- Draft specs remain non-executable planning artifacts.

## Acceptance Criteria

- Implement spec rejects draft execution specs.

## Assumptions

- Feature directories continue to provide execution-spec context.
MD);

        $conflict = $this->canonicalConflictFor(
            new ExecutionSpec(
                specId: 'execution-spec-system/017-conflict-detection-prohibition-awareness',
                feature: 'execution-spec-system',
                path: 'docs/specs/execution-spec-system/017-conflict-detection-prohibition-awareness.md',
                requestedChanges: ['Execute draft specs during implementation.'],
            ),
        );

        $this->assertIsArray($conflict);
        $this->assertSame('EXECUTION_SPEC_CONFLICTS_WITH_CANONICAL_SPEC', $conflict['issue']['code']);
    }

    public function test_canonical_conflict_detection_is_deterministic_for_repeated_runs(): void
    {
        $this->writeExecutionSpecSystemContext();

        $executionSpec = new ExecutionSpec(
            specId: 'execution-spec-system/004-spec-auto-log-on-implementation',
            feature: 'execution-spec-system',
            path: 'docs/specs/execution-spec-system/004-spec-auto-log-on-implementation.md',
            scope: [
                'Hook into the active execution-spec implementation flow.',
                'Append entries to docs/specs/implementation-log.md.',
                'Enforce required log-entry formatting.',
                'Prevent duplicate entries for the same completed implementation event.',
            ],
            constraints: [
                'Must not log draft specs.',
                'Must not duplicate entries for the same implementation event.',
                'Must use the required format from docs/specs/README.md.',
                'Must be deterministic in structure and behavior.',
                'Must surface log-write failures clearly and deterministically.',
            ],
            requestedChanges: [
                'After successful implementation of an active execution spec, Foundry must automatically append an implementation entry to docs/specs/implementation-log.md.',
            ],
        );

        $first = $this->canonicalConflictFor($executionSpec);
        $second = $this->canonicalConflictFor($executionSpec);

        $this->assertSame($first, $second);
        $this->assertNull($first);
    }

    public function test_framework_repository_execution_spec_is_blocked_before_app_scaffolding(): void
    {
        $this->writeExecutionSpecSystemContext();
        $this->writeExecutionSpecSystemExecutionSpec();

        $result = $this->frameworkService()->executeSpec(
            $this->frameworkResolver()->resolve('execution-spec-system/004-spec-auto-log-on-implementation'),
        );

        $this->assertSame('blocked', $result['status']);
        $this->assertFalse($result['can_proceed']);
        $this->assertTrue($result['requires_repair']);
        $this->assertSame('EXECUTION_SPEC_FRAMEWORK_APP_SCAFFOLD_BLOCKED', $result['issues'][0]['code']);
        $this->assertDirectoryDoesNotExist($this->project->root . '/app/features/execution-spec-system');
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

    public function test_non_consumable_context_blocks_before_execution_runs(): void
    {
        $this->initService()->init('event-bus');

        $result = $this->service()->execute('event-bus')->toArray();

        $this->assertSame('blocked', $result['status']);
        $this->assertFalse($result['can_proceed']);
        $this->assertTrue($result['requires_repair']);
        $this->assertNotSame([], $result['issues']);
        $this->assertContains(
            'Update the feature state to reflect current implementation.',
            $result['required_actions'],
        );
    }

    public function test_execution_state_write_path_normalizes_existing_state_document_noise(): void
    {
        $this->initService()->init('event-bus');

        file_put_contents($this->project->root . '/docs/features/event-bus.spec.md', <<<MD
# Feature Spec: event-bus

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

        file_put_contents($this->project->root . '/docs/features/event-bus.md', <<<MD
# Feature: event-bus

## Purpose

Introduce event bus handling.

## Next Steps

- Event bus feature scaffolding exists in the app.
- 35D7B implementation completed.
- Add contract coverage.

## Current State

- Feature spec created.
- Event bus feature implementation is pending.
- Event bus feature implementation is pending.

## Open Questions

- TBD.
MD);

        $result = $this->service()->execute('event-bus')->toArray();
        $state = (string) file_get_contents($this->project->root . '/docs/features/event-bus.md');

        $this->assertSame('completed', $result['status']);
        $this->assertStringContainsString("## Current State\n\n- Event bus feature implementation is pending.\n- Implemented Event bus feature scaffolding exists in the app.\n", $state);
        $this->assertStringContainsString("## Open Questions\n\n- TBD.\n", $state);
        $this->assertStringContainsString("## Next Steps\n\n- Add contract coverage.\n- Event bus feature files are present.\n", $state);
        $this->assertStringNotContainsString('Feature spec created.', $state);
        $this->assertStringNotContainsString('35D7B implementation completed.', $state);
        $this->assertStringNotContainsString("- Event bus feature scaffolding exists in the app.\n", $state);
    }

    private function service(): ContextExecutionService
    {
        return new ContextExecutionService(new Paths($this->project->root));
    }

    private function initService(): ContextInitService
    {
        return new ContextInitService(new Paths($this->project->root));
    }

    private function resolver(): ExecutionSpecResolver
    {
        return new ExecutionSpecResolver(new Paths($this->project->root));
    }

    private function frameworkResolver(): ExecutionSpecResolver
    {
        return new ExecutionSpecResolver(new Paths($this->project->root, $this->project->root));
    }

    private function frameworkService(): ContextExecutionService
    {
        return new ContextExecutionService(new Paths($this->project->root, $this->project->root));
    }

    /**
     * @return array{issue:array<string,mixed>,required_actions:list<string>}|null
     */
    private function canonicalConflictFor(ExecutionSpec $executionSpec): ?array
    {
        $method = new \ReflectionMethod(ContextExecutionService::class, 'canonicalConflictForExecutionSpec');
        $method->setAccessible(true);

        /** @var array{issue:array<string,mixed>,required_actions:list<string>}|null $result */
        $result = $method->invoke($this->service(), $executionSpec);

        return $result;
    }

    /**
     * @param list<string> $actionsTaken
     */
    private function finalizeExecutionFor(
        string $featureName,
        bool $repairAttempted,
        bool $repairSuccessful,
        array $actionsTaken,
    ): \Foundry\Context\ExecutionResult {
        $method = new \ReflectionMethod(ContextExecutionService::class, 'finalizeExecutionResult');
        $method->setAccessible(true);

        /** @var \Foundry\Context\ExecutionResult $result */
        $result = $method->invoke(
            $this->service(),
            $featureName,
            $repairAttempted,
            $repairSuccessful,
            $actionsTaken,
        );

        return $result;
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

    private function writeExecutionSpecSystemContext(): void
    {
        $this->initService()->init('execution-spec-system');

        file_put_contents($this->project->root . '/docs/features/execution-spec-system.spec.md', <<<MD
# Feature Spec: execution-spec-system

## Purpose

Keep execution-spec implementation logging deterministic.

## Goals

- Preserve canonical execution-spec naming and lifecycle rules.

## Non-Goals

- Do not rename existing execution-spec ids once assigned.

## Constraints

- Automatic implementation logging must not log draft specs, must prevent duplicate entries, and must surface log-write failures clearly and deterministically.

## Expected Behavior

- Successful implement spec runs for active execution specs append one required-format entry to docs/specs/implementation-log.md.
- Draft execution specs are never logged as implemented, and repeated completion of the same active spec does not duplicate the log entry.
- If the implementation log cannot be updated, implement spec must surface that failure clearly and deterministically.

## Acceptance Criteria

- Successful active execution-spec implementation appends exactly one correctly formatted implementation-log entry automatically.
- Draft execution specs are not auto-logged.
- Implementation-log write failures surface clearly and deterministically and do not appear as a clean successful completion.

## Assumptions

- Feature directories continue to provide execution-spec context.
MD);

        file_put_contents($this->project->root . '/docs/features/execution-spec-system.md', <<<MD
# Feature: execution-spec-system

## Purpose

Keep execution-spec implementation logging deterministic.

## Current State

- Implementation-log behavior is under active development.

## Open Questions

- None.

## Next Steps

- Finalize deterministic implementation logging.
MD);
    }

    private function writeExecutionSpecSystemExecutionSpec(): void
    {
        $path = $this->project->root . '/docs/specs/execution-spec-system/004-spec-auto-log-on-implementation.md';
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($path, <<<'MD'
# Execution Spec: 004-spec-auto-log-on-implementation

## Feature

- execution-spec-system

## Purpose

- Automatically append implementation entries to the implementation log when an active execution spec is implemented successfully.

## Scope

- Hook into the active execution-spec implementation flow.
- Append entries to `docs/specs/implementation-log.md`.
- Enforce required log-entry formatting.
- Prevent duplicate entries for the same completed implementation event.

## Constraints

- Must not log draft specs.
- Must not duplicate entries for the same implementation event.
- Must use the required format from `docs/specs/README.md`.
- Must be deterministic in structure and behavior.
- Must surface log-write failures clearly and deterministically.

## Requested Changes

### 1. Trigger Point

After successful implementation of an active execution spec, Foundry must automatically append an implementation entry to:

`docs/specs/implementation-log.md`

This must occur only after implementation has succeeded.

Do not append log entries:
- before implementation succeeds
- for draft specs
- for failed or partial implementations
MD);
    }
}
