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
        $this->assertSame(['feature', 'doctor', 'alignment', 'summary'], array_keys($result['payload']));
        $this->assertSame('event-bus', $result['payload']['feature']);
        $this->assertSame('ok', $result['payload']['summary']['doctor_status']);
        $this->assertSame('warning', $result['payload']['summary']['alignment_status']);
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
            'doctor_status',
            'alignment_status',
            'issues',
        ], array_keys($first['payload']));
    }

    public function test_compliant_feature_passes_context_verification(): void
    {
        $this->runCommand(['foundry', 'context', 'init', 'event-bus', '--json']);

        $result = $this->runCommand(['foundry', 'verify', 'context', '--feature=event-bus', '--json']);

        $this->assertSame(0, $result['status']);
        $this->assertSame('pass', $result['payload']['status']);
        $this->assertSame('ok', $result['payload']['doctor_status']);
        $this->assertSame('warning', $result['payload']['alignment_status']);
    }

    public function test_repairable_or_non_compliant_or_mismatch_feature_fails_context_verification(): void
    {
        $repairable = $this->runCommand(['foundry', 'verify', 'context', '--feature=event-bus', '--json']);
        $nonCompliant = $this->runCommand(['foundry', 'verify', 'context', '--feature=Event_Bus', '--json']);

        $this->runCommand(['foundry', 'context', 'init', 'blog-comments', '--json']);
        $specPath = $this->project->root . '/docs/features/blog-comments.spec.md';
        file_put_contents($specPath, str_replace(
            "## Acceptance Criteria\n\n- TBD.\n",
            "## Acceptance Criteria\n\n- Comments are enabled.\n",
            (string) file_get_contents($specPath),
        ));
        $statePath = $this->project->root . '/docs/features/blog-comments.md';
        file_put_contents($statePath, str_replace(
            "## Current State\n\nTBD.\n",
            "## Current State\n\nReplay support is pending.\n",
            (string) file_get_contents($statePath),
        ));
        $mismatch = $this->runCommand(['foundry', 'verify', 'context', '--feature=blog-comments', '--json']);

        $this->assertSame(1, $repairable['status']);
        $this->assertSame('fail', $repairable['payload']['status']);
        $this->assertSame('repairable', $repairable['payload']['doctor_status']);

        $this->assertSame(1, $nonCompliant['status']);
        $this->assertSame('fail', $nonCompliant['payload']['status']);
        $this->assertSame('non_compliant', $nonCompliant['payload']['doctor_status']);

        $this->assertSame(1, $mismatch['status']);
        $this->assertSame('fail', $mismatch['payload']['status']);
        $this->assertSame('ok', $mismatch['payload']['doctor_status']);
        $this->assertSame('mismatch', $mismatch['payload']['alignment_status']);
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
        $this->assertSame(['alpha-feature', 'zeta-feature'], $features);
        $this->assertSame(2, $result['payload']['summary']['pass']);
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
