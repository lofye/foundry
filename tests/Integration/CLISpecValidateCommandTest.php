<?php

declare(strict_types=1);

namespace Foundry\Tests\Integration;

use Foundry\CLI\Application;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class CLISpecValidateCommandTest extends TestCase
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

    public function test_spec_validate_success_output_and_payload_are_stable(): void
    {
        $this->writeSpec('execution-spec-system', '001-hierarchical-spec-ids-with-padded-segments');
        $this->writeSpec('execution-spec-system', '002-spec-new-cli-command', 'drafts');
        $this->writeImplementationLogEntry('execution-spec-system/001-hierarchical-spec-ids-with-padded-segments.md');

        $json = $this->runCommand(['foundry', 'spec:validate', '--json']);
        $raw = $this->runRawCommand(['foundry', 'spec:validate']);

        $this->assertSame(0, $json['status']);
        $this->assertTrue($json['payload']['ok']);
        $this->assertSame(
            ['checked_files' => 2, 'features' => 1, 'violations' => 0],
            $json['payload']['summary'],
        );
        $this->assertSame([], $json['payload']['violations']);

        $this->assertSame(0, $raw['status']);
        $this->assertSame(<<<'TEXT'
Spec validation passed

Checked files: 2
Violations: 0
TEXT . "\n", $raw['output']);
    }

    public function test_spec_validate_reports_all_violations_and_exits_non_zero(): void
    {
        $this->writeSpec('execution-spec-system', '001-first-active');
        $this->writeSpec('execution-spec-system', '001-second-draft', 'drafts');
        $this->writeRawFile(
            'docs/features/execution-spec-system/specs/002-bad-heading.md',
            '# Execution Spec: execution-spec-system/002-bad-heading' . "\n",
        );
        $this->writeRawFile(
            'docs/features/execution-spec-system/specs/003-with-status.md',
            "# Execution Spec: 003-with-status\n\nstatus: draft\n",
        );
        $this->writeRawFile(
            'docs/features/execution-spec-system/specs/not-a-spec.md',
            '# Execution Spec: not-a-spec' . "\n",
        );
        $this->writeImplementationLogEntry('execution-spec-system/001-first-active.md');
        $this->writeImplementationLogEntry('execution-spec-system/002-bad-heading.md');
        $this->writeImplementationLogEntry('execution-spec-system/003-with-status.md');

        $json = $this->runCommand(['foundry', 'spec:validate', '--json']);
        $raw = $this->runRawCommand(['foundry', 'spec:validate']);

        $this->assertSame(1, $json['status']);
        $this->assertFalse($json['payload']['ok']);
        $this->assertSame(
            [
                'EXECUTION_SPEC_DUPLICATE_ID',
                'EXECUTION_SPEC_INVALID_HEADING',
                'EXECUTION_SPEC_FORBIDDEN_METADATA',
                'EXECUTION_SPEC_INVALID_FILENAME',
            ],
            array_map(
                static fn(array $violation): string => (string) $violation['code'],
                $json['payload']['violations'],
            ),
        );

        $this->assertSame(1, $raw['status']);
        $this->assertStringContainsString('Spec validation failed', $raw['output']);
        $this->assertStringContainsString('EXECUTION_SPEC_DUPLICATE_ID', $raw['output']);
        $this->assertStringContainsString('paths=docs/features/execution-spec-system/specs/001-first-active.md, docs/features/execution-spec-system/specs/drafts/001-second-draft.md', $raw['output']);
        $this->assertStringContainsString('EXECUTION_SPEC_INVALID_HEADING', $raw['output']);
        $this->assertStringContainsString('expected_heading=# Execution Spec: 002-bad-heading', $raw['output']);
        $this->assertStringContainsString('actual_heading=# Execution Spec: execution-spec-system/002-bad-heading', $raw['output']);
        $this->assertStringContainsString('EXECUTION_SPEC_FORBIDDEN_METADATA', $raw['output']);
        $this->assertStringContainsString('field=status; line=3', $raw['output']);
        $this->assertStringContainsString('EXECUTION_SPEC_INVALID_FILENAME', $raw['output']);
        $this->assertStringContainsString('Summary:', $raw['output']);
        $this->assertStringContainsString('Violations: 4', $raw['output']);
    }

    public function test_spec_validate_reports_missing_implementation_log_entries_for_active_specs(): void
    {
        $this->writeSpec('execution-spec-system', '001-active-missing-log');
        $this->writeSpec('execution-spec-system', '002-draft-missing-log', 'drafts');

        $json = $this->runCommand(['foundry', 'spec:validate', '--json']);
        $raw = $this->runRawCommand(['foundry', 'spec:validate']);

        $this->assertSame(1, $json['status']);
        $this->assertFalse($json['payload']['ok']);
        $this->assertSame(
            ['EXECUTION_SPEC_IMPLEMENTATION_LOG_MISSING'],
            array_map(
                static fn(array $violation): string => (string) $violation['code'],
                $json['payload']['violations'],
            ),
        );
        $this->assertSame(
            'execution-spec-system/001-active-missing-log.md',
            $json['payload']['violations'][0]['details']['spec'],
        );

        $this->assertSame(1, $raw['status']);
        $this->assertStringContainsString('EXECUTION_SPEC_IMPLEMENTATION_LOG_MISSING', $raw['output']);
        $this->assertStringContainsString('docs/features/execution-spec-system/specs/001-active-missing-log.md', $raw['output']);
    }

    public function test_spec_validate_require_plans_enforces_missing_active_plan_only_when_enabled(): void
    {
        $this->writeSpec('execution-spec-system', '001-active-without-plan');
        $this->writeSpec('execution-spec-system', '002-draft-without-plan', 'drafts');
        $this->writeImplementationLogEntry('execution-spec-system/001-active-without-plan.md');

        $default = $this->runCommand(['foundry', 'spec:validate', '--json']);
        $strict = $this->runCommand(['foundry', 'spec:validate', '--require-plans', '--json']);

        $this->assertSame(0, $default['status']);
        $this->assertTrue($default['payload']['ok']);

        $this->assertSame(1, $strict['status']);
        $this->assertFalse($strict['payload']['ok']);
        $this->assertSame('EXECUTION_SPEC_PLAN_REQUIRED_MISSING', $strict['payload']['violations'][0]['code']);
        $this->assertSame(
            'docs/features/execution-spec-system/plans/001-active-without-plan.md',
            $strict['payload']['violations'][0]['details']['plan_path'],
        );
    }

    public function test_spec_validate_reports_sequential_gap_details_in_json_and_plain_text(): void
    {
        $this->writeSpec('execution-spec-system', '001-first');
        $this->writeSpec('execution-spec-system', '003-third');
        $this->writeImplementationLogEntry('execution-spec-system/001-first.md');
        $this->writeImplementationLogEntry('execution-spec-system/003-third.md');

        $json = $this->runCommand(['foundry', 'spec:validate', '--json']);
        $raw = $this->runRawCommand(['foundry', 'spec:validate']);

        $this->assertSame(1, $json['status']);
        $this->assertFalse($json['payload']['ok']);
        $gap = array_values(array_filter(
            $json['payload']['violations'],
            static fn(array $violation): bool => (string) $violation['code'] === 'EXECUTION_SPEC_ID_GAP',
        ))[0];
        $this->assertSame('002', $gap['details']['missing_id']);
        $this->assertSame('003', $gap['details']['next_observed_id']);
        $this->assertSame('active', $gap['details']['location']);
        $this->assertSame('top-level', $gap['details']['parent_id']);

        $this->assertSame(1, $raw['status']);
        $this->assertStringContainsString('EXECUTION_SPEC_ID_GAP', $raw['output']);
        $this->assertStringContainsString('location=active', $raw['output']);
        $this->assertStringContainsString('parent_id=top-level', $raw['output']);
        $this->assertStringContainsString('missing_id=002', $raw['output']);
        $this->assertStringContainsString('expected_missing_id=002', $raw['output']);
        $this->assertStringContainsString('next_observed_id=003', $raw['output']);
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

    private function writeSpec(string $feature, string $name, string $subdirectory = ''): void
    {
        $this->writeRawFile(
            'docs/features/' . $feature . '/specs' . ($subdirectory !== '' ? '/' . $subdirectory : '') . '/' . $name . '.md',
            '# Execution Spec: ' . $name . "\n",
        );
    }

    private function writeRawFile(string $relativePath, string $contents): void
    {
        $absolutePath = $this->project->root . '/' . $relativePath;
        $directory = dirname($absolutePath);

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($absolutePath, $contents);
    }

    private function writeImplementationLogEntry(string $specReference): void
    {
        $absolutePath = $this->project->root . '/docs/features/implementation-log.md';
        $directory = dirname($absolutePath);

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $entry = "## 2026-04-17 12:00:00 -0400\n- spec: {$specReference}\n";
        $existing = file_exists($absolutePath) ? (string) file_get_contents($absolutePath) : '';
        $contents = $existing === '' ? $entry : rtrim($existing, "\n") . "\n\n" . $entry;

        file_put_contents($absolutePath, $contents);
    }
}
