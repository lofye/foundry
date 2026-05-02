<?php

declare(strict_types=1);

namespace Foundry\Tests\Integration;

use Foundry\CLI\Application;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class CLIContextInspectionCommandsTest extends TestCase
{
    private TempProject $project;
    private string $cwd;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->cwd = getcwd() ?: '.';
        chdir($this->project->root);
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        $this->project->cleanup();
    }

    public function test_inspect_context_json_returns_combined_context_status(): void
    {
        $this->runCommand(['foundry', 'context', 'init', 'event-bus', '--json']);

        $result = $this->runCommand(['foundry', 'inspect', 'context', 'event-bus', '--json']);

        $this->assertSame(0, $result['status']);
        $this->assertSame(['feature', 'can_proceed', 'requires_repair', 'doctor', 'alignment', 'summary', 'required_actions'], array_keys($result['payload']));
        $this->assertSame('event-bus', $result['payload']['feature']);
        $this->assertTrue($result['payload']['can_proceed']);
        $this->assertFalse($result['payload']['requires_repair']);
        $this->assertSame('ok', $result['payload']['summary']['doctor_status']);
        $this->assertSame('warning', $result['payload']['summary']['alignment_status']);
        $this->assertSame([
            'Update the feature state to reflect current implementation.',
        ], $result['payload']['required_actions']);
    }

    public function test_verify_context_feature_json_returns_deterministic_output(): void
    {
        $this->runCommand(['foundry', 'context', 'init', 'event-bus', '--json']);

        $first = $this->runCommand(['foundry', 'verify', 'context', '--feature=event-bus', '--json']);
        $second = $this->runCommand(['foundry', 'verify', 'context', '--feature=event-bus', '--json']);

        $this->assertSame($first, $second);
        $this->assertSame(0, $first['status']);
        $this->assertSame([
            'feature',
            'status',
            'can_proceed',
            'requires_repair',
            'consumable',
            'doctor_status',
            'alignment_status',
            'issues',
            'required_actions',
        ], array_keys($first['payload']));
    }

    public function test_compliant_feature_passes_context_verification(): void
    {
        $this->runCommand(['foundry', 'context', 'init', 'event-bus', '--json']);

        $result = $this->runCommand(['foundry', 'verify', 'context', '--feature=event-bus', '--json']);

        $this->assertSame(0, $result['status']);
        $this->assertSame('pass', $result['payload']['status']);
        $this->assertTrue($result['payload']['can_proceed']);
        $this->assertFalse($result['payload']['requires_repair']);
        $this->assertFalse($result['payload']['consumable']);
        $this->assertSame('ok', $result['payload']['doctor_status']);
        $this->assertSame('warning', $result['payload']['alignment_status']);
        $this->assertSame([
            'Update the feature state to reflect current implementation.',
        ], $result['payload']['required_actions']);
    }

    public function test_repairable_or_non_compliant_or_mismatch_feature_fails_context_verification(): void
    {
        $repairable = $this->runCommand(['foundry', 'verify', 'context', '--feature=event-bus', '--json']);
        $nonCompliant = $this->runCommand(['foundry', 'verify', 'context', '--feature=Event_Bus', '--json']);

        $this->runCommand(['foundry', 'context', 'init', 'blog-comments', '--json']);
        $specPath = $this->project->root . '/docs/features/blog-comments/blog-comments.spec.md';
        file_put_contents($specPath, str_replace(
            "## Acceptance Criteria\n\n- TBD.\n",
            "## Acceptance Criteria\n\n- Comments are enabled.\n",
            (string) file_get_contents($specPath),
        ));
        $statePath = $this->project->root . '/docs/features/blog-comments/blog-comments.md';
        file_put_contents($statePath, str_replace(
            "## Current State\n\nTBD.\n",
            "## Current State\n\nReplay support is pending.\n",
            (string) file_get_contents($statePath),
        ));
        $mismatch = $this->runCommand(['foundry', 'verify', 'context', '--feature=blog-comments', '--json']);

        $this->assertSame(1, $repairable['status']);
        $this->assertSame('fail', $repairable['payload']['status']);
        $this->assertFalse($repairable['payload']['can_proceed']);
        $this->assertTrue($repairable['payload']['requires_repair']);
        $this->assertSame('repairable', $repairable['payload']['doctor_status']);
        $this->assertContains('Create missing spec file: docs/features/event-bus/event-bus.spec.md', $repairable['payload']['required_actions']);

        $this->assertSame(1, $nonCompliant['status']);
        $this->assertSame('fail', $nonCompliant['payload']['status']);
        $this->assertFalse($nonCompliant['payload']['can_proceed']);
        $this->assertTrue($nonCompliant['payload']['requires_repair']);
        $this->assertSame('non_compliant', $nonCompliant['payload']['doctor_status']);

        $this->assertSame(1, $mismatch['status']);
        $this->assertSame('fail', $mismatch['payload']['status']);
        $this->assertFalse($mismatch['payload']['can_proceed']);
        $this->assertTrue($mismatch['payload']['requires_repair']);
        $this->assertSame('ok', $mismatch['payload']['doctor_status']);
        $this->assertSame('mismatch', $mismatch['payload']['alignment_status']);
        $this->assertContains('Reflect the spec requirement in Current State, Open Questions, or Next Steps.', $mismatch['payload']['required_actions']);
    }

    public function test_verify_context_without_feature_checks_all_features(): void
    {
        $this->runCommand(['foundry', 'context', 'init', 'zeta-feature', '--json']);
        $this->runCommand(['foundry', 'context', 'init', 'alpha-feature', '--json']);

        $result = $this->runCommand(['foundry', 'verify', 'context', '--json']);
        $features = array_values(array_map(
            static fn(array $feature): string => (string) ($feature['feature'] ?? ''),
            $result['payload']['features'],
        ));

        $this->assertSame(0, $result['status']);
        $this->assertSame('pass', $result['payload']['status']);
        $this->assertFalse($result['payload']['can_proceed']);
        $this->assertTrue($result['payload']['requires_repair']);
        $this->assertSame(['alpha-feature', 'zeta-feature'], $features);
        $this->assertSame(2, $result['payload']['summary']['pass']);
        $this->assertSame([false, false], array_values(array_map(
            static fn(array $feature): bool => (bool) ($feature['consumable'] ?? true),
            $result['payload']['features'],
        )));
    }

    public function test_json_and_text_outputs_report_consistent_readiness(): void
    {
        $json = $this->runCommand(['foundry', 'verify', 'context', '--feature=event-bus', '--json']);
        $text = $this->runTextCommand(['foundry', 'verify', 'context', '--feature=event-bus']);

        $this->assertSame(1, $json['status']);
        $this->assertSame(1, $text['status']);
        $this->assertFalse($json['payload']['can_proceed']);
        $this->assertTrue($json['payload']['requires_repair']);
        $this->assertStringContainsString('Can proceed: no', $text['output']);
        $this->assertStringContainsString('Requires repair: yes', $text['output']);
        $this->assertStringContainsString('Create missing spec file: docs/features/event-bus/event-bus.spec.md', $text['output']);
    }

    public function test_verify_context_surfaces_execution_spec_drift_from_doctor(): void
    {
        $this->runCommand(['foundry', 'context', 'init', 'event-bus', '--json']);
        unlink($this->project->root . '/docs/features/event-bus/event-bus.md');
        $this->writeExecutionSpec('event-bus', '001-initial', draft: true);

        $result = $this->runCommand(['foundry', 'verify', 'context', '--feature=event-bus', '--json']);

        $this->assertSame(1, $result['status']);
        $this->assertSame('fail', $result['payload']['status']);
        $this->assertSame('repairable', $result['payload']['doctor_status']);
        $this->assertSame('mismatch', $result['payload']['alignment_status']);
        $this->assertSame([
            [
                'source' => 'doctor',
                'code' => 'CONTEXT_FILE_MISSING',
                'message' => 'Context state file is missing.',
                'file_path' => 'docs/features/event-bus/event-bus.md',
            ],
            [
                'source' => 'doctor',
                'code' => 'EXECUTION_SPEC_DRIFT',
                'message' => 'Execution specs exist for this feature, but canonical feature context is missing or incomplete.',
                'file_path' => 'docs/features/event-bus/event-bus.md',
            ],
        ], array_slice($result['payload']['issues'], 0, 2));
        $this->assertContains('Run foundry context init event-bus --json when appropriate to initialize missing canonical context files.', $result['payload']['required_actions']);
        $this->assertContains('Do not rely on execution specs as the source of truth for event-bus.', $result['payload']['required_actions']);
    }

    public function test_verify_context_surfaces_new_doctor_semantic_diagnostics(): void
    {
        $this->runCommand(['foundry', 'context', 'init', 'event-bus', '--json']);
        $this->writeDivergentSemanticContext();

        $result = $this->runCommand(['foundry', 'verify', 'context', '--feature=event-bus', '--json']);

        $this->assertSame(1, $result['status']);
        $this->assertSame('fail', $result['payload']['status']);
        $this->assertSame('repairable', $result['payload']['doctor_status']);
        $this->assertSame('mismatch', $result['payload']['alignment_status']);
        $this->assertSame([
            [
                'source' => 'doctor',
                'code' => 'STALE_COMPLETED_ITEMS_IN_NEXT_STEPS',
                'message' => 'Next Steps contains work that is already reflected as implemented in Current State.',
                'file_path' => 'docs/features/event-bus/event-bus.md',
            ],
            [
                'source' => 'doctor',
                'code' => 'DECISION_MISSING_FOR_STATE_DIVERGENCE',
                'message' => 'Current State diverges from the canonical spec without a supporting decision entry.',
                'file_path' => 'docs/features/event-bus/event-bus.decisions.md',
            ],
        ], array_slice($result['payload']['issues'], 0, 2));
        $this->assertContains('Remove already implemented work from Next Steps in docs/features/event-bus/event-bus.md.', $result['payload']['required_actions']);
        $this->assertContains('Add a decision entry to docs/features/event-bus/event-bus.decisions.md that explains the spec-state divergence.', $result['payload']['required_actions']);
    }

    /**
     * @param array<int,string> $argv
     * @return array{status:int,payload:array<string,mixed>}
     */
    private function runCommand(array $argv): array
    {
        ob_start();
        $status = (new Application())->run($argv);
        $output = ob_get_clean() ?: '';

        /** @var array<string,mixed> $payload */
        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        return ['status' => $status, 'payload' => $payload];
    }

    /**
     * @param array<int,string> $argv
     * @return array{status:int,output:string}
     */
    private function runTextCommand(array $argv): array
    {
        ob_start();
        $status = (new Application())->run($argv);
        $output = ob_get_clean() ?: '';

        return ['status' => $status, 'output' => $output];
    }

    private function writeExecutionSpec(string $feature, string $name, bool $draft = false): void
    {
        $directory = $this->project->root . '/docs/features/' . $feature . '/specs' . ($draft ? '/drafts' : '');
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($directory . '/' . $name . '.md', <<<MD
# Execution Spec: {$name}

## Feature
- {$feature}
MD);
    }

    private function writeDivergentSemanticContext(): void
    {
        file_put_contents($this->project->root . '/docs/features/event-bus/event-bus.spec.md', <<<'MD'
# Feature Spec: event-bus

## Purpose

Publish posts safely.

## Goals

- Keep publication deterministic.

## Non-Goals

- Do not bypass moderation silently.

## Constraints

- Preserve review workflow history.

## Expected Behavior

- Publishes blog posts through moderated review workflow.

## Acceptance Criteria

- Blog posts publish only after moderation review.

## Assumptions

- Moderation remains the default policy.
MD);

        file_put_contents($this->project->root . '/docs/features/event-bus/event-bus.md', <<<'MD'
# Feature: event-bus

## Purpose

Publish posts safely.

## Current State

- Publishes posts immediately in production.

## Open Questions

- None.

## Next Steps

- Publishes posts immediately in production.
MD);

        file_put_contents($this->project->root . '/docs/features/event-bus/event-bus.decisions.md', '');
    }
}
