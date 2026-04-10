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
        ], array_keys($result['payload']));
        $this->assertSame('event-bus/001-initial', $result['payload']['spec_id']);
        $this->assertSame('event-bus', $result['payload']['feature']);
        $this->assertSame('completed', $result['payload']['status']);
        $this->assertContains('Applied execution spec: docs/specs/event-bus/001-initial.md', $result['payload']['actions_taken']);
        $this->assertFileExists($this->project->root . '/app/features/event-bus/feature.yaml');
    }

    public function test_conflicting_execution_spec_is_blocked(): void
    {
        $this->runCommand(['foundry', 'context', 'init', 'event-bus', '--json']);
        $this->writeMeaningfulContext('event-bus');
        $specPath = $this->project->root . '/docs/features/event-bus.spec.md';
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

    public function test_repair_flag_reuses_feature_execution_pipeline(): void
    {
        $this->runCommand(['foundry', 'context', 'init', 'event-bus', '--json']);
        $this->writeMeaningfulContext('event-bus');
        $this->writeExecutionSpec('event-bus', '001-initial');
        unlink($this->project->root . '/docs/features/event-bus.md');

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
        $path = $this->project->root . '/docs/features/event-bus.spec.md';
        file_put_contents($path, str_replace('# Feature Spec: event-bus', '# Spec: event-bus', (string) file_get_contents($path)));

        $result = $this->runCommand(['foundry', 'implement', 'spec', 'event-bus/001-initial', '--auto-repair', '--json']);

        $this->assertSame(0, $result['status']);
        $this->assertSame('repaired', $result['payload']['status']);
        $this->assertTrue($result['payload']['repair_attempted']);
        $this->assertTrue($result['payload']['repair_successful']);
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
     * @param list<string> $requestedChanges
     */
    private function writeExecutionSpec(string $feature, string $name, array $requestedChanges = ['Add deterministic event bus scaffolding.']): void
    {
        $path = $this->project->root . '/docs/specs/' . $feature . '/' . $name . '.md';
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $requestedChangesBody = implode("\n", array_map(
            static fn(string $item): string => '- ' . $item,
            $requestedChanges,
        ));

        file_put_contents($path, <<<MD
# Execution Spec: {$feature}/{$name}

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

    private function writeMeaningfulContext(string $feature): void
    {
        file_put_contents($this->project->root . '/docs/features/' . $feature . '.spec.md', <<<MD
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

        file_put_contents($this->project->root . '/docs/features/' . $feature . '.md', <<<MD
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
}
