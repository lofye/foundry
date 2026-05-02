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
        $this->assertSame('event-bus/003-contract-test-coverage', $result['spec_id']);
        $this->assertFileExists($this->project->root . '/docs/features/event-bus/specs/drafts/003-contract-test-coverage.md');
        $this->assertFileDoesNotExist($this->project->root . '/docs/features/event-bus/specs/003-contract-test-coverage.md');
    }

    public function test_next_execution_spec_number_considers_drafts_and_hierarchical_ids(): void
    {
        $this->writeMeaningfulContext('event-bus');
        $this->writeExistingSpec('event-bus', '001-initial');
        $this->writeExistingSpec('event-bus', '003.001-draft-follow-up', 'drafts');

        $result = $this->service()->plan('event-bus')->toArray();

        $this->assertSame('planned', $result['status']);
        $this->assertSame('event-bus/004-contract-test-coverage', $result['spec_id']);
        $this->assertFileExists($this->project->root . '/docs/features/event-bus/specs/drafts/004-contract-test-coverage.md');
        $this->assertFileDoesNotExist($this->project->root . '/docs/features/event-bus/specs/004-contract-test-coverage.md');
    }

    public function test_planning_writes_exactly_one_draft_spec_and_reports_it_truthfully(): void
    {
        $this->writeMeaningfulContext('event-bus');

        $beforePaths = $this->specPaths('event-bus');
        $result = $this->service()->plan('event-bus')->toArray();
        $afterPaths = $this->specPaths('event-bus');

        $this->assertSame('planned', $result['status']);
        $this->assertSame(
            [(string) $result['spec_path']],
            array_values(array_diff($afterPaths, $beforePaths)),
        );
        $this->assertSame('docs/features/event-bus/specs/drafts/001-contract-test-coverage.md', $result['spec_path']);
        $this->assertSame('event-bus/001-contract-test-coverage', $result['spec_id']);
        $this->assertFileExists($this->project->root . '/' . (string) $result['spec_path']);
        $this->assertFileDoesNotExist($this->project->root . '/docs/features/event-bus/specs/001-contract-test-coverage.md');
    }

    public function test_generated_execution_spec_matches_stub_structure_exactly(): void
    {
        $this->writeMeaningfulContext('event-bus');

        $result = $this->service()->plan('event-bus')->toArray();
        $contents = (string) file_get_contents($this->project->root . '/' . (string) $result['spec_path']);

        $this->assertSame(<<<'MD'
# Execution Spec: 001-contract-test-coverage

## Feature
- event-bus

## Purpose
- Current State does not yet reflect contract test coverage for the event bus feature, so this is the next bounded step now.

## Scope
- Event bus contract-test coverage and generated verification.

## Constraints
- Keep canonical feature context authoritative.
- Keep generated execution specs secondary to canonical feature truth.
- Keep this work deterministic and bounded to one coherent step.
- Respect prior decisions recorded in docs/features/event-bus/event-bus.decisions.md.

## Requested Changes
- Add contract test coverage for the event bus feature.

## Non-Goals
- Do not broaden this step beyond Event bus contract-test coverage and generated verification.
- Do not change canonical feature context authority.

## Completion Signals
- Add contract test coverage for the event bus feature.
- docs/features/event-bus/event-bus.md reflects contract test coverage for the event bus feature.

## Post-Execution Expectations
- Current State reflects the completed bounded work.
- Meaningful execution decisions are appended to docs/features/event-bus/event-bus.decisions.md when needed.
- Canonical feature context remains authoritative for later work.
MD . "\n", $contents);
    }

    public function test_planning_outputs_are_identical_for_identical_projects(): void
    {
        $this->writeMeaningfulContext('event-bus');

        $firstResult = $this->service()->plan('event-bus')->toArray();
        $firstContents = (string) file_get_contents(
            $this->project->root . '/' . (string) $firstResult['spec_path'],
        );

        $otherProject = new TempProject();

        try {
            $this->writeMeaningfulContextForProject($otherProject, 'event-bus');
            $otherService = new ContextPlanningService(new Paths($otherProject->root));
            $secondResult = $otherService->plan('event-bus')->toArray();
            $secondContents = (string) file_get_contents(
                $otherProject->root . '/' . (string) $secondResult['spec_path'],
            );
        } finally {
            $otherProject->cleanup();
        }

        $this->assertSame($firstResult, $secondResult);
        $this->assertSame($firstContents, $secondContents);
    }

    public function test_stub_changes_propagate_without_planner_changes(): void
    {
        $this->writeMeaningfulContext('event-bus');

        $frameworkRoot = $this->project->root . '/framework-fixture';
        mkdir($frameworkRoot . '/stubs/specs', 0777, true);
        file_put_contents($frameworkRoot . '/stubs/specs/execution-spec.stub.md', <<<'MD'
# Execution Spec: {{spec_name}}

## Feature
- {{feature}}

## Purpose
- Planned via custom stub: {{purpose}}

## Scope
{{scope}}

## Constraints
{{constraints}}

## Requested Changes
{{requested_changes}}

## Non-Goals
{{non_goals}}

## Completion Signals
{{completion_signals}}

## Post-Execution Expectations
{{post_execution_expectations}}
MD);

        $service = new ContextPlanningService(new Paths($this->project->root, $frameworkRoot));
        $result = $service->plan('event-bus')->toArray();
        $contents = (string) file_get_contents($this->project->root . '/' . (string) $result['spec_path']);

        $this->assertStringContainsString(
            '- Planned via custom stub: Current State does not yet reflect contract test coverage for the event bus feature, so this is the next bounded step now.',
            $contents,
        );
    }

    public function test_planning_is_blocked_when_required_context_is_missing(): void
    {
        $result = $this->service()->plan('event-bus')->toArray();

        $this->assertSame('blocked', $result['status']);
        $this->assertFalse($result['can_proceed']);
        $this->assertTrue($result['requires_repair']);
        $this->assertContains('Create missing spec file: docs/features/event-bus/event-bus.spec.md', $result['required_actions']);
    }

    public function test_planning_is_blocked_when_only_non_meaningful_gap_remains(): void
    {
        $this->writeGenericPlanningContext('context-persistence');

        $result = $this->service()->plan('context-persistence')->toArray();

        $this->assertSame('blocked', $result['status']);
        $this->assertFalse($result['can_proceed']);
        $this->assertTrue($result['requires_repair']);
        $this->assertSame('PLANNING_NO_BOUNDED_STEP', $result['issues'][0]['code']);
        $this->assertContains(
            'Update docs/features/context-persistence/context-persistence.spec.md or docs/features/context-persistence/context-persistence.md so there is a concrete actionable gap between Expected Behavior and Current State.',
            $result['required_actions'],
        );
    }

    public function test_planning_blocks_generic_fallback_slug_and_does_not_write_execution_spec(): void
    {
        $this->writeGenericFallbackPlanningContext('event-bus');

        $result = $this->service()->plan('event-bus')->toArray();

        $this->assertSame('blocked', $result['status']);
        $this->assertFalse($result['can_proceed']);
        $this->assertTrue($result['requires_repair']);
        $this->assertSame('PLANNING_NO_BOUNDED_STEP', $result['issues'][0]['code']);
        $this->assertFileDoesNotExist($this->project->root . '/docs/features/event-bus/specs/drafts/001-support.md');
    }

    public function test_planning_fails_clearly_when_draft_directory_path_is_blocked(): void
    {
        $this->writeMeaningfulContext('event-bus');
        mkdir($this->project->root . '/docs/features/event-bus/specs', 0777, true);
        file_put_contents($this->project->root . '/docs/features/event-bus/specs/drafts', 'blocked');

        $this->expectException(\Foundry\Support\FoundryError::class);
        $this->expectExceptionMessage('Draft execution spec directory path exists but is not a directory.');

        $this->service()->plan('event-bus');
    }

    public function test_blocked_planning_response_is_identical_across_repeated_runs(): void
    {
        $this->writeGenericPlanningContext('context-persistence');

        $first = $this->service()->plan('context-persistence')->toArray();
        $second = $this->service()->plan('context-persistence')->toArray();

        $this->assertSame($first, $second);
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

    public function test_invalid_feature_names_are_blocked_before_context_inspection(): void
    {
        $result = $this->service()->plan('Not Valid')->toArray();

        $this->assertSame('blocked', $result['status']);
        $this->assertTrue($result['requires_repair']);
        $this->assertSame('Not Valid', $result['feature']);
        $this->assertContains('Use a lowercase kebab-case feature name.', $result['required_actions']);
    }

    public function test_planning_blocks_when_the_target_spec_path_already_exists(): void
    {
        $this->writeMeaningfulContext('event-bus');
        $this->writeExistingSpec('event-bus', '001-initial');
        mkdir($this->project->root . '/docs/features/event-bus/specs/drafts', 0777, true);
        mkdir($this->project->root . '/docs/features/event-bus/specs/drafts/002-contract-test-coverage.md', 0777, true);

        $result = $this->service()->plan('event-bus')->toArray();

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('PLANNING_SPEC_PATH_EXISTS', $result['issues'][0]['code']);
        $this->assertSame('docs/features/event-bus/specs/drafts/002-contract-test-coverage.md', $result['spec_path']);
    }

    public function test_missing_or_invalid_stubs_fail_fast(): void
    {
        $this->writeMeaningfulContext('event-bus');

        $missingFrameworkRoot = $this->project->root . '/missing-framework';
        mkdir($missingFrameworkRoot, 0777, true);

        try {
            (new ContextPlanningService(new Paths($this->project->root, $missingFrameworkRoot)))->plan('event-bus');
            self::fail('Expected missing stub failure.');
        } catch (\Foundry\Support\FoundryError $error) {
            $this->assertSame('PLANNING_SPEC_STUB_MISSING', $error->errorCode);
        }

        $invalidFrameworkRoot = $this->project->root . '/invalid-framework';
        mkdir($invalidFrameworkRoot . '/stubs/specs', 0777, true);
        file_put_contents($invalidFrameworkRoot . '/stubs/specs/execution-spec.stub.md', <<<'MD'
# Wrong Heading
MD);

        try {
            (new ContextPlanningService(new Paths($this->project->root, $invalidFrameworkRoot)))->plan('event-bus');
            self::fail('Expected invalid stub heading failure.');
        } catch (\Foundry\Support\FoundryError $error) {
            $this->assertSame('PLANNING_SPEC_STUB_INVALID', $error->errorCode);
        }
    }

    private function service(): ContextPlanningService
    {
        return new ContextPlanningService(new Paths($this->project->root));
    }

    private function initService(): ContextInitService
    {
        return new ContextInitService(new Paths($this->project->root));
    }

    /**
     * @return list<string>
     */
    private function specPaths(string $feature): array
    {
        $paths = [];

        foreach ([
            $this->project->root . '/docs/features/' . $feature . '/specs',
            $this->project->root . '/docs/features/' . $feature . '/specs/drafts',
        ] as $directory) {
            foreach (glob($directory . '/*.md') ?: [] as $path) {
                $paths[] = str_replace($this->project->root . '/', '', $path);
            }
        }

        sort($paths);

        return $paths;
    }

    private function writeExistingSpec(string $feature, string $name, string $subdirectory = ''): void
    {
        $directory = $this->project->root . '/docs/features/' . $feature . '/specs' . ($subdirectory !== '' ? '/' . $subdirectory : '');
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($directory . '/' . $name . '.md', <<<MD
# Execution Spec: {$name}

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

- Add contract test coverage for the event bus feature.

## Assumptions

- Initial implementation may be scaffold-first.
MD);

        file_put_contents($this->project->root . '/docs/features/' . $feature . '/' . $feature . '.md', <<<MD
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

    private function writeMeaningfulContextForProject(TempProject $project, string $feature): void
    {
        $initService = new ContextInitService(new Paths($project->root));
        $initService->init($feature);

        file_put_contents($project->root . '/docs/features/' . $feature . '/' . $feature . '.spec.md', <<<MD
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

        file_put_contents($project->root . '/docs/features/' . $feature . '/' . $feature . '.md', <<<MD
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
        $this->initService()->init($feature);

        file_put_contents($this->project->root . '/docs/features/' . $feature . '/' . $feature . '.spec.md', <<<MD
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

- Plan feature generates the next bounded execution spec deterministically under docs/features/<feature>/specs/drafts/<id>-<slug>.md.
- Later execution systems can consume canonical feature context files safely.

## Acceptance Criteria

- Plan feature returns deterministic planned or blocked results.

## Assumptions

- Execution specs remain secondary.
MD);

        file_put_contents($this->project->root . '/docs/features/' . $feature . '/' . $feature . '.md', <<<MD
# Feature: {$feature}

## Purpose

Preserve feature intent across sessions.

## Current State

- Plan feature generates the next bounded execution spec deterministically under docs/features/<feature>/specs/drafts/<id>-<slug>.md.
- Plan feature returns deterministic planned or blocked results.

## Open Questions

- None.

## Next Steps

- Keep later execution systems safely consumable from canonical feature context files.
MD);
    }

    private function writeGenericFallbackPlanningContext(string $feature): void
    {
        $this->initService()->init($feature);

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

- Add support.

## Assumptions

- Initial implementation may be scaffold-first.
MD);

        file_put_contents($this->project->root . '/docs/features/' . $feature . '/' . $feature . '.md', <<<MD
# Feature: {$feature}

## Purpose

Introduce event bus handling.

## Current State

- Event bus feature scaffolding exists in the app.

## Open Questions

- None.

## Next Steps

- Add support.
MD);
    }
}
