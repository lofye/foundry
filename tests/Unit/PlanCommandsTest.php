<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\CLI\CommandContext;
use Foundry\CLI\Commands\PlanListCommand;
use Foundry\CLI\Commands\PlanReplayCommand;
use Foundry\CLI\Commands\PlanShowCommand;
use Foundry\CLI\Commands\PlanUndoCommand;
use Foundry\Generate\PlanRecordStore;
use Foundry\Support\FoundryError;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class PlanCommandsTest extends TestCase
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

    public function test_plan_list_renders_empty_human_message(): void
    {
        $result = (new PlanListCommand())->run(['plan:list'], new CommandContext($this->project->root));

        $this->assertSame(0, $result['status']);
        $this->assertSame('No persisted plans found.', $result['message']);
        $this->assertNull($result['payload']);
    }

    public function test_plan_list_renders_persisted_plan_summaries_in_human_mode(): void
    {
        $this->store('2026-04-23T01:02:03Z')->persist(
            $this->record('11111111-1111-4111-8111-111111111111', 'Create comments', status: 'success'),
        );

        $result = (new PlanListCommand())->run(['plan:list'], new CommandContext($this->project->root));

        $this->assertSame(0, $result['status']);
        $this->assertStringContainsString('Persisted plans:', (string) $result['message']);
        $this->assertStringContainsString(
            '11111111-1111-4111-8111-111111111111 | 2026-04-23T01:02:03Z | success | new | Create comments',
            (string) $result['message'],
        );
        $this->assertNull($result['payload']);
    }

    public function test_plan_show_requires_plan_id(): void
    {
        try {
            (new PlanShowCommand())->run(['plan:show'], new CommandContext($this->project->root));
            self::fail('Expected missing plan id validation failure.');
        } catch (FoundryError $error) {
            $this->assertSame('PLAN_SHOW_ID_REQUIRED', $error->errorCode);
        }
    }

    public function test_plan_replay_requires_plan_id(): void
    {
        try {
            (new PlanReplayCommand())->run(['plan:replay'], new CommandContext($this->project->root));
            self::fail('Expected missing plan id validation failure.');
        } catch (FoundryError $error) {
            $this->assertSame('PLAN_REPLAY_ID_REQUIRED', $error->errorCode);
        }
    }

    public function test_plan_undo_requires_plan_id(): void
    {
        try {
            (new PlanUndoCommand())->run(['plan:undo'], new CommandContext($this->project->root));
            self::fail('Expected missing plan id validation failure.');
        } catch (FoundryError $error) {
            $this->assertSame('PLAN_UNDO_ID_REQUIRED', $error->errorCode);
        }
    }

    public function test_plan_show_reports_missing_record(): void
    {
        try {
            (new PlanShowCommand())->run(
                ['plan:show', '11111111-1111-4111-8111-111111111111'],
                new CommandContext($this->project->root),
            );
            self::fail('Expected missing plan record failure.');
        } catch (FoundryError $error) {
            $this->assertSame('PLAN_RECORD_NOT_FOUND', $error->errorCode);
        }
    }

    public function test_plan_show_renders_human_readable_record_details(): void
    {
        $this->store('2026-04-23T01:02:03Z')->persist(
            $this->record(
                '11111111-1111-4111-8111-111111111111',
                'Create comments',
                status: 'aborted',
                riskLevel: 'HIGH',
                affectedFiles: ['app/features/comments/feature.yaml'],
                actionsExecuted: [['type' => 'write_file']],
            ),
        );

        $result = (new PlanShowCommand())->run(
            ['plan:show', '11111111-1111-4111-8111-111111111111'],
            new CommandContext($this->project->root),
        );

        $this->assertSame(0, $result['status']);
        $this->assertStringContainsString('Plan: 11111111-1111-4111-8111-111111111111', (string) $result['message']);
        $this->assertStringContainsString('Status: aborted', (string) $result['message']);
        $this->assertStringContainsString('Risk: HIGH', (string) $result['message']);
        $this->assertStringContainsString('Affected files: 1', (string) $result['message']);
        $this->assertStringContainsString('Executed actions: 1', (string) $result['message']);
        $this->assertStringContainsString('"intent": "Create comments"', (string) $result['message']);
        $this->assertNull($result['payload']);
    }

    public function test_plan_replay_renders_human_readable_dry_run_with_drift_notices(): void
    {
        $this->store('2026-04-23T01:02:03Z')->persist(
            $this->record(
                '11111111-1111-4111-8111-111111111111',
                'Create comments',
                planOriginal: $this->replayPlan(),
            ),
        );

        $result = (new PlanReplayCommand())->run(
            ['plan:replay', '11111111-1111-4111-8111-111111111111', '--dry-run'],
            new CommandContext($this->project->root),
        );

        $this->assertSame(0, $result['status']);
        $this->assertStringContainsString('Replay dry run prepared.', (string) $result['message']);
        $this->assertStringContainsString('Replay mode: adaptive', (string) $result['message']);
        $this->assertStringContainsString('Drift detected: yes', (string) $result['message']);
        $this->assertStringContainsString('Verification: skipped', (string) $result['message']);
        $this->assertStringContainsString('Replay source: original', (string) $result['message']);
        $this->assertStringContainsString('Drift notices:', (string) $result['message']);
        $this->assertNull($result['payload']);
    }

    public function test_plan_replay_uses_approved_final_plan_when_present(): void
    {
        $this->store('2026-04-23T01:02:03Z')->persist(
            $this->record(
                '11111111-1111-4111-8111-111111111111',
                'Create comments',
                planOriginal: $this->replayPlan(marker: 'original'),
                planFinal: $this->replayPlan(marker: 'final'),
                interactive: [
                    'enabled' => true,
                    'approved' => true,
                    'rejected' => false,
                    'modified' => true,
                    'allow_risky' => false,
                    'preview' => ['summary' => [], 'actions' => [], 'diffs' => []],
                    'risk' => ['level' => 'LOW', 'reasons' => [], 'risky_action_indexes' => [], 'risky_paths' => []],
                ],
            ),
        );

        $result = (new PlanReplayCommand())->run(
            ['plan:replay', '11111111-1111-4111-8111-111111111111', '--dry-run'],
            new CommandContext($this->project->root, true),
        );

        $this->assertSame(0, $result['status']);
        $this->assertSame('final', $result['payload']['source_record']['selected_plan']);
        $this->assertSame('final', $result['payload']['plan']['metadata']['marker']);
    }

    public function test_plan_replay_can_reconstruct_fallback_intent_for_risky_delete_records(): void
    {
        $this->store('2026-04-23T01:02:03Z')->persist(
            $this->record(
                '11111111-1111-4111-8111-111111111111',
                'Delete comments',
                mode: 'modify',
                targets: [[
                    'requested' => 'comments',
                    'resolved' => 'feature:comments',
                ]],
                planOriginal: $this->replayPlan(
                    marker: 'delete',
                    actions: [[
                        'type' => 'delete_file',
                        'path' => 'app/features/comments/feature.yaml',
                        'summary' => 'Delete file.',
                        'explain_node_id' => 'feature:comments',
                    ]],
                ),
                interactive: null,
                metadataOverrides: [
                    'requested_intent' => null,
                    'source_hash' => '',
                ],
            ),
        );

        $result = (new PlanReplayCommand())->run(
            ['plan:replay', '11111111-1111-4111-8111-111111111111', '--dry-run'],
            new CommandContext($this->project->root, true),
        );

        $this->assertSame(0, $result['status']);
        $this->assertTrue($result['payload']['drift_detected']);
        $this->assertContains(
            'missing_delete_target',
            array_values(array_map(
                static fn(array $item): string => (string) ($item['code'] ?? ''),
                $result['payload']['drift_summary']['items'],
            )),
        );
    }

    public function test_plan_undo_renders_human_readable_nothing_to_undo_message(): void
    {
        $this->store('2026-04-23T01:02:03Z')->persist(
            $this->record(
                '11111111-1111-4111-8111-111111111111',
                'Create comments',
            ),
        );

        $result = (new PlanUndoCommand())->run(
            ['plan:undo', '11111111-1111-4111-8111-111111111111'],
            new CommandContext($this->project->root),
        );

        $this->assertSame(0, $result['status']);
        $this->assertStringContainsString('No applied generate changes to undo.', (string) $result['message']);
        $this->assertStringContainsString('Status: nothing_to_undo', (string) $result['message']);
        $this->assertNull($result['payload']);
    }

    private function store(string $timestamp): PlanRecordStore
    {
        return new PlanRecordStore(
            new Paths($this->project->root),
            static fn(): \DateTimeImmutable => new \DateTimeImmutable($timestamp),
        );
    }

    /**
     * @param list<string> $affectedFiles
     * @param list<array<string,mixed>> $actionsExecuted
     * @param list<array<string,mixed>> $targets
     * @return array<string,mixed>
     */
    private function record(
        string $planId,
        string $intent,
        string $status = 'success',
        string $riskLevel = 'LOW',
        string $mode = 'new',
        array $targets = [],
        array $affectedFiles = [],
        array $actionsExecuted = [],
        ?array $planOriginal = null,
        ?array $planFinal = null,
        ?array $interactive = null,
        array $metadataOverrides = [],
    ): array {
        $metadata = [
            'framework_version' => '0.1.0',
            'graph_version' => 1,
            'source_hash' => 'abc123',
            'requested_intent' => [
                'raw' => $intent,
                'mode' => $mode,
                'target' => $targets[0]['requested'] ?? null,
                'interactive' => false,
                'dry_run' => true,
                'skip_verify' => false,
                'explain' => false,
                'allow_risky' => false,
                'allow_dirty' => false,
                'allow_pack_install' => false,
                'git_commit' => false,
                'git_commit_message' => null,
                'packs' => [],
            ],
            'dry_run' => true,
            'interactive_requested' => false,
            'plan_origin' => 'core',
            'generator_id' => 'generate comments',
            'safety_routing' => null,
        ];

        foreach ($metadataOverrides as $key => $value) {
            if ($value === null) {
                unset($metadata[$key]);

                continue;
            }

            $metadata[$key] = $value;
        }

        return [
            'plan_id' => $planId,
            'intent' => $intent,
            'mode' => $mode,
            'targets' => $targets,
            'generation_context_packet' => null,
            'plan_original' => $planOriginal,
            'plan_final' => $planFinal,
            'interactive' => $interactive ?? ['enabled' => true, 'rejected' => $status === 'aborted'],
            'user_decisions' => [],
            'actions_executed' => $actionsExecuted,
            'affected_files' => $affectedFiles,
            'risk_level' => $riskLevel,
            'verification_results' => ['skipped' => true, 'ok' => true],
            'status' => $status,
            'error' => null,
            'undo' => [
                'file_snapshots_before' => [[
                    'path' => 'app/features/comments/feature.yaml',
                    'exists' => false,
                    'content' => null,
                    'hash' => null,
                ]],
                'file_snapshots_after' => [[
                    'path' => 'app/features/comments/feature.yaml',
                    'exists' => true,
                    'content' => "feature: comments\n",
                    'hash' => hash('sha256', "feature: comments\n"),
                ]],
                'patches' => [[
                    'path' => 'app/features/comments/feature.yaml',
                    'format' => 'unified_diff',
                    'before_exists' => false,
                    'after_exists' => true,
                    'before_hash' => null,
                    'after_hash' => hash('sha256', "feature: comments\n"),
                    'patch' => "--- /dev/null\n+++ b/app/features/comments/feature.yaml\n@@ -1,0 +1,1 @@\n+feature: comments\n",
                ]],
            ],
            'metadata' => $metadata,
        ];
    }

    /**
     * @param list<array<string,mixed>>|null $actions
     * @return array<string,mixed>
     */
    private function replayPlan(string $marker = 'original', ?array $actions = null): array
    {
        $actions ??= [[
            'type' => 'create_file',
            'path' => 'app/features/comments/feature.yaml',
            'summary' => 'Create feature scaffold.',
            'explain_node_id' => 'feature:comments',
        ]];

        return [
            'actions' => $actions,
            'affected_files' => array_values(array_map(
                static fn(array $action): string => (string) ($action['path'] ?? ''),
                $actions,
            )),
            'risks' => [],
            'validations' => ['compile_graph'],
            'origin' => 'core',
            'generator_id' => 'core.feature.' . $marker,
            'extension' => null,
            'confidence' => ['band' => 'high', 'score' => 0.91],
            'metadata' => ['marker' => $marker],
        ];
    }
}
