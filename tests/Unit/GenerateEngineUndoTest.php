<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Generate\GenerateEngine;
use Foundry\Generate\GenerateUnifiedDiffRenderer;
use Foundry\Generate\PlanRecordStore;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class GenerateEngineUndoTest extends TestCase
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

    public function test_snapshot_based_undo_restores_updated_files(): void
    {
        $path = $this->project->root . '/notes.txt';
        file_put_contents($path, "after\n");

        $this->store()->persist($this->record(
            planId: '11111111-1111-4111-8111-111111111111',
            path: 'notes.txt',
            type: 'update_file',
            status: 'written',
            undo: $this->undoPayload('notes.txt', "before\n", "after\n", includePatch: false),
        ));

        $result = $this->engine()->undo('11111111-1111-4111-8111-111111111111');

        $this->assertSame('undone', $result['status']);
        $this->assertSame('snapshot', $result['rollback_mode']);
        $this->assertSame('snapshot', $result['reversed_actions'][0]['rollback_mode']);
        $this->assertSame([], $result['integrity_warnings']);
        $this->assertSame("before\n", (string) file_get_contents($path));
    }

    public function test_patch_based_undo_restores_updated_files(): void
    {
        $path = $this->project->root . '/notes.txt';
        file_put_contents($path, "after\n");

        $undo = $this->undoPayload('notes.txt', "before\n", "after\n", includePatch: true);
        $undo['file_snapshots_before'] = [];

        $this->store()->persist($this->record(
            planId: '11111111-1111-4111-8111-111111111112',
            path: 'notes.txt',
            type: 'update_file',
            status: 'written',
            undo: $undo,
        ));

        $result = $this->engine()->undo('11111111-1111-4111-8111-111111111112');

        $this->assertSame('undone', $result['status']);
        $this->assertSame('patch', $result['rollback_mode']);
        $this->assertSame('patch', $result['reversed_actions'][0]['rollback_mode']);
        $this->assertSame("before\n", (string) file_get_contents($path));
    }

    public function test_undo_restores_deleted_files_when_snapshot_exists(): void
    {
        $this->store()->persist($this->record(
            planId: '11111111-1111-4111-8111-111111111113',
            path: 'deleted.txt',
            type: 'delete_file',
            status: 'deleted',
            undo: $this->undoPayload('deleted.txt', "before delete\n", null, includePatch: false),
        ));

        $result = $this->engine()->undo('11111111-1111-4111-8111-111111111113');

        $this->assertSame('undone', $result['status']);
        $this->assertSame('deleted.txt', $result['files_recovered'][0]);
        $this->assertSame("before delete\n", (string) file_get_contents($this->project->root . '/deleted.txt'));
    }

    public function test_undo_reports_integrity_mismatch_before_applying(): void
    {
        $path = $this->project->root . '/notes.txt';
        file_put_contents($path, "manually changed\n");

        $this->store()->persist($this->record(
            planId: '11111111-1111-4111-8111-111111111114',
            path: 'notes.txt',
            type: 'update_file',
            status: 'written',
            undo: $this->undoPayload('notes.txt', "before\n", "after\n", includePatch: true),
        ));

        $result = $this->engine()->undo('11111111-1111-4111-8111-111111111114', true);

        $this->assertSame('dry_run', $result['status']);
        $this->assertSame('low', $result['confidence_level']);
        $this->assertSame([], $result['reversible_actions']);
        $this->assertSame('integrity_mismatch', $result['skipped_actions'][0]['reason']);
        $this->assertNotEmpty($result['integrity_warnings']);
    }

    public function test_undo_dry_run_is_deterministic_for_patch_backed_records(): void
    {
        $path = $this->project->root . '/notes.txt';
        file_put_contents($path, "after\n");

        $undo = $this->undoPayload('notes.txt', "before\n", "after\n", includePatch: true);
        $undo['file_snapshots_before'] = [];

        $this->store()->persist($this->record(
            planId: '11111111-1111-4111-8111-111111111115',
            path: 'notes.txt',
            type: 'update_file',
            status: 'written',
            undo: $undo,
        ));

        $first = $this->engine()->undo('11111111-1111-4111-8111-111111111115', true);
        $second = $this->engine()->undo('11111111-1111-4111-8111-111111111115', true);

        $this->assertSame($first, $second);
    }

    private function engine(): GenerateEngine
    {
        return new GenerateEngine(new Paths($this->project->root));
    }

    private function store(): PlanRecordStore
    {
        return new PlanRecordStore(
            new Paths($this->project->root),
            static fn(): \DateTimeImmutable => new \DateTimeImmutable('2026-04-23T01:02:03Z'),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function record(string $planId, string $path, string $type, string $status, array $undo): array
    {
        return [
            'plan_id' => $planId,
            'intent' => 'Undo fixture',
            'mode' => 'modify',
            'targets' => [],
            'generation_context_packet' => null,
            'plan_original' => [
                'actions' => [[
                    'type' => $type,
                    'path' => $path,
                    'summary' => 'Undo fixture action.',
                    'explain_node_id' => 'feature:undo_fixture',
                ]],
                'affected_files' => [$path],
                'risks' => [],
                'validations' => ['compile_graph'],
                'origin' => 'core',
                'generator_id' => 'core.feature.undo-fixture',
                'extension' => null,
                'confidence' => ['band' => 'high', 'score' => 0.91],
                'metadata' => [],
            ],
            'plan_final' => null,
            'interactive' => null,
            'user_decisions' => [],
            'actions_executed' => [[
                'type' => $type,
                'path' => $path,
                'status' => $status,
                'origin' => 'core',
                'extension' => null,
            ]],
            'affected_files' => [$path],
            'risk_level' => 'LOW',
            'verification_results' => ['skipped' => true, 'ok' => true],
            'status' => 'success',
            'error' => null,
            'undo' => $undo,
            'metadata' => [
                'framework_version' => '0.1.0',
                'graph_version' => 1,
                'source_hash' => 'abc123',
                'requested_intent' => [
                    'raw' => 'Undo fixture',
                    'mode' => 'modify',
                    'target' => null,
                    'interactive' => false,
                    'dry_run' => false,
                    'skip_verify' => false,
                    'explain' => false,
                    'allow_risky' => false,
                    'allow_dirty' => false,
                    'allow_pack_install' => false,
                    'git_commit' => false,
                    'git_commit_message' => null,
                    'packs' => [],
                ],
                'dry_run' => false,
                'interactive_requested' => false,
                'plan_origin' => 'core',
                'generator_id' => 'core.feature.undo-fixture',
                'safety_routing' => null,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function undoPayload(string $path, ?string $beforeContent, ?string $afterContent, bool $includePatch): array
    {
        $payload = [
            'file_snapshots_before' => [[
                'path' => $path,
                'exists' => $beforeContent !== null,
                'content' => $beforeContent,
                'hash' => $beforeContent !== null ? hash('sha256', $beforeContent) : null,
            ]],
            'file_snapshots_after' => [[
                'path' => $path,
                'exists' => $afterContent !== null,
                'content' => $afterContent,
                'hash' => $afterContent !== null ? hash('sha256', $afterContent) : null,
            ]],
            'patches' => [],
        ];

        if ($includePatch) {
            $payload['patches'][] = [
                'path' => $path,
                'format' => 'unified_diff',
                'before_exists' => $beforeContent !== null,
                'after_exists' => $afterContent !== null,
                'before_hash' => $beforeContent !== null ? hash('sha256', $beforeContent) : null,
                'after_hash' => $afterContent !== null ? hash('sha256', $afterContent) : null,
                'patch' => (new GenerateUnifiedDiffRenderer())->render($path, $beforeContent, $afterContent),
            ];
        }

        return $payload;
    }
}
