<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\CLI\CommandContext;
use Foundry\CLI\Commands\PlanListCommand;
use Foundry\CLI\Commands\PlanShowCommand;
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
     * @return array<string,mixed>
     */
    private function record(
        string $planId,
        string $intent,
        string $status = 'success',
        string $riskLevel = 'LOW',
        array $affectedFiles = [],
        array $actionsExecuted = [],
    ): array {
        return [
            'plan_id' => $planId,
            'intent' => $intent,
            'mode' => 'new',
            'targets' => [],
            'generation_context_packet' => null,
            'plan_original' => null,
            'plan_final' => null,
            'interactive' => ['enabled' => true, 'rejected' => $status === 'aborted'],
            'user_decisions' => [],
            'actions_executed' => $actionsExecuted,
            'affected_files' => $affectedFiles,
            'risk_level' => $riskLevel,
            'verification_results' => ['skipped' => true, 'ok' => true],
            'status' => $status,
            'error' => null,
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
