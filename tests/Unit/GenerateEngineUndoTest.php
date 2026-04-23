<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Generate\GenerateEngine;
use Foundry\Generate\PlanRecordStore;
use Foundry\Support\Paths;
use Foundry\Support\Yaml;
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

    public function test_undo_restores_updated_files_when_prior_contents_were_persisted(): void
    {
        $path = $this->project->root . '/notes.txt';
        file_put_contents($path, $this->afterContent());

        $this->store()->persist($this->record(
            planId: '11111111-1111-4111-8111-111111111111',
            undo: [
                'file_snapshots' => [[
                    'path' => 'notes.txt',
                    'exists' => true,
                    'content' => "before\n",
                ]],
            ],
        ));

        $result = $this->engine()->undo('11111111-1111-4111-8111-111111111111');

        $this->assertSame('undone', $result['status']);
        $this->assertSame([], $result['irreversible_actions']);
        $this->assertSame([], $result['skipped_actions']);
        $this->assertSame("before\n", (string) file_get_contents($path));
        $this->assertSame('restored', $result['reversed_actions'][0]['status']);
    }

    public function test_undo_reports_updated_files_as_irreversible_when_prior_contents_are_missing(): void
    {
        $path = $this->project->root . '/notes.txt';
        file_put_contents($path, $this->afterContent());

        $this->store()->persist($this->record(
            planId: '11111111-1111-4111-8111-111111111112',
            undo: null,
        ));

        $result = $this->engine()->undo('11111111-1111-4111-8111-111111111112');

        $this->assertSame('irreversible', $result['status']);
        $this->assertSame([], $result['reversed_actions']);
        $this->assertSame('missing_prior_contents', $result['irreversible_actions'][0]['reason']);
        $this->assertSame($this->afterContent(), (string) file_get_contents($path));
    }

    public function test_undo_skips_updates_when_current_state_has_drifted(): void
    {
        $path = $this->project->root . '/notes.txt';
        file_put_contents($path, "manually changed\n");

        $this->store()->persist($this->record(
            planId: '11111111-1111-4111-8111-111111111113',
            undo: [
                'file_snapshots' => [[
                    'path' => 'notes.txt',
                    'exists' => true,
                    'content' => "before\n",
                ]],
            ],
        ));

        $result = $this->engine()->undo('11111111-1111-4111-8111-111111111113', true);

        $this->assertSame('dry_run', $result['status']);
        $this->assertFalse($result['fully_reversible']);
        $this->assertSame([], $result['reversible_actions']);
        $this->assertSame('current_state_differs', $result['skipped_actions'][0]['reason']);
        $this->assertNotEmpty($result['warnings']);
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
    private function record(string $planId, ?array $undo): array
    {
        return [
            'plan_id' => $planId,
            'intent' => 'Refine notes',
            'mode' => 'modify',
            'targets' => [],
            'generation_context_packet' => null,
            'plan_original' => [
                'actions' => [[
                    'type' => 'update_file',
                    'path' => 'notes.txt',
                    'summary' => 'Update note contents.',
                    'explain_node_id' => 'feature:notes',
                ]],
                'affected_files' => ['notes.txt'],
                'risks' => [],
                'validations' => ['compile_graph'],
                'origin' => 'core',
                'generator_id' => 'core.feature.modify',
                'extension' => null,
                'confidence' => ['band' => 'high', 'score' => 0.91],
                'metadata' => [
                    'execution' => [
                        'strategy' => 'modify_feature',
                        'manifest_path' => 'notes.txt',
                        'manifest' => [
                            'feature' => 'notes',
                            'description' => 'After undo fixture',
                        ],
                        'prompts_path' => '',
                        'prompts_content' => '',
                    ],
                ],
            ],
            'plan_final' => null,
            'interactive' => null,
            'user_decisions' => [],
            'actions_executed' => [[
                'type' => 'update_file',
                'path' => 'notes.txt',
                'status' => 'written',
                'origin' => 'core',
                'extension' => null,
            ]],
            'affected_files' => ['notes.txt'],
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
                    'raw' => 'Refine notes',
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
                'generator_id' => 'core.feature.modify',
                'safety_routing' => null,
            ],
        ];
    }

    private function afterContent(): string
    {
        return Yaml::dump([
            'feature' => 'notes',
            'description' => 'After undo fixture',
        ]);
    }
}
