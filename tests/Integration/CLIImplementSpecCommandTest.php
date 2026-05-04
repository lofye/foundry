<?php

declare(strict_types=1);

namespace Foundry\Tests\Integration;

use Foundry\CLI\Application;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class CLIImplementSpecCommandTest extends TestCase
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

    public function test_implement_spec_succeeds_when_execution_spec_and_context_align(): void
    {
        $this->runCommand(['foundry', 'context', 'init', 'event-bus', '--json']);
        $this->writeMeaningfulContext('event-bus');
        $this->writeExecutionSpec('event-bus', '001-initial');

        $result = $this->runCommand(['foundry', 'implement', 'spec', 'event-bus/001-initial', '--json']);

        $this->assertSame(0, $result['status']);
        $this->assertSame([
            'spec_id',
            'feature',
            'status',
            'can_proceed',
            'requires_repair',
            'repair_attempted',
            'repair_successful',
            'actions_taken',
            'issues',
            'required_actions',
            'quality_gate',
        ], array_keys($result['payload']));
        $this->assertSame('event-bus/001-initial', $result['payload']['spec_id']);
        $this->assertSame('event-bus', $result['payload']['feature']);
        $this->assertSame('completed', $result['payload']['status']);
        $this->assertTrue($result['payload']['quality_gate']['passed']);
        $this->assertSame('passed', $result['payload']['quality_gate']['changed_surface']['status']);
        $this->assertContains('Appended implementation log entry: docs/features/implementation-log.md', $result['payload']['actions_taken']);
        $this->assertContains('Applied execution spec: docs/features/event-bus/specs/001-initial.md', $result['payload']['actions_taken']);
        $this->assertFileExists($this->project->root . '/app/features/event-bus/feature.yaml');
        $this->assertMatchesRegularExpression(
            '/^## \d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} [+-]\d{4}\n- spec: event-bus\/001-initial\.md\n$/',
            (string) file_get_contents($this->project->root . '/docs/features/implementation-log.md'),
        );
    }

    public function test_implement_spec_accepts_feature_and_id_shorthand(): void
    {
        $this->runCommand(['foundry', 'context', 'init', 'event-bus', '--json']);
        $this->writeMeaningfulContext('event-bus');
        $this->writeExecutionSpec('event-bus', '001-initial');

        $result = $this->runCommand(['foundry', 'implement', 'spec', 'event-bus', '001', '--json']);

        $this->assertSame(0, $result['status']);
        $this->assertSame('completed', $result['payload']['status']);
        $this->assertSame('event-bus/001-initial', $result['payload']['spec_id']);
    }

    public function test_implement_spec_accepts_feature_and_hierarchical_id_shorthand(): void
    {
        $this->runCommand(['foundry', 'context', 'init', 'event-bus', '--json']);
        $this->writeMeaningfulContext('event-bus');
        $this->writeExecutionSpec('event-bus', '015.001-nested-work');

        $result = $this->runCommand(['foundry', 'implement', 'spec', 'event-bus', '015.001', '--json']);

        $this->assertSame(0, $result['status']);
        $this->assertSame('completed', $result['payload']['status']);
        $this->assertSame('event-bus/015.001-nested-work', $result['payload']['spec_id']);
    }

    public function test_conflicting_execution_spec_is_blocked(): void
    {
        $this->runCommand(['foundry', 'context', 'init', 'event-bus', '--json']);
        $this->writeMeaningfulContext('event-bus');
        $specPath = $this->project->root . '/docs/features/event-bus/event-bus.spec.md';
        file_put_contents($specPath, str_replace(
            '- Do not add async delivery.',
            '- Do not make execution specs authoritative after implementation.',
            (string) file_get_contents($specPath),
        ));
        $this->writeExecutionSpec(
            'event-bus',
            '001-conflict',
            requestedChanges: ['Make execution specs authoritative after implementation.'],
        );

        $result = $this->runCommand(['foundry', 'implement', 'spec', 'event-bus/001-conflict', '--json']);

        $this->assertSame(1, $result['status']);
        $this->assertSame('blocked', $result['payload']['status']);
        $this->assertSame('EXECUTION_SPEC_CONFLICTS_WITH_CANONICAL_SPEC', $result['payload']['issues'][0]['code']);
        $this->assertFileDoesNotExist($this->project->root . '/app/features/event-bus/feature.yaml');
    }

    public function test_implement_spec_refuses_non_consumable_context(): void
    {
        $this->runCommand(['foundry', 'context', 'init', 'event-bus', '--json']);
        $this->writeExecutionSpec('event-bus', '001-initial');

        $result = $this->runCommand(['foundry', 'implement', 'spec', 'event-bus/001-initial', '--json']);

        $this->assertSame(1, $result['status']);
        $this->assertSame('blocked', $result['payload']['status']);
        $this->assertFalse($result['payload']['can_proceed']);
        $this->assertTrue($result['payload']['requires_repair']);
        $this->assertSame('context_not_consumable', $result['payload']['reason']);
        $this->assertSame(
            'Run `php bin/foundry verify context --json` and resolve all issues before proceeding.',
            $result['payload']['required_action'],
        );
        $this->assertContains('Update the feature state to reflect current implementation.', $result['payload']['required_actions']);
    }

    public function test_auto_log_execution_spec_with_negative_lead_in_fragments_is_not_falsely_blocked(): void
    {
        $this->runCommand(['foundry', 'context', 'init', 'execution-spec-system', '--json']);
        $this->writeExecutionSpecSystemContext();
        $this->writeRawExecutionSpec('execution-spec-system', '004-spec-auto-log-on-implementation', <<<'MD'
# Execution Spec: 004-spec-auto-log-on-implementation

## Feature

- execution-spec-system

## Purpose

- Automatically append implementation entries to the implementation log when an active execution spec is implemented successfully.

## Scope

- Hook into the active execution-spec implementation flow.
- Append entries to `docs/features/implementation-log.md`.
- Enforce required log-entry formatting.
- Prevent duplicate entries for the same completed implementation event.

## Constraints

- Must not log draft specs.
- Must not duplicate entries for the same implementation event.
- Must use the required format from `docs/features/README.md`.
- Must be deterministic in structure and behavior.
- Must surface log-write failures clearly and deterministically.

## Requested Changes

### 1. Trigger Point

After successful implementation of an active execution spec, Foundry must automatically append an implementation entry to:

`docs/features/implementation-log.md`

This must occur only after implementation has succeeded.

Do not append log entries:
- before implementation succeeds
- for draft specs
- for failed or partial implementations
MD);

        $result = $this->runCommand(['foundry', 'implement', 'spec', 'execution-spec-system/004-spec-auto-log-on-implementation', '--json']);

        $this->assertSame(0, $result['status']);
        $this->assertSame('completed', $result['payload']['status']);
        $this->assertContains(
            'Applied execution spec: docs/features/execution-spec-system/specs/004-spec-auto-log-on-implementation.md',
            $result['payload']['actions_taken'],
        );
        $this->assertSame(
            [],
            array_values(array_filter(
                array_map(static fn(array $issue): string => (string) ($issue['code'] ?? ''), $result['payload']['issues']),
                static fn(string $code): bool => $code === 'EXECUTION_SPEC_CONFLICTS_WITH_CANONICAL_SPEC',
            )),
        );
    }

    public function test_negative_execution_spec_instruction_that_opposes_canonical_requirement_is_blocked(): void
    {
        $this->runCommand(['foundry', 'context', 'init', 'execution-spec-system', '--json']);
        file_put_contents($this->project->root . '/docs/features/execution-spec-system/execution-spec-system.spec.md', <<<'MD'
# Feature Spec: execution-spec-system

## Purpose

Keep execution-spec implementation logging deterministic.

## Goals

- Preserve canonical execution-spec lifecycle rules.

## Non-Goals

- Do not rename existing execution-spec ids once assigned.

## Constraints

- Keep conflict detection deterministic.

## Expected Behavior

- Active execution specs append one implementation-log entry after successful implementation.

## Acceptance Criteria

- Successful active execution-spec implementation appends exactly one implementation-log entry automatically.

## Assumptions

- Feature directories continue to provide execution-spec context.
MD);
        file_put_contents($this->project->root . '/docs/features/execution-spec-system/execution-spec-system.md', <<<'MD'
# Feature: execution-spec-system

## Purpose

Keep execution-spec implementation logging deterministic.

## Current State

- Implementation-log behavior is under active development.

## Open Questions

- None.

## Next Steps

- Finalize deterministic implementation logging.
MD);
        $this->writeExecutionSpec(
            'execution-spec-system',
            '017-conflict-detection-prohibition-awareness',
            requestedChanges: ['Do not append implementation-log entries for active execution specs.'],
        );

        $result = $this->runCommand([
            'foundry',
            'implement',
            'spec',
            'execution-spec-system/017-conflict-detection-prohibition-awareness',
            '--json',
        ]);

        $this->assertSame(1, $result['status']);
        $this->assertSame('blocked', $result['payload']['status']);
        $this->assertSame('EXECUTION_SPEC_CONFLICTS_WITH_CANONICAL_SPEC', $result['payload']['issues'][0]['code']);
    }

    public function test_framework_repository_execution_spec_is_blocked_before_app_feature_scaffolding(): void
    {
        $frameworkRoot = dirname(__DIR__, 2);
        chdir($frameworkRoot);

        try {
            $result = $this->runCommand(['foundry', 'implement', 'spec', 'execution-spec-system/004-spec-auto-log-on-implementation', '--json']);

            $this->assertSame(1, $result['status']);
            $this->assertSame('blocked', $result['payload']['status']);
            $this->assertSame('EXECUTION_SPEC_FRAMEWORK_APP_SCAFFOLD_BLOCKED', $result['payload']['issues'][0]['code']);
            $this->assertDirectoryDoesNotExist($frameworkRoot . '/app/features/execution-spec-system');
        } finally {
            chdir($this->project->root);
        }
    }

    public function test_repair_flag_reuses_feature_execution_pipeline(): void
    {
        $this->runCommand(['foundry', 'context', 'init', 'event-bus', '--json']);
        $this->writeMeaningfulContext('event-bus');
        $this->writeExecutionSpec('event-bus', '001-initial');
        unlink($this->project->root . '/docs/features/event-bus/event-bus.md');

        $result = $this->runCommand(['foundry', 'implement', 'spec', 'event-bus/001-initial', '--repair', '--json']);

        $this->assertSame(0, $result['status']);
        $this->assertSame('repaired', $result['payload']['status']);
        $this->assertTrue($result['payload']['repair_attempted']);
        $this->assertTrue($result['payload']['repair_successful']);
    }

    public function test_auto_repair_flag_reuses_feature_execution_pipeline(): void
    {
        $this->runCommand(['foundry', 'context', 'init', 'event-bus', '--json']);
        $this->writeMeaningfulContext('event-bus');
        $this->writeExecutionSpec('event-bus', '001-initial');
        $path = $this->project->root . '/docs/features/event-bus/event-bus.spec.md';
        file_put_contents($path, str_replace('# Feature Spec: event-bus', '# Spec: event-bus', (string) file_get_contents($path)));

        $result = $this->runCommand(['foundry', 'implement', 'spec', 'event-bus/001-initial', '--auto-repair', '--json']);

        $this->assertSame(0, $result['status']);
        $this->assertSame('repaired', $result['payload']['status']);
        $this->assertTrue($result['payload']['repair_attempted']);
        $this->assertTrue($result['payload']['repair_successful']);
    }

    public function test_repeated_implement_spec_runs_do_not_duplicate_log_entries(): void
    {
        $this->runCommand(['foundry', 'context', 'init', 'event-bus', '--json']);
        $this->writeMeaningfulContext('event-bus');
        $this->writeExecutionSpec('event-bus', '001-initial');

        $first = $this->runCommand(['foundry', 'implement', 'spec', 'event-bus/001-initial', '--json']);
        $second = $this->runCommand(['foundry', 'implement', 'spec', 'event-bus/001-initial', '--json']);

        $this->assertSame(0, $first['status']);
        $this->assertSame(0, $second['status']);
        $this->assertSame(
            1,
            preg_match_all(
                '/^- spec: event-bus\/001-initial\.md$/m',
                (string) file_get_contents($this->project->root . '/docs/features/implementation-log.md'),
            ),
        );
    }

    public function test_implement_spec_fails_when_quality_gate_does_not_pass(): void
    {
        $this->runCommand(['foundry', 'context', 'init', 'event-bus', '--json']);
        $this->writeMeaningfulContext('event-bus');
        $this->writeExecutionSpec('event-bus', '001-initial');
        $coveragePath = $this->project->root . '/src/Foo.php';
        if (!is_dir(dirname($coveragePath))) {
            mkdir(dirname($coveragePath), 0777, true);
        }
        file_put_contents($coveragePath, "<?php\n");
        file_put_contents($this->project->root . '/.foundry-test-coverage-files.json', json_encode([
            [
                'path' => $coveragePath,
                'statements' => 10,
                'covered_statements' => 8,
            ],
        ], JSON_THROW_ON_ERROR));

        $result = $this->runCommand(['foundry', 'implement', 'spec', 'event-bus/001-initial', '--json']);

        $this->assertSame(1, $result['status']);
        $this->assertSame('completed_with_issues', $result['payload']['status']);
        $this->assertSame('IMPLEMENTATION_QUALITY_GATE_GLOBAL_COVERAGE_BELOW_THRESHOLD', $result['payload']['issues'][0]['code']);
        $this->assertFalse($result['payload']['quality_gate']['passed']);
    }

    public function test_feature_and_id_shorthand_draft_only_match_fails_clearly(): void
    {
        $this->runCommand(['foundry', 'context', 'init', 'event-bus', '--json']);
        $this->writeMeaningfulContext('event-bus');
        $this->writeRawDraftExecutionSpec('event-bus', '001-initial', <<<'MD'
# Execution Spec: 001-initial

## Feature

- event-bus

## Purpose

- Execute a bounded event bus implementation step.

## Scope

- Add deterministic event bus scaffolding.

## Constraints

- Keep execution deterministic.

## Requested Changes

- Add deterministic event bus scaffolding.
MD);

        $result = $this->runCommand(['foundry', 'implement', 'spec', 'event-bus', '001', '--json']);

        $this->assertSame(1, $result['status']);
        $this->assertSame('blocked', $result['payload']['status']);
        $this->assertSame('EXECUTION_SPEC_DRAFT_ONLY', $result['payload']['issues'][0]['code']);
        $this->assertContains(
            'Promote the draft execution spec to docs/features/<feature>/specs/<id>-<slug>.md before implementing it.',
            $result['payload']['required_actions'],
        );
    }

    public function test_feature_and_id_shorthand_unknown_id_fails_clearly(): void
    {
        $this->runCommand(['foundry', 'context', 'init', 'event-bus', '--json']);
        $this->writeMeaningfulContext('event-bus');

        $result = $this->runCommand(['foundry', 'implement', 'spec', 'event-bus', '001', '--json']);

        $this->assertSame(1, $result['status']);
        $this->assertSame('blocked', $result['payload']['status']);
        $this->assertSame('EXECUTION_SPEC_NOT_FOUND', $result['payload']['issues'][0]['code']);
    }

    public function test_feature_and_id_shorthand_malformed_id_fails_clearly(): void
    {
        $this->runCommand(['foundry', 'context', 'init', 'event-bus', '--json']);
        $this->writeMeaningfulContext('event-bus');
        $this->writeExecutionSpec('event-bus', '001-initial');

        $result = $this->runCommand(['foundry', 'implement', 'spec', 'event-bus', '18', '--json']);

        $this->assertSame(1, $result['status']);
        $this->assertSame('blocked', $result['payload']['status']);
        $this->assertSame('EXECUTION_SPEC_ID_INVALID', $result['payload']['issues'][0]['code']);
    }

    public function test_feature_only_invocation_fails_with_clear_missing_id_message_in_text_mode(): void
    {
        $this->runCommand(['foundry', 'context', 'init', 'event-bus', '--json']);
        $this->writeMeaningfulContext('event-bus');

        $result = $this->runRawCommand(['foundry', 'implement', 'spec', 'event-bus']);

        $this->assertSame(1, $result['status']);
        $this->assertStringContainsString('Status: blocked', $result['output']);
        $this->assertStringContainsString('CLI_IMPLEMENT_SPEC_ID_REQUIRED', $result['output']);
        $this->assertStringContainsString('Provide the execution spec id as `implement spec <feature> <id>`.', $result['output']);
    }

    public function test_log_write_failure_is_surfaced_clearly(): void
    {
        $this->runCommand(['foundry', 'context', 'init', 'event-bus', '--json']);
        $this->writeMeaningfulContext('event-bus');
        $this->writeExecutionSpec('event-bus', '001-initial');
        mkdir($this->project->root . '/docs/features/implementation-log.md', 0777, true);

        $result = $this->runCommand(['foundry', 'implement', 'spec', 'event-bus/001-initial', '--json']);

        $this->assertSame(1, $result['status']);
        $this->assertSame('completed_with_issues', $result['payload']['status']);
        $this->assertSame('EXECUTION_SPEC_IMPLEMENTATION_LOG_WRITE_FAILED', $result['payload']['issues'][0]['code']);
        $this->assertContains(
            'Restore write access to docs/features/implementation-log.md and record the missing implementation entry.',
            $result['payload']['required_actions'],
        );
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
    private function runRawCommand(array $argv): array
    {
        ob_start();
        $status = (new Application())->run($argv);
        $output = ob_get_clean() ?: '';

        return ['status' => $status, 'output' => $output];
    }
    /**
     * @param list<string> $requestedChanges
     */
    private function writeExecutionSpec(string $feature, string $name, array $requestedChanges = ['Add deterministic event bus scaffolding.']): void
    {
        $requestedChangesBody = implode("\n", array_map(
            static fn(string $item): string => '- ' . $item,
            $requestedChanges,
        ));

        $this->writeRawExecutionSpec($feature, $name, <<<MD
# Execution Spec: {$name}

## Feature

- {$feature}

## Purpose

- Execute a bounded event bus implementation step.

## Scope

- Add deterministic event bus scaffolding.

## Constraints

- Keep execution deterministic.

## Requested Changes

{$requestedChangesBody}
MD);
    }

    private function writeRawExecutionSpec(string $feature, string $name, string $contents): void
    {
        $path = $this->project->root . '/docs/features/' . $feature . '/specs/' . $name . '.md';
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($path, $contents);
    }

    private function writeRawDraftExecutionSpec(string $feature, string $name, string $contents): void
    {
        $path = $this->project->root . '/docs/features/' . $feature . '/specs/drafts/' . $name . '.md';
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($path, $contents);
    }

    private function writeMeaningfulContext(string $feature): void
    {
        file_put_contents($this->project->root . '/docs/features/' . $feature . '/' . $feature . '.spec.md', <<<MD
# Feature Spec: {$feature}

## Purpose

Introduce event bus handling.

## Goals

- Add deterministic event bus feature scaffolding.

## Non-Goals

- Do not add async delivery.

## Constraints

- Keep output deterministic.

## Expected Behavior

- Event bus feature scaffolding exists in the app.

## Acceptance Criteria

- Event bus feature files are present.

## Assumptions

- Initial implementation may be scaffold-first.
MD);

        file_put_contents($this->project->root . '/docs/features/' . $feature . '/' . $feature . '.md', <<<MD
# Feature: {$feature}

## Purpose

Introduce event bus handling.

## Current State

- Event bus feature implementation is pending.

## Open Questions

- None.

## Next Steps

- Event bus feature scaffolding exists in the app.
- Event bus feature files are present.
MD);
    }

    private function writeExecutionSpecSystemContext(): void
    {
        file_put_contents($this->project->root . '/docs/features/execution-spec-system/execution-spec-system.spec.md', <<<MD
# Feature Spec: execution-spec-system

## Purpose

Keep execution-spec implementation logging deterministic.

## Goals

- Preserve canonical execution-spec naming and lifecycle rules.

## Non-Goals

- Do not rename existing execution-spec ids once assigned.

## Constraints

- Automatic implementation logging must not log draft specs, must prevent duplicate entries, and must surface log-write failures clearly and deterministically.

## Expected Behavior

- Successful implement spec runs for active execution specs append one required-format entry to docs/features/implementation-log.md.
- Draft execution specs are never logged as implemented, and repeated completion of the same active spec does not duplicate the log entry.
- If the implementation log cannot be updated, implement spec must surface that failure clearly and deterministically.

## Acceptance Criteria

- Successful active execution-spec implementation appends exactly one correctly formatted implementation-log entry automatically.
- Draft execution specs are not auto-logged.
- Implementation-log write failures surface clearly and deterministically and do not appear as a clean successful completion.

## Assumptions

- Feature directories continue to provide execution-spec context.
MD);

        file_put_contents($this->project->root . '/docs/features/execution-spec-system/execution-spec-system.md', <<<MD
# Feature: execution-spec-system

## Purpose

Keep execution-spec implementation logging deterministic.

## Current State

- Successful implement spec runs for active execution specs append one required-format entry to docs/features/implementation-log.md.
- Draft execution specs are never logged as implemented, and repeated completion of the same active spec does not duplicate the log entry.
- If the implementation log cannot be updated, implement spec must surface that failure clearly and deterministically.
- Successful active execution-spec implementation appends exactly one correctly formatted implementation-log entry automatically.
- Draft execution specs are not auto-logged.
- Implementation-log write failures surface clearly and deterministically and do not appear as a clean successful completion.

## Open Questions

- None.

## Next Steps

- Preserve deterministic automatic implementation logging behavior.
MD);
    }
}
