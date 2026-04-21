<?php

declare(strict_types=1);

namespace Foundry\Tests\Integration;

use Foundry\CLI\Application;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class CLIContextCommandsTest extends TestCase
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

    public function test_context_init_creates_the_three_canonical_files(): void
    {
        $result = $this->runCommand(['foundry', 'context', 'init', 'event-bus', '--json']);

        $this->assertSame(0, $result['status']);
        $this->assertTrue($result['payload']['success']);
        $this->assertSame([
            'docs/features/event-bus.spec.md',
            'docs/features/event-bus.md',
            'docs/features/event-bus.decisions.md',
        ], $result['payload']['created']);
        $this->assertFileExists($this->project->root . '/docs/features/event-bus.spec.md');
        $this->assertFileExists($this->project->root . '/docs/features/event-bus.md');
        $this->assertFileExists($this->project->root . '/docs/features/event-bus.decisions.md');
    }

    public function test_context_init_with_invalid_feature_name_fails_deterministically(): void
    {
        $result = $this->runCommand(['foundry', 'context', 'init', 'Event_Bus', '--json']);
        $codes = array_values(array_map(
            static fn(array $issue): string => (string) ($issue['code'] ?? ''),
            $result['payload']['issues'],
        ));

        $this->assertSame(1, $result['status']);
        $this->assertFalse($result['payload']['success']);
        $this->assertFalse($result['payload']['feature_valid']);
        $this->assertContains('CONTEXT_FEATURE_NAME_UPPERCASE', $codes);
    }

    public function test_context_doctor_feature_json_returns_required_contract(): void
    {
        $this->runCommand(['foundry', 'context', 'init', 'event-bus', '--json']);

        $result = $this->runCommand(['foundry', 'context', 'doctor', '--feature=event-bus', '--json']);

        $this->assertSame(0, $result['status']);
        $this->assertSame('ok', $result['payload']['status']);
        $this->assertSame('event-bus', $result['payload']['feature']);
        $this->assertTrue($result['payload']['can_proceed']);
        $this->assertFalse($result['payload']['requires_repair']);
        $this->assertSame([], $result['payload']['required_actions']);
        $this->assertSame([
            'status',
            'feature',
            'can_proceed',
            'requires_repair',
            'files',
            'required_actions',
        ], array_keys($result['payload']));
        $this->assertSame([
            'path',
            'exists',
            'valid',
            'missing_sections',
            'issues',
        ], array_keys($result['payload']['files']['spec']));
        $this->assertSame([
            'path',
            'exists',
            'valid',
            'issues',
        ], array_keys($result['payload']['files']['decisions']));
        $this->assertSame('docs/features/event-bus.spec.md', $result['payload']['files']['spec']['path']);
        $this->assertTrue($result['payload']['files']['spec']['exists']);
        $this->assertTrue($result['payload']['files']['spec']['valid']);
    }

    public function test_context_doctor_all_json_returns_deterministic_ordering_and_results(): void
    {
        $this->runCommand(['foundry', 'context', 'init', 'zeta-feature', '--json']);
        $this->runCommand(['foundry', 'context', 'init', 'alpha-feature', '--json']);

        $result = $this->runCommand(['foundry', 'context', 'doctor', '--all', '--json']);
        $features = array_values(array_map(
            static fn(array $feature): string => (string) ($feature['feature'] ?? ''),
            $result['payload']['features'],
        ));

        $this->assertSame(0, $result['status']);
        $this->assertSame('ok', $result['payload']['status']);
        $this->assertTrue($result['payload']['can_proceed']);
        $this->assertFalse($result['payload']['requires_repair']);
        $this->assertSame(['alpha-feature', 'zeta-feature'], $features);
        $this->assertSame(2, $result['payload']['summary']['ok']);
        $this->assertSame(2, $result['payload']['summary']['total']);
    }

    public function test_context_doctor_reports_malformed_docs_correctly(): void
    {
        $this->runCommand(['foundry', 'context', 'init', 'event-bus', '--json']);
        $specPath = $this->project->root . '/docs/features/event-bus.spec.md';
        file_put_contents($specPath, str_replace('# Feature Spec: event-bus', '# Spec: event-bus', (string) file_get_contents($specPath)));

        $result = $this->runCommand(['foundry', 'context', 'doctor', '--feature=event-bus', '--json']);

        $this->assertSame(1, $result['status']);
        $this->assertSame('repairable', $result['payload']['status']);
        $this->assertFalse($result['payload']['can_proceed']);
        $this->assertTrue($result['payload']['requires_repair']);
        $this->assertContains('Fix malformed spec heading in docs/features/event-bus.spec.md.', $result['payload']['required_actions']);
        $this->assertSame('CONTEXT_SPEC_HEADING_INVALID', $result['payload']['files']['spec']['issues'][0]['code']);
    }

    public function test_context_doctor_reports_missing_docs_as_repairable(): void
    {
        $result = $this->runCommand(['foundry', 'context', 'doctor', '--feature=event-bus', '--json']);

        $this->assertSame(1, $result['status']);
        $this->assertSame('repairable', $result['payload']['status']);
        $this->assertFalse($result['payload']['can_proceed']);
        $this->assertTrue($result['payload']['requires_repair']);
        $this->assertContains('Create missing spec file: docs/features/event-bus.spec.md', $result['payload']['required_actions']);
        $this->assertContains('Create missing state file: docs/features/event-bus.md', $result['payload']['required_actions']);
        $this->assertContains('Create missing decision ledger: docs/features/event-bus.decisions.md', $result['payload']['required_actions']);
    }

    public function test_context_doctor_missing_state_produces_blocked_readiness(): void
    {
        $this->runCommand(['foundry', 'context', 'init', 'event-bus', '--json']);
        unlink($this->project->root . '/docs/features/event-bus.md');

        $result = $this->runCommand(['foundry', 'context', 'doctor', '--feature=event-bus', '--json']);

        $this->assertSame(1, $result['status']);
        $this->assertSame('repairable', $result['payload']['status']);
        $this->assertFalse($result['payload']['can_proceed']);
        $this->assertTrue($result['payload']['requires_repair']);
        $this->assertContains('Create missing state file: docs/features/event-bus.md', $result['payload']['required_actions']);
    }

    public function test_context_doctor_missing_decisions_produces_blocked_readiness(): void
    {
        $this->runCommand(['foundry', 'context', 'init', 'event-bus', '--json']);
        unlink($this->project->root . '/docs/features/event-bus.decisions.md');

        $result = $this->runCommand(['foundry', 'context', 'doctor', '--feature=event-bus', '--json']);

        $this->assertSame(1, $result['status']);
        $this->assertSame('repairable', $result['payload']['status']);
        $this->assertFalse($result['payload']['can_proceed']);
        $this->assertTrue($result['payload']['requires_repair']);
        $this->assertContains('Create missing decision ledger: docs/features/event-bus.decisions.md', $result['payload']['required_actions']);
    }

    public function test_context_doctor_reports_execution_spec_drift_using_existing_json_shape(): void
    {
        $this->writeExecutionSpec('event-bus', '001-initial');

        $result = $this->runCommand(['foundry', 'context', 'doctor', '--feature=event-bus', '--json']);

        $this->assertSame(1, $result['status']);
        $this->assertSame([
            'status',
            'feature',
            'can_proceed',
            'requires_repair',
            'files',
            'required_actions',
        ], array_keys($result['payload']));
        $this->assertSame('repairable', $result['payload']['status']);
        $this->assertSame(['CONTEXT_FILE_MISSING', 'EXECUTION_SPEC_DRIFT'], $this->issueCodes($result['payload']['files']['spec']['issues']));
        $this->assertSame(['CONTEXT_FILE_MISSING', 'EXECUTION_SPEC_DRIFT'], $this->issueCodes($result['payload']['files']['state']['issues']));
        $this->assertSame(['CONTEXT_FILE_MISSING', 'EXECUTION_SPEC_DRIFT'], $this->issueCodes($result['payload']['files']['decisions']['issues']));
        $this->assertSame([
            'Create missing spec file: docs/features/event-bus.spec.md',
            'Create missing state file: docs/features/event-bus.md',
            'Create missing decision ledger: docs/features/event-bus.decisions.md',
            'Create or initialize the missing canonical feature context files for event-bus.',
            'Run foundry context init event-bus --json when appropriate to initialize missing canonical context files.',
            'Do not rely on execution specs as the source of truth for event-bus.',
        ], $result['payload']['required_actions']);
    }

    public function test_context_doctor_reports_semantic_diagnostic_rules_using_existing_json_shape(): void
    {
        $this->runCommand(['foundry', 'context', 'init', 'event-bus', '--json']);
        $this->writeDivergentSemanticContext();

        $result = $this->runCommand(['foundry', 'context', 'doctor', '--feature=event-bus', '--json']);

        $this->assertSame(1, $result['status']);
        $this->assertSame([
            'status',
            'feature',
            'can_proceed',
            'requires_repair',
            'files',
            'required_actions',
        ], array_keys($result['payload']));
        $this->assertSame('repairable', $result['payload']['status']);
        $this->assertSame(['STALE_COMPLETED_ITEMS_IN_NEXT_STEPS'], $this->issueCodes($result['payload']['files']['state']['issues']));
        $this->assertSame(['DECISION_MISSING_FOR_STATE_DIVERGENCE'], $this->issueCodes($result['payload']['files']['decisions']['issues']));
        $this->assertSame([
            'Remove already implemented work from Next Steps in docs/features/event-bus.md.',
            'Add a decision entry to docs/features/event-bus.decisions.md that explains the spec-state divergence.',
        ], $result['payload']['required_actions']);
    }

    public function test_context_doctor_feature_and_all_conflict_fails_deterministically(): void
    {
        $result = $this->runCommand(['foundry', 'context', 'doctor', '--feature=event-bus', '--all', '--json']);

        $this->assertSame(1, $result['status']);
        $this->assertSame('CLI_CONTEXT_DOCTOR_TARGET_CONFLICT', $result['payload']['error']['code']);
        $this->assertSame('Use either --feature=<feature> or --all, not both.', $result['payload']['error']['message']);
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

    private function writeExecutionSpec(string $feature, string $name, bool $draft = false): void
    {
        $directory = $this->project->root . '/docs/specs/' . $feature . ($draft ? '/drafts' : '');
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
        file_put_contents($this->project->root . '/docs/features/event-bus.spec.md', <<<'MD'
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

        file_put_contents($this->project->root . '/docs/features/event-bus.md', <<<'MD'
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

        file_put_contents($this->project->root . '/docs/features/event-bus.decisions.md', '');
    }

    /**
     * @param array<int,array<string,mixed>> $issues
     * @return list<string>
     */
    private function issueCodes(array $issues): array
    {
        return array_values(array_map(
            static fn(array $issue): string => (string) ($issue['code'] ?? ''),
            $issues,
        ));
    }
}
