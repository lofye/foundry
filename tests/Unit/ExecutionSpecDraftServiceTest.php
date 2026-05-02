<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Context\ExecutionSpecDraftService;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class ExecutionSpecDraftServiceTest extends TestCase
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

    public function test_create_draft_normalizes_slug_and_allocates_next_id_deterministically(): void
    {
        $this->writeSpec('execution-spec-system', '001-hierarchical-spec-ids-with-padded-segments');
        $this->writeSpec('execution-spec-system', '002-existing-draft', 'drafts');

        $first = $this->service()->createDraft('execution-spec-system', '  Add CLI / Command  ');

        $otherProject = new TempProject();

        try {
            $this->writeSpecForProject($otherProject, 'execution-spec-system', '001-hierarchical-spec-ids-with-padded-segments');
            $this->writeSpecForProject($otherProject, 'execution-spec-system', '002-existing-draft', 'drafts');

            $second = (new ExecutionSpecDraftService(new Paths($otherProject->root)))
                ->createDraft('execution-spec-system', '  Add CLI / Command  ');
        } finally {
            $otherProject->cleanup();
        }

        $this->assertTrue($first['success']);
        $this->assertSame('003', $first['id']);
        $this->assertSame('add-cli-command', $first['slug']);
        $this->assertSame($first, $second);
    }

    public function test_generated_draft_template_matches_required_structure_exactly(): void
    {
        $result = $this->service()->createDraft('execution-spec-system', 'add-cli-command');

        $this->assertTrue($result['success']);
        $this->assertSame(<<<'MD'
# Execution Spec: 001-add-cli-command

## Feature
- execution-spec-system

## Purpose

## Scope

## Constraints

## Requested Changes

## Non-Goals

## Authority Rule

## Completion Signals

## Post-Execution Expectations
MD . "\n", (string) file_get_contents($this->project->root . '/' . (string) $result['path']));
    }

    public function test_allocation_failure_is_reported_when_feature_spec_state_is_invalid(): void
    {
        $directory = $this->project->root . '/docs/features/execution-spec-system/specs/drafts';
        mkdir($directory, 0777, true);
        file_put_contents($directory . '/not-a-spec.md', "# Execution Spec: not-a-spec\n");

        $result = $this->service()->createDraft('execution-spec-system', 'add-cli-command');

        $this->assertFalse($result['success']);
        $this->assertSame('could not allocate next spec ID', $result['reason']);
    }

    public function test_allocation_failure_is_reported_when_feature_has_skipped_ids(): void
    {
        $this->writeSpec('execution-spec-system', '001-draft-first', 'drafts');
        $this->writeSpec('execution-spec-system', '003-draft-third', 'drafts');

        $result = $this->service()->createDraft('execution-spec-system', 'add-cli-command');

        $this->assertFalse($result['success']);
        $this->assertSame('could not allocate next spec ID', $result['reason']);
        $this->assertContains('Resolve duplicate, invalid, or skipped execution spec IDs in this feature', $result['required_actions']);
    }

    public function test_create_draft_fails_when_active_sequence_has_gap_even_if_drafts_are_contiguous(): void
    {
        $this->writeSpec('execution-spec-system', '001-active-first');
        $this->writeSpec('execution-spec-system', '003-active-third');
        $this->writeSpec('execution-spec-system', '001-draft-first', 'drafts');

        $result = $this->service()->createDraft('execution-spec-system', 'add-cli-command');

        $this->assertFalse($result['success']);
        $this->assertSame('could not allocate next spec ID', $result['reason']);
    }

    public function test_create_draft_rejects_invalid_feature_name(): void
    {
        $result = $this->service()->createDraft('Execution Spec System', 'add-cli-command');

        $this->assertFalse($result['success']);
        $this->assertSame('invalid feature name', $result['reason']);
    }

    public function test_create_draft_rejects_invalid_slug(): void
    {
        $result = $this->service()->createDraft('execution-spec-system', 'draft');

        $this->assertFalse($result['success']);
        $this->assertSame('invalid slug', $result['reason']);
    }

    public function test_create_draft_reports_target_file_collision(): void
    {
        $path = $this->project->root . '/docs/features/execution-spec-system/specs/drafts/001-add-cli-command.md';
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        mkdir($path, 0777, true);

        $result = $this->service()->createDraft('execution-spec-system', 'add-cli-command');

        $this->assertFalse($result['success']);
        $this->assertSame('target file already exists', $result['reason']);
        $this->assertSame('001', $result['id']);
    }

    private function service(): ExecutionSpecDraftService
    {
        return new ExecutionSpecDraftService(new Paths($this->project->root));
    }

    private function writeSpec(string $feature, string $name, string $subdirectory = ''): void
    {
        $this->writeSpecForProject($this->project, $feature, $name, $subdirectory);
    }

    private function writeSpecForProject(TempProject $project, string $feature, string $name, string $subdirectory = ''): void
    {
        $directory = $project->root . '/docs/features/' . $feature . '/specs' . ($subdirectory !== '' ? '/' . $subdirectory : '');
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($directory . '/' . $name . '.md', '# Execution Spec: ' . $name . "\n");
    }
}
