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
            'docs/specs/execution-spec-system/002-bad-heading.md',
            '# Execution Spec: execution-spec-system/002-bad-heading' . "\n",
        );
        $this->writeRawFile(
            'docs/specs/execution-spec-system/003-with-status.md',
            "# Execution Spec: 003-with-status\n\nstatus: draft\n",
        );
        $this->writeRawFile(
            'docs/specs/execution-spec-system/not-a-spec.md',
            '# Execution Spec: not-a-spec' . "\n",
        );

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
        $this->assertStringContainsString('paths=docs/specs/execution-spec-system/001-first-active.md, docs/specs/execution-spec-system/drafts/001-second-draft.md', $raw['output']);
        $this->assertStringContainsString('EXECUTION_SPEC_INVALID_HEADING', $raw['output']);
        $this->assertStringContainsString('EXECUTION_SPEC_FORBIDDEN_METADATA', $raw['output']);
        $this->assertStringContainsString('field=status; line=3', $raw['output']);
        $this->assertStringContainsString('EXECUTION_SPEC_INVALID_FILENAME', $raw['output']);
        $this->assertStringContainsString('Summary:', $raw['output']);
        $this->assertStringContainsString('Violations: 4', $raw['output']);
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
            'docs/specs/' . $feature . ($subdirectory !== '' ? '/' . $subdirectory : '') . '/' . $name . '.md',
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
}
