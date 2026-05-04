<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Context\ExecutionSpecValidationService;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class ExecutionSpecValidationServiceTest extends TestCase
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

    public function test_validate_passes_for_canonical_active_and_draft_specs(): void
    {
        $this->writeSpec(
            'execution-spec-system',
            '001-hierarchical-spec-ids-with-padded-segments',
            <<<'MD'
# Execution Spec: 001-hierarchical-spec-ids-with-padded-segments

```yaml
status: draft
```
MD,
        );
        $this->writeSpec(
            'execution-spec-system',
            '002-spec-new-cli-command',
            '# Execution Spec: 002-spec-new-cli-command',
            'drafts',
        );
        $this->writeImplementationLogEntry('execution-spec-system/001-hierarchical-spec-ids-with-padded-segments.md');

        $result = $this->service()->validate();

        $this->assertTrue($result['ok']);
        $this->assertSame(
            ['checked_files' => 2, 'features' => 1, 'violations' => 0],
            $result['summary'],
        );
        $this->assertSame([], $result['violations']);
    }

    public function test_validate_reports_rule_violations_deterministically(): void
    {
        $this->writeSpec('execution-spec-system', '001-first-active', '# Execution Spec: 001-first-active');
        $this->writeSpec('execution-spec-system', '001-second-draft', '# Execution Spec: 001-second-draft', 'drafts');
        $this->writeSpec(
            'execution-spec-system',
            '002-bad-heading',
            '# Execution Spec: execution-spec-system/002-bad-heading',
        );
        $this->writeSpec(
            'execution-spec-system',
            '003-with-status',
            <<<'MD'
# Execution Spec: 003-with-status

status: draft
MD,
        );
        $this->writeRawFile(
            'docs/features/execution-spec-system/specs/archive/004-misplaced.md',
            '# Execution Spec: 004-misplaced' . "\n",
        );
        $this->writeRawFile(
            'docs/features/execution-spec-system/specs/not-a-spec.md',
            '# Execution Spec: not-a-spec' . "\n",
        );
        $this->writeImplementationLogEntry('execution-spec-system/001-first-active.md');
        $this->writeImplementationLogEntry('execution-spec-system/002-bad-heading.md');
        $this->writeImplementationLogEntry('execution-spec-system/003-with-status.md');

        $result = $this->service()->validate();

        $this->assertFalse($result['ok']);
        $this->assertSame(
            ['checked_files' => 6, 'features' => 1, 'violations' => 5],
            $result['summary'],
        );
        $this->assertSame(
            [
                'EXECUTION_SPEC_DUPLICATE_ID',
                'EXECUTION_SPEC_INVALID_HEADING',
                'EXECUTION_SPEC_FORBIDDEN_METADATA',
                'EXECUTION_SPEC_INVALID_DIRECTORY',
                'EXECUTION_SPEC_INVALID_FILENAME',
            ],
            array_map(
                static fn(array $violation): string => (string) $violation['code'],
                $result['violations'],
            ),
        );
        $this->assertSame(
            [
                'expected_heading' => '# Execution Spec: 002-bad-heading',
                'actual_heading' => '# Execution Spec: execution-spec-system/002-bad-heading',
            ],
            $result['violations'][1]['details'],
        );
        $this->assertSame(
            [
                'feature' => 'execution-spec-system',
                'id' => '001',
                'paths' => [
                    'docs/features/execution-spec-system/specs/001-first-active.md',
                    'docs/features/execution-spec-system/specs/drafts/001-second-draft.md',
                ],
            ],
            $result['violations'][0]['details'],
        );
        $this->assertSame(['field' => 'status', 'line' => 3], $result['violations'][2]['details']);
    }

    public function test_validate_reports_filename_only_heading_as_invalid(): void
    {
        $this->writeSpec('execution-spec-system', '001-grandchild', '# 001-grandchild');
        $this->writeImplementationLogEntry('execution-spec-system/001-grandchild.md');

        $result = $this->service()->validate();

        $this->assertFalse($result['ok']);
        $this->assertSame('EXECUTION_SPEC_INVALID_HEADING', $result['violations'][0]['code']);
        $this->assertSame(
            [
                'expected_heading' => '# Execution Spec: 001-grandchild',
                'actual_heading' => '# 001-grandchild',
            ],
            $result['violations'][0]['details'],
        );
    }

    public function test_validate_reports_malformed_execution_spec_prefix_as_invalid(): void
    {
        $this->writeSpec('execution-spec-system', '001-grandchild', '# ExecutionSpec: 001-grandchild');
        $this->writeImplementationLogEntry('execution-spec-system/001-grandchild.md');

        $result = $this->service()->validate();

        $this->assertFalse($result['ok']);
        $this->assertSame('EXECUTION_SPEC_INVALID_HEADING', $result['violations'][0]['code']);
        $this->assertSame(
            [
                'expected_heading' => '# Execution Spec: 001-grandchild',
                'actual_heading' => '# ExecutionSpec: 001-grandchild',
            ],
            $result['violations'][0]['details'],
        );
    }

    public function test_validate_accepts_hierarchical_heading_with_execution_spec_prefix(): void
    {
        $this->writeSpec(
            'execution-spec-system',
            '001-parent',
            '# Execution Spec: 001-parent',
        );
        $this->writeSpec(
            'execution-spec-system',
            '001.001-child',
            '# Execution Spec: 001.001-child',
        );
        $this->writeSpec(
            'execution-spec-system',
            '001.001.001-grandchild',
            '# Execution Spec: 001.001.001-grandchild',
        );
        $this->writeImplementationLogEntry('execution-spec-system/001-parent.md');
        $this->writeImplementationLogEntry('execution-spec-system/001.001-child.md');
        $this->writeImplementationLogEntry('execution-spec-system/001.001.001-grandchild.md');

        $result = $this->service()->validate();

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['violations']);
    }

    public function test_validate_ignores_forbidden_metadata_inside_fenced_code_blocks(): void
    {
        $this->writeSpec(
            'execution-spec-system',
            '001-code-sample',
            <<<'MD'
# Execution Spec: 001-code-sample

```yaml
id: 001
parent: 000
status: draft
```
MD,
        );
        $this->writeImplementationLogEntry('execution-spec-system/001-code-sample.md');

        $result = $this->service()->validate();

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['violations']);
    }

    public function test_validate_requires_matching_implementation_log_entries_for_active_specs_only(): void
    {
        $this->writeSpec('execution-spec-system', '001-active-with-log', '# Execution Spec: 001-active-with-log');
        $this->writeSpec('execution-spec-system', '002-draft-without-log', '# Execution Spec: 002-draft-without-log', 'drafts');
        $this->writeImplementationLogEntry('execution-spec-system/001-active-with-log.md');

        $result = $this->service()->validate();

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['violations']);
    }

    public function test_validate_reports_missing_implementation_log_entries_for_active_specs(): void
    {
        $this->writeSpec('execution-spec-system', '001-active-missing-log', '# Execution Spec: 001-active-missing-log');
        $this->writeSpec('execution-spec-system', '002-draft-missing-log', '# Execution Spec: 002-draft-missing-log', 'drafts');

        $result = $this->service()->validate();

        $this->assertFalse($result['ok']);
        $this->assertSame(
            [
                'code' => 'EXECUTION_SPEC_IMPLEMENTATION_LOG_MISSING',
                'message' => 'Active execution specs must have a matching implementation-log entry.',
                'file_path' => 'docs/features/execution-spec-system/specs/001-active-missing-log.md',
                'details' => [
                    'spec' => 'execution-spec-system/001-active-missing-log.md',
                    'log_path' => 'docs/features/implementation-log.md',
                ],
            ],
            $result['violations'][0],
        );
    }

    public function test_validate_reports_top_level_gap_deterministically(): void
    {
        $this->writeSpec('execution-spec-system', '001-first', '# Execution Spec: 001-first');
        $this->writeSpec('execution-spec-system', '003-third', '# Execution Spec: 003-third');
        $this->writeImplementationLogEntry('execution-spec-system/001-first.md');
        $this->writeImplementationLogEntry('execution-spec-system/003-third.md');

        $result = $this->service()->validate();
        $this->assertFalse($result['ok']);
        $this->assertContains('EXECUTION_SPEC_ID_GAP', array_map(static fn(array $violation): string => (string) $violation['code'], $result['violations']));
        $gap = array_values(array_filter($result['violations'], static fn(array $violation): bool => $violation['code'] === 'EXECUTION_SPEC_ID_GAP'))[0];
        $this->assertSame('002', $gap['details']['missing_id']);
        $this->assertSame('003', $gap['details']['next_observed_id']);
        $this->assertSame('active', $gap['details']['location']);
        $this->assertSame('top-level', $gap['details']['parent_id']);
        $this->assertSame('docs/features/execution-spec-system/specs/003-third.md', $gap['file_path']);
    }

    public function test_validate_reports_child_gap_and_missing_parent_deterministically(): void
    {
        $this->writeSpec('execution-spec-system', '007-parent', '# Execution Spec: 007-parent');
        $this->writeSpec('execution-spec-system', '007.001-child-a', '# Execution Spec: 007.001-child-a');
        $this->writeSpec('execution-spec-system', '007.003-child-c', '# Execution Spec: 007.003-child-c');
        $this->writeSpec('execution-spec-system', '009.001-orphan-child', '# Execution Spec: 009.001-orphan-child');
        $this->writeImplementationLogEntry('execution-spec-system/007-parent.md');
        $this->writeImplementationLogEntry('execution-spec-system/007.001-child-a.md');
        $this->writeImplementationLogEntry('execution-spec-system/007.003-child-c.md');
        $this->writeImplementationLogEntry('execution-spec-system/009.001-orphan-child.md');

        $result = $this->service()->validate();
        $this->assertFalse($result['ok']);

        $gaps = array_values(array_filter($result['violations'], static fn(array $violation): bool => $violation['code'] === 'EXECUTION_SPEC_ID_GAP'));
        $this->assertNotEmpty($gaps);
        $this->assertTrue($this->containsGap($gaps, '007.002', '007.003', 'docs/features/execution-spec-system/specs/007.003-child-c.md'));
        $this->assertTrue($this->containsGap($gaps, '009', '009.001', 'docs/features/execution-spec-system/specs/009.001-orphan-child.md'));
    }

    public function test_validate_checks_active_and_draft_continuity_separately(): void
    {
        $this->writeSpec('execution-spec-system', '001-first', '# Execution Spec: 001-first');
        $this->writeSpec('execution-spec-system', '003-third', '# Execution Spec: 003-third', 'drafts');
        $this->writeImplementationLogEntry('execution-spec-system/001-first.md');

        $result = $this->service()->validate();
        $this->assertTrue($result['ok']);
    }

    public function test_validate_enforces_active_and_draft_continuity_separately(): void
    {
        $this->writeSpec('execution-spec-system', '001-active-a', '# Execution Spec: 001-active-a');
        $this->writeSpec('execution-spec-system', '002-active-b', '# Execution Spec: 002-active-b');
        $this->writeSpec('execution-spec-system', '001-draft-a', '# Execution Spec: 001-draft-a', 'drafts');
        $this->writeSpec('execution-spec-system', '003-draft-c', '# Execution Spec: 003-draft-c', 'drafts');
        $this->writeImplementationLogEntry('execution-spec-system/001-active-a.md');
        $this->writeImplementationLogEntry('execution-spec-system/002-active-b.md');

        $result = $this->service()->validate();
        $this->assertFalse($result['ok']);
        $gap = array_values(array_filter($result['violations'], static fn(array $violation): bool => $violation['code'] === 'EXECUTION_SPEC_ID_GAP'))[0];
        $this->assertSame('drafts', $gap['details']['location']);
        $this->assertSame('002', $gap['details']['missing_id']);
        $this->assertSame('003', $gap['details']['next_observed_id']);
    }

    public function test_validate_supports_canonical_features_workspace_specs_and_log(): void
    {
        $this->writeRawFile('Features/ExecutionSpecSystem/specs/001-canonical.md', '# Execution Spec: 001-canonical');
        $this->writeRawFile(
            'Features/implementation.log',
            "## 2026-05-03 12:00:00 -0400\n- spec: execution-spec-system/001-canonical.md\n",
        );

        $result = $this->service()->validate();

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['violations']);
    }

    public function test_validate_reports_duplicate_canonical_and_legacy_spec_definitions(): void
    {
        $this->writeRawFile('Features/ExecutionSpecSystem/specs/001-shared.md', '# Execution Spec: 001-shared');
        $this->writeSpec('execution-spec-system', '001-shared', '# Execution Spec: 001-shared');
        $this->writeImplementationLogEntry('execution-spec-system/001-shared.md');

        $result = $this->service()->validate();

        $this->assertFalse($result['ok']);
        $codes = array_map(static fn(array $violation): string => (string) $violation['code'], $result['violations']);
        $this->assertContains('FEATURE_DUPLICATE_CANONICAL_AND_LEGACY', $codes);
    }

    public function test_validate_does_not_enforce_global_sequence_across_features(): void
    {
        $this->writeSpec('execution-spec-system', '001-first', '# Execution Spec: 001-first');
        $this->writeSpec('generate-engine', '001-first', '# Execution Spec: 001-first');
        $this->writeImplementationLogEntry('execution-spec-system/001-first.md');
        $this->writeImplementationLogEntry('generate-engine/001-first.md');

        $result = $this->service()->validate();
        $this->assertTrue($result['ok']);
    }

    public function test_validate_rejects_implementation_log_entries_with_skipped_ids(): void
    {
        $this->writeSpec('execution-spec-system', '001-first', '# Execution Spec: 001-first');
        $this->writeImplementationLogEntry('execution-spec-system/001-first.md');
        $this->writeImplementationLogEntry('execution-spec-system/003-third.md');

        $result = $this->service()->validate();
        $this->assertFalse($result['ok']);
        $codes = array_map(static fn(array $violation): string => (string) $violation['code'], $result['violations']);
        $this->assertContains('EXECUTION_SPEC_IMPLEMENTATION_LOG_SKIPPED_ID', $codes);
    }

    public function test_validate_ignores_noncanonical_implementation_log_references_for_continuity(): void
    {
        $this->writeSpec('execution-spec-system', '001-first', '# Execution Spec: 001-first');
        $this->writeImplementationLogEntry('execution-spec-system/001-first.md');
        $this->writeImplementationLogEntry('not-a-canonical-reference');
        $this->writeImplementationLogEntry('execution-spec-system/not-a-spec.md');

        $result = $this->service()->validate();

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['violations']);
    }

    public function test_validate_reports_invalid_directory_for_legacy_spec_paths(): void
    {
        $this->writeRawFile(
            'docs/specs/001-legacy.md',
            "# Execution Spec: 001-legacy\n",
        );

        $result = $this->service()->validate();
        $this->assertFalse($result['ok']);
        $this->assertSame('EXECUTION_SPEC_INVALID_DIRECTORY', $result['violations'][0]['code']);
    }

    public function test_validate_reports_invalid_plan_directory_and_filename(): void
    {
        $this->writeRawFile(
            'docs/specs/plans/not-a-spec.md',
            "# Implementation Plan: not-a-spec\n",
        );
        $this->writeRawFile(
            'docs/features/execution-spec-system/plans/not-a-spec.md',
            "# Implementation Plan: not-a-spec\n",
        );

        $result = $this->service()->validate();
        $codes = array_map(static fn(array $violation): string => (string) $violation['code'], $result['violations']);
        $this->assertContains('EXECUTION_SPEC_PLAN_INVALID_DIRECTORY', $codes);
        $this->assertContains('EXECUTION_SPEC_PLAN_INVALID_FILENAME', $codes);
    }

    public function test_validate_reports_forbidden_plan_metadata_and_duplicate_plan_ids(): void
    {
        $this->writeSpec('execution-spec-system', '001-first', '# Execution Spec: 001-first');
        $this->writeImplementationLogEntry('execution-spec-system/001-first.md');
        $this->writeRawFile(
            'docs/features/execution-spec-system/plans/001-first.md',
            "# Implementation Plan: 001-first\n\nstatus: draft\n",
        );
        $this->writeRawFile(
            'docs/features/execution-spec-system/plans/archive/001-first.md',
            "# Implementation Plan: 001-first\n",
        );

        $result = $this->service()->validate();
        $codes = array_map(static fn(array $violation): string => (string) $violation['code'], $result['violations']);
        $this->assertContains('EXECUTION_SPEC_PLAN_FORBIDDEN_METADATA', $codes);
        $this->assertContains('EXECUTION_SPEC_PLAN_INVALID_DIRECTORY', $codes);
    }

    public function test_validate_reports_duplicate_plan_ids_with_deterministic_details(): void
    {
        $this->writeSpec('execution-spec-system', '001-first', '# Execution Spec: 001-first');
        $this->writeImplementationLogEntry('execution-spec-system/001-first.md');
        $this->writeRawFile(
            'docs/features/execution-spec-system/plans/001-first.md',
            "# Implementation Plan: 001-first\n",
        );
        $this->writeRawFile(
            'docs/features/execution-spec-system/plans/001-second.md',
            "# Implementation Plan: 001-second\n",
        );

        $result = $this->service()->validate();

        $this->assertFalse($result['ok']);
        $duplicate = array_values(array_filter(
            $result['violations'],
            static fn(array $violation): bool => $violation['code'] === 'EXECUTION_SPEC_PLAN_DUPLICATE_ID',
        ))[0];
        $this->assertSame(
            [
                'feature' => 'execution-spec-system',
                'id' => '001',
                'paths' => [
                    'docs/features/execution-spec-system/plans/001-first.md',
                    'docs/features/execution-spec-system/plans/001-second.md',
                ],
            ],
            $duplicate['details'],
        );
    }

    public function test_validate_reports_invalid_implementation_log_when_path_is_directory(): void
    {
        $absolutePath = $this->project->root . '/docs/features/implementation-log.md';
        if (!is_dir(dirname($absolutePath))) {
            mkdir(dirname($absolutePath), 0777, true);
        }
        mkdir($absolutePath, 0777, true);

        $this->writeSpec('execution-spec-system', '001-first', '# Execution Spec: 001-first');

        $result = $this->service()->validate();
        $this->assertFalse($result['ok']);
        $this->assertContains(
            'EXECUTION_SPEC_IMPLEMENTATION_LOG_INVALID',
            array_map(static fn(array $violation): string => (string) $violation['code'], $result['violations']),
        );
    }

    public function test_validate_accepts_valid_plan_file_in_canonical_location(): void
    {
        $this->writeSpec('execution-spec-system', '001-implementation-plan-files', '# Execution Spec: 001-implementation-plan-files');
        $this->writeImplementationLogEntry('execution-spec-system/001-implementation-plan-files.md');
        $this->writeRawFile(
            'docs/features/execution-spec-system/plans/001-implementation-plan-files.md',
            "# Implementation Plan: 001-implementation-plan-files\n",
        );

        $result = $this->service()->validate();

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['violations']);
    }

    public function test_validate_skips_directories_that_match_spec_and_plan_globs(): void
    {
        $this->writeSpec('execution-spec-system', '001-first', '# Execution Spec: 001-first');
        $this->writeImplementationLogEntry('execution-spec-system/001-first.md');
        mkdir($this->project->root . '/docs/features/execution-spec-system/specs/002-directory.md', 0777, true);
        mkdir($this->project->root . '/docs/features/execution-spec-system/plans/001-plan-directory.md', 0777, true);

        $result = $this->service()->validate();

        $this->assertTrue($result['ok']);
        $this->assertSame(
            ['checked_files' => 1, 'features' => 1, 'violations' => 0],
            $result['summary'],
        );
    }

    public function test_validate_rejects_orphan_plan_and_bad_heading(): void
    {
        $this->writeSpec('execution-spec-system', '001-implementation-plan-files', '# Execution Spec: 001-implementation-plan-files');
        $this->writeImplementationLogEntry('execution-spec-system/001-implementation-plan-files.md');
        $this->writeRawFile(
            'docs/features/execution-spec-system/plans/002-orphan.md',
            "# Implementation Plan: 002-orphan\n",
        );
        $this->writeRawFile(
            'docs/features/execution-spec-system/plans/001-implementation-plan-files.md',
            "# Implementation Plan: execution-spec-system/001-implementation-plan-files\n",
        );

        $result = $this->service()->validate();

        $this->assertFalse($result['ok']);
        $codes = array_map(static fn(array $violation): string => (string) $violation['code'], $result['violations']);
        $this->assertContains('EXECUTION_SPEC_PLAN_ORPHAN', $codes);
        $this->assertContains('EXECUTION_SPEC_PLAN_INVALID_HEADING', $codes);
    }

    public function test_validate_require_plans_only_enforces_active_specs(): void
    {
        $this->writeSpec('execution-spec-system', '001-active-missing-plan', '# Execution Spec: 001-active-missing-plan');
        $this->writeSpec('execution-spec-system', '002-draft-missing-plan', '# Execution Spec: 002-draft-missing-plan', 'drafts');
        $this->writeImplementationLogEntry('execution-spec-system/001-active-missing-plan.md');

        $default = $this->service()->validate();
        $strict = $this->service()->validate(true);

        $this->assertTrue($default['ok']);
        $this->assertFalse($strict['ok']);
        $this->assertSame('EXECUTION_SPEC_PLAN_REQUIRED_MISSING', $strict['violations'][0]['code']);
        $this->assertSame(
            'docs/features/execution-spec-system/plans/001-active-missing-plan.md',
            $strict['violations'][0]['details']['plan_path'],
        );
    }

    public function test_validate_require_plans_continues_past_specs_that_already_have_plans(): void
    {
        $this->writeSpec('execution-spec-system', '001-has-plan', '# Execution Spec: 001-has-plan');
        $this->writeSpec('execution-spec-system', '002-missing-plan', '# Execution Spec: 002-missing-plan');
        $this->writeImplementationLogEntry('execution-spec-system/001-has-plan.md');
        $this->writeImplementationLogEntry('execution-spec-system/002-missing-plan.md');
        $this->writeRawFile(
            'docs/features/execution-spec-system/plans/001-has-plan.md',
            "# Implementation Plan: 001-has-plan\n",
        );

        $result = $this->service()->validate(true);

        $this->assertFalse($result['ok']);
        $this->assertSame(1, $result['summary']['violations']);
        $this->assertSame('EXECUTION_SPEC_PLAN_REQUIRED_MISSING', $result['violations'][0]['code']);
        $this->assertSame(
            'docs/features/execution-spec-system/plans/002-missing-plan.md',
            $result['violations'][0]['details']['plan_path'],
        );
    }

    public function test_validate_matches_implementation_log_entries_exactly_and_stably(): void
    {
        $this->writeSpec('execution-spec-system', '001-exact-match-required', '# Execution Spec: 001-exact-match-required');
        $this->writeImplementationLogEntry('execution-spec-system/001-exact-match-required-typo.md');

        $first = $this->service()->validate();
        $second = $this->service()->validate();

        $this->assertSame($first, $second);
        $this->assertFalse($first['ok']);
        $this->assertSame('EXECUTION_SPEC_IMPLEMENTATION_LOG_MISSING', $first['violations'][0]['code']);
        $this->assertSame(
            'execution-spec-system/001-exact-match-required.md',
            $first['violations'][0]['details']['spec'],
        );
    }

    private function service(): ExecutionSpecValidationService
    {
        return new ExecutionSpecValidationService(new Paths($this->project->root));
    }

    private function writeSpec(string $feature, string $name, string $contents, string $subdirectory = ''): void
    {
        $relativePath = 'docs/features/' . $feature . '/specs' . ($subdirectory !== '' ? '/' . $subdirectory : '') . '/' . $name . '.md';
        $this->writeRawFile($relativePath, rtrim($contents, "\n") . "\n");
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

    private function writeImplementationLogEntry(string $specReference): void
    {
        $absolutePath = $this->project->root . '/docs/features/implementation-log.md';
        $directory = dirname($absolutePath);

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $entry = "## 2026-04-17 12:00:00 -0400\n- spec: {$specReference}\n";
        $existing = file_exists($absolutePath) ? (string) file_get_contents($absolutePath) : '';
        $contents = $existing === '' ? $entry : rtrim($existing, "\n") . "\n\n" . $entry;

        file_put_contents($absolutePath, $contents);
    }

    /**
     * @param list<array<string,mixed>> $gaps
     */
    private function containsGap(array $gaps, string $missingId, string $nextObservedId, string $path): bool
    {
        foreach ($gaps as $gap) {
            $details = $gap['details'] ?? [];
            if (!is_array($details)) {
                continue;
            }

            if (
                ($details['missing_id'] ?? null) === $missingId
                && ($details['next_observed_id'] ?? null) === $nextObservedId
                && ($details['path'] ?? null) === $path
            ) {
                return true;
            }
        }

        return false;
    }
}
