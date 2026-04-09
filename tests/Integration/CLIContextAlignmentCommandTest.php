<?php

declare(strict_types=1);

namespace Foundry\Tests\Integration;

use Foundry\CLI\Application;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class CLIContextAlignmentCommandTest extends TestCase
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

    public function test_context_check_alignment_json_returns_expected_issues(): void
    {
        $this->writeFeatureFiles(
            'event-bus',
            $this->spec('Events are replayable.'),
            $this->state('Replay support is still pending.'),
            '',
        );

        $result = $this->runCommand(['foundry', 'context', 'check-alignment', '--feature=event-bus', '--json']);

        $this->assertSame(1, $result['status']);
        $this->assertSame(['status', 'feature', 'can_proceed', 'requires_repair', 'issues', 'required_actions'], array_keys($result['payload']));
        $this->assertSame('mismatch', $result['payload']['status']);
        $this->assertSame('event-bus', $result['payload']['feature']);
        $this->assertFalse($result['payload']['can_proceed']);
        $this->assertTrue($result['payload']['requires_repair']);
        $this->assertSame('untracked_spec_requirement', $result['payload']['issues'][0]['code']);
    }

    public function test_compliant_feature_returns_ok(): void
    {
        $this->writeFeatureFiles(
            'event-bus',
            $this->spec('Events are replayable.'),
            $this->state('Events are replayable.'),
            '',
        );

        $result = $this->runCommand(['foundry', 'context', 'check-alignment', '--feature=event-bus', '--json']);

        $this->assertSame(0, $result['status']);
        $this->assertSame('ok', $result['payload']['status']);
        $this->assertTrue($result['payload']['can_proceed']);
        $this->assertFalse($result['payload']['requires_repair']);
        $this->assertSame([], $result['payload']['issues']);
        $this->assertSame([], $result['payload']['required_actions']);
    }

    public function test_obviously_divergent_feature_returns_mismatch(): void
    {
        $this->writeFeatureFiles(
            'event-bus',
            $this->spec('Publishes posts.'),
            $this->state('Comments are enabled.'),
            '',
        );

        $result = $this->runCommand(['foundry', 'context', 'check-alignment', '--feature=event-bus', '--json']);
        $codes = array_values(array_map(
            static fn(array $issue): string => (string) ($issue['code'] ?? ''),
            $result['payload']['issues'],
        ));

        $this->assertSame(1, $result['status']);
        $this->assertSame('mismatch', $result['payload']['status']);
        $this->assertFalse($result['payload']['can_proceed']);
        $this->assertTrue($result['payload']['requires_repair']);
        $this->assertContains('unsupported_state_claim', $codes);
    }

    public function test_context_check_alignment_requires_feature_and_rejects_all(): void
    {
        $result = $this->runCommand(['foundry', 'context', 'check-alignment', '--all', '--json']);

        $this->assertSame(1, $result['status']);
        $this->assertSame('CLI_CONTEXT_ALIGNMENT_ALL_UNSUPPORTED', $result['payload']['error']['code']);
        $this->assertSame('Context check-alignment requires --feature=<feature>.', $result['payload']['error']['message']);
    }

    private function writeFeatureFiles(string $feature, string $spec, string $state, string $decisions): void
    {
        $directory = $this->project->root . '/docs/features';
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($directory . '/' . $feature . '.spec.md', $spec);
        file_put_contents($directory . '/' . $feature . '.md', $state);
        file_put_contents($directory . '/' . $feature . '.decisions.md', $decisions);
    }

    private function spec(string $acceptanceCriteria): string
    {
        return <<<MD
        # Feature Spec: event-bus

        ## Purpose

        Replay event flow.

        ## Goals

        - Capture replay behavior.

        ## Non-Goals

        - TBD.

        ## Constraints

        - Deterministic output only.

        ## Expected Behavior

        {$acceptanceCriteria}

        ## Acceptance Criteria

        - {$acceptanceCriteria}

        ## Assumptions

        - Existing events remain available.
        MD;
    }

    private function state(string $currentState): string
    {
        return <<<MD
        # Feature: event-bus

        ## Purpose

        Replay event flow.

        ## Current State

        {$currentState}

        ## Open Questions

        - None.

        ## Next Steps

        - None.
        MD;
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
}
