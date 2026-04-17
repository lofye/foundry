<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Context\ExecutionSpecValidationService;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class ExecutionSpecValidationServiceTest extends TestCase
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

    public function test_validate_passes_for_canonical_active_and_draft_specs(): void
    {
        $this->writeSpec(
            'execution-spec-system',
            '001-hierarchical-spec-ids-with-padded-segments',
            <<<'MD'
# Execution Spec: 001-hierarchical-spec-ids-with-padded-segments

```yaml
status: draft
```
MD,
        );
        $this->writeSpec(
            'execution-spec-system',
            '002-spec-new-cli-command',
            '# Execution Spec: 002-spec-new-cli-command',
            'drafts',
        );
        $this->writeImplementationLogEntry('execution-spec-system/001-hierarchical-spec-ids-with-padded-segments.md');

        $result = $this->service()->validate();

        $this->assertTrue($result['ok']);
        $this->assertSame(
            ['checked_files' => 2, 'features' => 1, 'violations' => 0],
            $result['summary'],
        );
        $this->assertSame([], $result['violations']);
    }

    public function test_validate_reports_rule_violations_deterministically(): void
    {
        $this->writeSpec('execution-spec-system', '001-first-active', '# Execution Spec: 001-first-active');
        $this->writeSpec('execution-spec-system', '001-second-draft', '# Execution Spec: 001-second-draft', 'drafts');
        $this->writeSpec(
            'execution-spec-system',
            '002-bad-heading',
            '# Execution Spec: execution-spec-system/002-bad-heading',
        );
        $this->writeSpec(
            'execution-spec-system',
            '003-with-status',
            <<<'MD'
# Execution Spec: 003-with-status

status: draft
MD,
        );
        $this->writeRawFile(
            'docs/specs/execution-spec-system/archive/004-misplaced.md',
            '# Execution Spec: 004-misplaced' . "\n",
        );
        $this->writeRawFile(
            'docs/specs/execution-spec-system/not-a-spec.md',
            '# Execution Spec: not-a-spec' . "\n",
        );
        $this->writeImplementationLogEntry('execution-spec-system/001-first-active.md');
        $this->writeImplementationLogEntry('execution-spec-system/002-bad-heading.md');
        $this->writeImplementationLogEntry('execution-spec-system/003-with-status.md');

        $result = $this->service()->validate();

        $this->assertFalse($result['ok']);
        $this->assertSame(
            ['checked_files' => 6, 'features' => 1, 'violations' => 5],
            $result['summary'],
        );
        $this->assertSame(
            [
                'EXECUTION_SPEC_DUPLICATE_ID',
                'EXECUTION_SPEC_INVALID_HEADING',
                'EXECUTION_SPEC_FORBIDDEN_METADATA',
                'EXECUTION_SPEC_INVALID_DIRECTORY',
                'EXECUTION_SPEC_INVALID_FILENAME',
            ],
            array_map(
                static fn(array $violation): string => (string) $violation['code'],
                $result['violations'],
            ),
        );
        $this->assertSame(
            [
                'feature' => 'execution-spec-system',
                'id' => '001',
                'paths' => [
                    'docs/specs/execution-spec-system/001-first-active.md',
                    'docs/specs/execution-spec-system/drafts/001-second-draft.md',
                ],
            ],
            $result['violations'][0]['details'],
        );
        $this->assertSame(['field' => 'status', 'line' => 3], $result['violations'][2]['details']);
    }

    public function test_validate_ignores_forbidden_metadata_inside_fenced_code_blocks(): void
    {
        $this->writeSpec(
            'execution-spec-system',
            '001-code-sample',
            <<<'MD'
# Execution Spec: 001-code-sample

```yaml
id: 001
parent: 000
status: draft
```
MD,
        );
        $this->writeImplementationLogEntry('execution-spec-system/001-code-sample.md');

        $result = $this->service()->validate();

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['violations']);
    }

    public function test_validate_requires_matching_implementation_log_entries_for_active_specs_only(): void
    {
        $this->writeSpec('execution-spec-system', '001-active-with-log', '# Execution Spec: 001-active-with-log');
        $this->writeSpec('execution-spec-system', '002-draft-without-log', '# Execution Spec: 002-draft-without-log', 'drafts');
        $this->writeImplementationLogEntry('execution-spec-system/001-active-with-log.md');

        $result = $this->service()->validate();

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['violations']);
    }

    public function test_validate_reports_missing_implementation_log_entries_for_active_specs(): void
    {
        $this->writeSpec('execution-spec-system', '001-active-missing-log', '# Execution Spec: 001-active-missing-log');
        $this->writeSpec('execution-spec-system', '002-draft-missing-log', '# Execution Spec: 002-draft-missing-log', 'drafts');

        $result = $this->service()->validate();

        $this->assertFalse($result['ok']);
        $this->assertSame(
            [
                'code' => 'EXECUTION_SPEC_IMPLEMENTATION_LOG_MISSING',
                'message' => 'Active execution specs must have a matching implementation-log entry.',
                'file_path' => 'docs/specs/execution-spec-system/001-active-missing-log.md',
                'details' => [
                    'spec' => 'execution-spec-system/001-active-missing-log.md',
                    'log_path' => 'docs/specs/implementation-log.md',
                ],
            ],
            $result['violations'][0],
        );
    }

    public function test_validate_matches_implementation_log_entries_exactly_and_stably(): void
    {
        $this->writeSpec('execution-spec-system', '001-exact-match-required', '# Execution Spec: 001-exact-match-required');
        $this->writeImplementationLogEntry('execution-spec-system/001-exact-match-required-typo.md');

        $first = $this->service()->validate();
        $second = $this->service()->validate();

        $this->assertSame($first, $second);
        $this->assertFalse($first['ok']);
        $this->assertSame('EXECUTION_SPEC_IMPLEMENTATION_LOG_MISSING', $first['violations'][0]['code']);
        $this->assertSame(
            'execution-spec-system/001-exact-match-required.md',
            $first['violations'][0]['details']['spec'],
        );
    }

    private function service(): ExecutionSpecValidationService
    {
        return new ExecutionSpecValidationService(new Paths($this->project->root));
    }

    private function writeSpec(string $feature, string $name, string $contents, string $subdirectory = ''): void
    {
        $relativePath = 'docs/specs/' . $feature . ($subdirectory !== '' ? '/' . $subdirectory : '') . '/' . $name . '.md';
        $this->writeRawFile($relativePath, rtrim($contents, "\n") . "\n");
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
        $absolutePath = $this->project->root . '/docs/specs/implementation-log.md';
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
