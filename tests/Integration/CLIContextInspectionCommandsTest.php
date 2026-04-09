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
        $this->assertFalse($repairable['payload']['can_proceed']);
        $this->assertTrue($repairable['payload']['requires_repair']);
        $this->assertSame('repairable', $repairable['payload']['doctor_status']);
        $this->assertContains('Create missing spec file: docs/features/event-bus.spec.md', $repairable['payload']['required_actions']);

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
        $this->assertTrue($result['payload']['can_proceed']);
        $this->assertFalse($result['payload']['requires_repair']);
        $this->assertSame(['alpha-feature', 'zeta-feature'], $features);
        $this->assertSame(2, $result['payload']['summary']['pass']);
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
        $this->assertStringContainsString('Create missing spec file: docs/features/event-bus.spec.md', $text['output']);
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
}
