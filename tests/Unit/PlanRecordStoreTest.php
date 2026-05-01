<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Generate\PlanRecordStore;
use Foundry\Support\FoundryError;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class PlanRecordStoreTest extends TestCase
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

    public function test_persist_writes_repository_local_plan_record_with_integrity_hash(): void
    {
        $store = $this->store('2026-04-23T01:02:03Z');

        $record = $store->persist($this->record('11111111-1111-4111-8111-111111111111', 'Create comments'));

        $this->assertSame(3, $record['storage_version']);
        $this->assertSame('2026-04-23T01:02:03Z', $record['timestamp']);
        $this->assertSame(
            '.foundry/plans/20260423T010203Z_11111111-1111-4111-8111-111111111111.json',
            $record['storage_path'],
        );
        $this->assertSame(3, $record['metadata']['storage_version']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $record['metadata']['integrity_hash']);
        $this->assertSame('app/features/comments/feature.yaml', $record['undo']['file_snapshots_before'][0]['path']);
        $this->assertSame('app/features/comments/feature.yaml', $record['undo']['file_snapshots_after'][0]['path']);
        $this->assertSame('app/features/comments/feature.yaml', $record['undo']['patches'][0]['path']);
        $this->assertFileExists($this->project->root . '/' . $record['storage_path']);
    }

    public function test_list_returns_deterministic_summaries_in_reverse_timestamp_order(): void
    {
        $this->store('2026-04-23T01:02:03Z')
            ->persist($this->record('11111111-1111-4111-8111-111111111111', 'Create comments'));
        $this->store('2026-04-23T01:02:04Z')
            ->persist($this->record('22222222-2222-4222-8222-222222222222', 'Create bookmarks', status: 'aborted'));

        $summaries = $this->store('2026-04-23T01:02:05Z')->list();

        $this->assertSame([
            '22222222-2222-4222-8222-222222222222',
            '11111111-1111-4111-8111-111111111111',
        ], array_column($summaries, 'plan_id'));
        $this->assertSame(['aborted', 'success'], array_column($summaries, 'status'));
    }

    public function test_list_distinguishes_workflow_parent_and_step_records(): void
    {
        $this->store('2026-04-23T01:02:03Z')->persist($this->workflowStepRecord(
            planId: '11111111-1111-4111-8111-111111111111',
            workflowId: 'workflow-abc',
            stepId: 'create_comments',
            stepIndex: 0,
        ));
        $this->store('2026-04-23T01:02:04Z')->persist($this->workflowRecord(
            planId: '22222222-2222-4222-8222-222222222222',
            workflowId: 'workflow-abc',
            stepRecordId: '11111111-1111-4111-8111-111111111111',
        ));

        $summaries = $this->store('2026-04-23T01:02:05Z')->list();

        $this->assertSame(['workflow', 'workflow_step'], array_column($summaries, 'record_kind'));
        $this->assertSame(['workflow-abc', 'workflow-abc'], array_column($summaries, 'workflow_id'));
        $this->assertSame([null, 'create_comments'], array_column($summaries, 'workflow_step_id'));
    }

    public function test_list_includes_template_id_when_present(): void
    {
        $record = $this->record('11111111-1111-4111-8111-111111111111', 'Create comments');
        $record['metadata']['template'] = [
            'template_id' => 'feature.recipe',
            'path' => '.foundry/templates/single.json',
            'resolved_parameters' => ['name' => 'comments'],
        ];

        $this->store('2026-04-23T01:02:03Z')->persist($record);

        $summaries = $this->store('2026-04-23T01:02:04Z')->list();

        $this->assertSame('feature.recipe', $summaries[0]['template_id']);
    }

    public function test_load_resolves_plan_by_primary_plan_id(): void
    {
        $store = $this->store('2026-04-23T01:02:03Z');
        $store->persist($this->record('11111111-1111-4111-8111-111111111111', 'Create comments'));

        $loaded = $store->load('11111111-1111-4111-8111-111111111111');

        $this->assertIsArray($loaded);
        $this->assertSame('Create comments', $loaded['intent']);
        $this->assertSame('success', $loaded['status']);
    }

    public function test_persist_requires_plan_id(): void
    {
        $store = $this->store('2026-04-23T01:02:03Z');

        try {
            $store->persist($this->record('', 'Create comments'));
            self::fail('Expected plan id validation failure.');
        } catch (FoundryError $error) {
            $this->assertSame('PLAN_RECORD_ID_REQUIRED', $error->errorCode);
        }
    }

    public function test_persist_throws_when_target_path_cannot_be_written(): void
    {
        $store = $this->store('2026-04-23T01:02:03Z');
        $record = $this->record('11111111-1111-4111-8111-111111111111', 'Create comments');
        $target = $this->project->root . '/.foundry/plans/20260423T010203Z_11111111-1111-4111-8111-111111111111.json';

        mkdir(dirname($target), 0777, true);
        mkdir($target, 0777, true);

        try {
            $store->persist($record);
            self::fail('Expected plan record write failure.');
        } catch (FoundryError $error) {
            $this->assertSame('PLAN_RECORD_WRITE_FAILED', $error->errorCode);
        }
    }

    public function test_persist_rejects_workflow_record_with_unknown_schema(): void
    {
        try {
            $this->store('2026-04-23T01:02:03Z')->persist([
                'plan_id' => '11111111-1111-4111-8111-111111111111',
                'mode' => 'workflow',
                'schema' => 'foundry.generate.workflow_record.v0',
                'workflow_id' => 'workflow-abc',
                'source' => ['type' => 'repository_file', 'path' => 'generate-workflow.json'],
                'steps' => [],
                'shared_context' => [],
                'result' => ['completed_steps' => 0, 'failed_step' => null, 'skipped_steps' => 0],
                'rollback_guidance' => [],
                'status' => 'completed',
                'metadata' => [],
            ]);
            self::fail('Expected invalid workflow schema failure.');
        } catch (FoundryError $error) {
            $this->assertSame('PLAN_RECORD_WORKFLOW_SCHEMA_INVALID', $error->errorCode);
        }
    }

    public function test_load_returns_null_when_plan_id_is_missing(): void
    {
        $store = $this->store('2026-04-23T01:02:03Z');

        $this->assertNull($store->load('11111111-1111-4111-8111-111111111111'));
    }

    public function test_load_throws_when_multiple_records_share_plan_id(): void
    {
        mkdir($this->project->root . '/.foundry/plans', 0777, true);

        file_put_contents(
            $this->project->root . '/.foundry/plans/20260423T010203Z_duplicate-a.json',
            json_encode($this->record('11111111-1111-4111-8111-111111111111', 'Create comments'), JSON_THROW_ON_ERROR),
        );
        file_put_contents(
            $this->project->root . '/.foundry/plans/20260423T010204Z_duplicate-b.json',
            json_encode($this->record('11111111-1111-4111-8111-111111111111', 'Create comments again'), JSON_THROW_ON_ERROR),
        );

        try {
            $this->store('2026-04-23T01:02:05Z')->load('11111111-1111-4111-8111-111111111111');
            self::fail('Expected duplicate plan id failure.');
        } catch (FoundryError $error) {
            $this->assertSame('PLAN_RECORD_DUPLICATE_ID', $error->errorCode);
        }
    }

    public function test_list_throws_when_record_file_is_unreadable(): void
    {
        mkdir($this->project->root . '/.foundry/plans', 0777, true);
        file_put_contents($this->project->root . '/.foundry/plans/20260423T010203Z_invalid.json', '');

        try {
            $this->store('2026-04-23T01:02:05Z')->list();
            self::fail('Expected unreadable plan record failure.');
        } catch (FoundryError $error) {
            $this->assertSame('PLAN_RECORD_UNREADABLE', $error->errorCode);
        }
    }

    public function test_list_throws_when_record_file_is_missing_plan_id(): void
    {
        mkdir($this->project->root . '/.foundry/plans', 0777, true);
        file_put_contents(
            $this->project->root . '/.foundry/plans/20260423T010203Z_invalid.json',
            json_encode(['status' => 'success'], JSON_THROW_ON_ERROR),
        );

        try {
            $this->store('2026-04-23T01:02:05Z')->list();
            self::fail('Expected invalid plan record failure.');
        } catch (FoundryError $error) {
            $this->assertSame('PLAN_RECORD_INVALID', $error->errorCode);
        }
    }

    public function test_persist_throws_when_plan_directory_cannot_be_created(): void
    {
        $foundryPath = $this->project->root . '/.foundry';
        if (is_dir($foundryPath)) {
            rmdir($foundryPath);
        }
        file_put_contents($foundryPath, 'not-a-directory');

        try {
            $this->store('2026-04-23T01:02:03Z')
                ->persist($this->record('11111111-1111-4111-8111-111111111111', 'Create comments'));
            self::fail('Expected plan directory creation failure.');
        } catch (FoundryError $error) {
            $this->assertSame('PLAN_RECORD_DIRECTORY_CREATE_FAILED', $error->errorCode);
        }
    }

    public function test_list_throws_when_workflow_step_record_references_missing_parent(): void
    {
        $this->store('2026-04-23T01:02:03Z')->persist($this->workflowStepRecord(
            planId: '11111111-1111-4111-8111-111111111111',
            workflowId: 'workflow-abc',
            stepId: 'create_comments',
            stepIndex: 0,
        ));

        try {
            $this->store('2026-04-23T01:02:04Z')->list();
            self::fail('Expected missing workflow parent failure.');
        } catch (FoundryError $error) {
            $this->assertSame('PLAN_RECORD_WORKFLOW_PARENT_MISSING', $error->errorCode);
        }
    }

    public function test_list_throws_when_workflow_step_record_disagrees_with_parent_step_metadata(): void
    {
        $this->store('2026-04-23T01:02:03Z')->persist($this->workflowStepRecord(
            planId: '11111111-1111-4111-8111-111111111111',
            workflowId: 'workflow-abc',
            stepId: 'create_comments',
            stepIndex: 1,
        ));
        $this->store('2026-04-23T01:02:04Z')->persist($this->workflowRecord(
            planId: '22222222-2222-4222-8222-222222222222',
            workflowId: 'workflow-abc',
            stepRecordId: '11111111-1111-4111-8111-111111111111',
        ));

        try {
            $this->store('2026-04-23T01:02:05Z')->list();
            self::fail('Expected workflow step mismatch failure.');
        } catch (FoundryError $error) {
            $this->assertSame('PLAN_RECORD_WORKFLOW_STEP_MISMATCH', $error->errorCode);
        }
    }

    public function test_list_throws_when_workflow_parent_references_missing_step_record(): void
    {
        $this->store('2026-04-23T01:02:03Z')->persist($this->workflowRecord(
            planId: '22222222-2222-4222-8222-222222222222',
            workflowId: 'workflow-abc',
            stepRecordId: '11111111-1111-4111-8111-111111111111',
        ));

        try {
            $this->store('2026-04-23T01:02:04Z')->list();
            self::fail('Expected missing workflow step record failure.');
        } catch (FoundryError $error) {
            $this->assertSame('PLAN_RECORD_WORKFLOW_STEP_RECORD_MISSING', $error->errorCode);
        }
    }

    public function test_list_throws_when_workflow_record_is_inconsistent(): void
    {
        $workflow = $this->workflowRecord(
            planId: '22222222-2222-4222-8222-222222222222',
            workflowId: 'workflow-abc',
            stepRecordId: '11111111-1111-4111-8111-111111111111',
            status: 'completed',
        );
        $workflow['steps'][0]['status'] = 'skipped';
        $workflow['steps'][0]['record_id'] = null;
        $workflow['result']['completed_steps'] = 0;
        $workflow['result']['skipped_steps'] = 1;
        mkdir($this->project->root . '/.foundry/plans', 0777, true);
        file_put_contents(
            $this->project->root . '/.foundry/plans/20260423T010203Z_invalid-workflow.json',
            json_encode($workflow, JSON_THROW_ON_ERROR),
        );

        try {
            $this->store('2026-04-23T01:02:04Z')->list();
            self::fail('Expected workflow status validation failure.');
        } catch (FoundryError $error) {
            $this->assertSame('PLAN_RECORD_WORKFLOW_STATUS_INVALID', $error->errorCode);
        }
    }

    public function test_list_throws_when_workflow_record_has_invalid_source(): void
    {
        $workflow = $this->workflowRecord(
            planId: '22222222-2222-4222-8222-222222222222',
            workflowId: 'workflow-abc',
            stepRecordId: '11111111-1111-4111-8111-111111111111',
        );
        $workflow['source'] = ['type' => 'external', 'path' => '/tmp/workflow.json'];
        $this->writeRawRecord('invalid-source', $workflow);

        try {
            $this->store('2026-04-23T01:02:04Z')->list();
            self::fail('Expected workflow source validation failure.');
        } catch (FoundryError $error) {
            $this->assertSame('PLAN_RECORD_WORKFLOW_SOURCE_INVALID', $error->errorCode);
        }
    }

    public function test_list_throws_when_workflow_record_source_path_is_absolute(): void
    {
        $workflow = $this->workflowRecord(
            planId: '22222222-2222-4222-8222-222222222222',
            workflowId: 'workflow-abc',
            stepRecordId: '11111111-1111-4111-8111-111111111111',
        );
        $workflow['source'] = ['type' => 'repository_file', 'path' => '/tmp/workflow.json'];
        $this->writeRawRecord('absolute-source-path', $workflow);

        try {
            $this->store('2026-04-23T01:02:04Z')->list();
            self::fail('Expected absolute workflow source path failure.');
        } catch (FoundryError $error) {
            $this->assertSame('PLAN_RECORD_WORKFLOW_SOURCE_INVALID', $error->errorCode);
        }
    }

    public function test_list_throws_when_workflow_record_has_non_null_timestamps(): void
    {
        $workflow = $this->workflowRecord(
            planId: '22222222-2222-4222-8222-222222222222',
            workflowId: 'workflow-abc',
            stepRecordId: '11111111-1111-4111-8111-111111111111',
        );
        $workflow['started_at'] = '2026-04-23T01:02:03Z';
        $this->writeRawRecord('invalid-timestamps', $workflow);

        try {
            $this->store('2026-04-23T01:02:04Z')->list();
            self::fail('Expected workflow timestamp validation failure.');
        } catch (FoundryError $error) {
            $this->assertSame('PLAN_RECORD_WORKFLOW_TIMESTAMP_INVALID', $error->errorCode);
        }
    }

    public function test_list_throws_when_workflow_result_completed_steps_do_not_match(): void
    {
        $workflow = $this->workflowRecord(
            planId: '22222222-2222-4222-8222-222222222222',
            workflowId: 'workflow-abc',
            stepRecordId: '11111111-1111-4111-8111-111111111111',
        );
        $workflow['result']['completed_steps'] = 0;
        $this->writeRawRecord('invalid-completed-steps', $workflow);

        try {
            $this->store('2026-04-23T01:02:04Z')->list();
            self::fail('Expected workflow completed_steps validation failure.');
        } catch (FoundryError $error) {
            $this->assertSame('PLAN_RECORD_WORKFLOW_RESULT_INVALID', $error->errorCode);
        }
    }

    public function test_list_throws_when_workflow_result_skipped_steps_do_not_match(): void
    {
        $workflow = $this->workflowRecord(
            planId: '22222222-2222-4222-8222-222222222222',
            workflowId: 'workflow-abc',
            stepRecordId: '11111111-1111-4111-8111-111111111111',
        );
        $workflow['result']['skipped_steps'] = 1;
        $this->writeRawRecord('invalid-skipped-steps', $workflow);

        try {
            $this->store('2026-04-23T01:02:04Z')->list();
            self::fail('Expected workflow skipped_steps validation failure.');
        } catch (FoundryError $error) {
            $this->assertSame('PLAN_RECORD_WORKFLOW_RESULT_INVALID', $error->errorCode);
        }
    }

    public function test_list_throws_when_failed_workflow_has_no_failed_step_result(): void
    {
        $workflow = $this->workflowRecord(
            planId: '22222222-2222-4222-8222-222222222222',
            workflowId: 'workflow-abc',
            stepRecordId: '11111111-1111-4111-8111-111111111111',
            status: 'failed',
        );
        $workflow['result']['failed_step'] = null;
        $this->writeRawRecord('missing-failed-step', $workflow);

        try {
            $this->store('2026-04-23T01:02:04Z')->list();
            self::fail('Expected workflow failed_step validation failure.');
        } catch (FoundryError $error) {
            $this->assertSame('PLAN_RECORD_WORKFLOW_STATUS_INVALID', $error->errorCode);
        }
    }

    public function test_list_throws_when_failed_workflow_result_references_non_failed_step(): void
    {
        $workflow = $this->workflowRecord(
            planId: '22222222-2222-4222-8222-222222222222',
            workflowId: 'workflow-abc',
            stepRecordId: '11111111-1111-4111-8111-111111111111',
            status: 'failed',
        );
        $workflow['result']['failed_step'] = 'different_step';
        $this->writeRawRecord('mismatched-failed-step', $workflow);

        try {
            $this->store('2026-04-23T01:02:04Z')->list();
            self::fail('Expected workflow failed_step mismatch failure.');
        } catch (FoundryError $error) {
            $this->assertSame('PLAN_RECORD_WORKFLOW_RESULT_INVALID', $error->errorCode);
        }
    }

    public function test_list_throws_when_workflow_step_is_missing_required_fields(): void
    {
        $workflow = $this->workflowRecord(
            planId: '22222222-2222-4222-8222-222222222222',
            workflowId: 'workflow-abc',
            stepRecordId: '11111111-1111-4111-8111-111111111111',
        );
        unset($workflow['steps'][0]['dependencies']);
        $this->writeRawRecord('missing-step-fields', $workflow);

        try {
            $this->store('2026-04-23T01:02:04Z')->list();
            self::fail('Expected missing workflow step field failure.');
        } catch (FoundryError $error) {
            $this->assertSame('PLAN_RECORD_WORKFLOW_STEP_INVALID', $error->errorCode);
        }
    }

    public function test_list_throws_when_workflow_step_indexes_are_out_of_order(): void
    {
        $workflow = $this->workflowRecord(
            planId: '22222222-2222-4222-8222-222222222222',
            workflowId: 'workflow-abc',
            stepRecordId: '11111111-1111-4111-8111-111111111111',
        );
        $workflow['steps'][0]['index'] = 1;
        $this->writeRawRecord('out-of-order-step-index', $workflow);

        try {
            $this->store('2026-04-23T01:02:04Z')->list();
            self::fail('Expected workflow step index validation failure.');
        } catch (FoundryError $error) {
            $this->assertSame('PLAN_RECORD_WORKFLOW_STEP_INDEX_INVALID', $error->errorCode);
        }
    }

    public function test_list_throws_when_workflow_step_status_is_invalid(): void
    {
        $workflow = $this->workflowRecord(
            planId: '22222222-2222-4222-8222-222222222222',
            workflowId: 'workflow-abc',
            stepRecordId: '11111111-1111-4111-8111-111111111111',
        );
        $workflow['steps'][0]['status'] = 'running';
        $this->writeRawRecord('invalid-step-status', $workflow);

        try {
            $this->store('2026-04-23T01:02:04Z')->list();
            self::fail('Expected workflow step status validation failure.');
        } catch (FoundryError $error) {
            $this->assertSame('PLAN_RECORD_WORKFLOW_STEP_INVALID', $error->errorCode);
        }
    }

    public function test_list_throws_when_workflow_step_dependencies_are_not_deterministic(): void
    {
        $workflow = $this->workflowRecord(
            planId: '22222222-2222-4222-8222-222222222222',
            workflowId: 'workflow-abc',
            stepRecordId: '11111111-1111-4111-8111-111111111111',
        );
        $workflow['steps'][0]['dependencies'] = ['zeta', 'alpha', 'alpha'];
        $this->writeRawRecord('non-deterministic-dependencies', $workflow);

        try {
            $this->store('2026-04-23T01:02:04Z')->list();
            self::fail('Expected workflow dependency ordering validation failure.');
        } catch (FoundryError $error) {
            $this->assertSame('PLAN_RECORD_WORKFLOW_STEP_INVALID', $error->errorCode);
        }
    }

    public function test_list_throws_when_completed_workflow_step_has_no_record_id(): void
    {
        $workflow = $this->workflowRecord(
            planId: '22222222-2222-4222-8222-222222222222',
            workflowId: 'workflow-abc',
            stepRecordId: '11111111-1111-4111-8111-111111111111',
        );
        $workflow['steps'][0]['record_id'] = null;
        $this->writeRawRecord('completed-step-missing-record-id', $workflow);

        try {
            $this->store('2026-04-23T01:02:04Z')->list();
            self::fail('Expected completed workflow step record id failure.');
        } catch (FoundryError $error) {
            $this->assertSame('PLAN_RECORD_WORKFLOW_STEP_RECORD_REQUIRED', $error->errorCode);
        }
    }

    public function test_list_throws_when_skipped_workflow_step_has_record_id(): void
    {
        $workflow = $this->workflowRecord(
            planId: '22222222-2222-4222-8222-222222222222',
            workflowId: 'workflow-abc',
            stepRecordId: '11111111-1111-4111-8111-111111111111',
        );
        $workflow['steps'][0]['status'] = 'skipped';
        $workflow['result']['completed_steps'] = 0;
        $workflow['result']['skipped_steps'] = 1;
        $this->writeRawRecord('skipped-step-with-record-id', $workflow);

        try {
            $this->store('2026-04-23T01:02:04Z')->list();
            self::fail('Expected skipped workflow step record id failure.');
        } catch (FoundryError $error) {
            $this->assertSame('PLAN_RECORD_WORKFLOW_STEP_RECORD_INVALID', $error->errorCode);
        }
    }

    public function test_list_throws_when_workflow_step_linkage_is_invalid(): void
    {
        $this->store('2026-04-23T01:02:03Z')->persist($this->workflowRecord(
            planId: '22222222-2222-4222-8222-222222222222',
            workflowId: 'workflow-abc',
            stepRecordId: '11111111-1111-4111-8111-111111111111',
        ));
        $record = $this->workflowStepRecord(
            planId: '11111111-1111-4111-8111-111111111111',
            workflowId: 'workflow-abc',
            stepId: 'create_comments',
            stepIndex: 0,
        );
        $record['metadata']['workflow']['step_index'] = 'zero';
        $this->writeRawRecord('invalid-step-linkage', $record);

        try {
            $this->store('2026-04-23T01:02:04Z')->list();
            self::fail('Expected workflow linkage validation failure.');
        } catch (FoundryError $error) {
            $this->assertSame('PLAN_RECORD_WORKFLOW_LINKAGE_INVALID', $error->errorCode);
        }
    }

    private function store(string $timestamp): PlanRecordStore
    {
        return new PlanRecordStore(
            new Paths($this->project->root),
            static fn(): \DateTimeImmutable => new \DateTimeImmutable($timestamp),
        );
    }

    /**
     * @param array<string,mixed> $record
     */
    private function writeRawRecord(string $suffix, array $record): void
    {
        $plansDir = $this->project->root . '/.foundry/plans';
        if (!is_dir($plansDir)) {
            mkdir($plansDir, 0777, true);
        }
        file_put_contents(
            $this->project->root . '/.foundry/plans/20260423T010203Z_' . $suffix . '.json',
            json_encode($record, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function record(string $planId, string $intent, string $status = 'success'): array
    {
        return [
            'plan_id' => $planId,
            'intent' => $intent,
            'mode' => 'new',
            'targets' => [],
            'generation_context_packet' => null,
            'plan_original' => null,
            'plan_final' => null,
            'interactive' => null,
            'user_decisions' => [],
            'actions_executed' => [],
            'affected_files' => [],
            'risk_level' => 'LOW',
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
            'metadata' => [
                'framework_version' => '0.1.0',
                'graph_version' => 1,
                'source_hash' => 'abc123',
                'dry_run' => true,
                'interactive_requested' => false,
                'plan_origin' => 'core',
                'generator_id' => 'generate comments',
                'safety_routing' => null,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function workflowStepRecord(string $planId, string $workflowId, string $stepId, int $stepIndex): array
    {
        $record = $this->record($planId, 'Create comments from workflow');
        $record['metadata']['workflow'] = [
            'workflow_id' => $workflowId,
            'step_id' => $stepId,
            'step_index' => $stepIndex,
            'is_workflow_step' => true,
        ];

        return $record;
    }

    /**
     * @return array<string,mixed>
     */
    private function workflowRecord(
        string $planId,
        string $workflowId,
        string $stepRecordId,
        string $status = 'completed',
    ): array {
        return [
            'plan_id' => $planId,
            'schema' => 'foundry.generate.workflow_record.v1',
            'workflow_id' => $workflowId,
            'source' => [
                'type' => 'repository_file',
                'path' => 'generate-workflow.json',
            ],
            'started_at' => null,
            'completed_at' => null,
            'steps' => [[
                'step_id' => 'create_comments',
                'index' => 0,
                'status' => $status === 'completed' ? 'completed' : 'failed',
                'record_id' => $stepRecordId,
                'dependencies' => [],
                'failure' => $status === 'completed'
                    ? null
                    : ['code' => 'GENERATE_FAILED', 'message' => 'Workflow step failed.'],
            ]],
            'shared_context' => [
                'shared' => ['resource' => 'comments'],
                'steps' => [
                    'create_comments' => ['feature' => 'comments'],
                ],
                'workflow' => [
                    'id' => $workflowId,
                    'path' => 'generate-workflow.json',
                ],
            ],
            'result' => [
                'completed_steps' => $status === 'completed' ? 1 : 0,
                'failed_step' => $status === 'completed' ? null : 'create_comments',
                'skipped_steps' => 0,
            ],
            'rollback_guidance' => $status === 'completed'
                ? []
                : ['Review step create_comments with `foundry plan:show ' . $stepRecordId . '`.'],
            'timestamp' => null,
            'storage_path' => null,
            'intent' => 'Workflow generate comments',
            'mode' => 'workflow',
            'targets' => [],
            'generation_context_packet' => [
                'workflow' => [
                    'id' => $workflowId,
                    'path' => 'generate-workflow.json',
                    'shared_context_final' => [
                        'shared' => ['resource' => 'comments'],
                    ],
                ],
            ],
            'plan_original' => null,
            'plan_final' => null,
            'interactive' => null,
            'user_decisions' => [],
            'actions_executed' => [],
            'affected_files' => ['app/features/comments/feature.yaml'],
            'risk_level' => null,
            'policy' => null,
            'verification_results' => ['skipped' => true, 'ok' => true],
            'status' => $status,
            'error' => $status === 'completed' ? null : ['code' => 'GENERATE_FAILED', 'message' => 'Workflow failed.'],
            'undo' => null,
            'metadata' => [
                'requested_intent' => [
                    'raw' => 'Workflow generate comments',
                    'mode' => 'new',
                    'workflow_path' => 'generate-workflow.json',
                    'multi_step' => false,
                ],
                'dry_run' => true,
                'policy_check' => false,
                'interactive_requested' => false,
                'workflow_id' => $workflowId,
                'workflow_path' => 'generate-workflow.json',
                'multi_step' => false,
                'step_ids' => ['create_comments'],
                'packs_used' => [],
            ],
        ];
    }
}
