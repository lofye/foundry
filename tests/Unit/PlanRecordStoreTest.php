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

    private function store(string $timestamp): PlanRecordStore
    {
        return new PlanRecordStore(
            new Paths($this->project->root),
            static fn(): \DateTimeImmutable => new \DateTimeImmutable($timestamp),
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
}
