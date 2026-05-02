<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Context\ExecutionSpec;
use Foundry\Context\ExecutionSpecImplementationLogService;
use Foundry\Support\FoundryError;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class ExecutionSpecImplementationLogServiceTest extends TestCase
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

    public function test_record_if_eligible_appends_required_log_entry_format(): void
    {
        $service = $this->serviceAt('2026-04-14 16:05:06 -0400');

        $action = $service->recordIfEligible($this->activeExecutionSpec());

        $this->assertSame('Appended implementation log entry: docs/features/implementation-log.md', $action);
        $this->assertSame(<<<'MD'
## 2026-04-14 16:05:06 -0400
- spec: execution-spec-system/004-spec-auto-log-on-implementation.md
MD . "\n", $this->logContents());
    }

    public function test_suggested_entry_returns_exact_canonical_log_entry_content(): void
    {
        $payload = $this->serviceAt('2026-04-14 16:05:06 -0400')->suggestedEntry($this->activeExecutionSpec());

        $this->assertSame([
            'spec_id' => 'execution-spec-system/004-spec-auto-log-on-implementation',
            'feature' => 'execution-spec-system',
            'spec_ref' => 'execution-spec-system/004-spec-auto-log-on-implementation.md',
            'spec_path' => 'docs/features/execution-spec-system/specs/004-spec-auto-log-on-implementation.md',
            'log_path' => 'docs/features/implementation-log.md',
            'timestamp' => '2026-04-14 16:05:06 -0400',
            'timestamp_heading' => '## 2026-04-14 16:05:06 -0400',
            'spec_log_line' => '- spec: execution-spec-system/004-spec-auto-log-on-implementation.md',
            'entry' => "## 2026-04-14 16:05:06 -0400\n- spec: execution-spec-system/004-spec-auto-log-on-implementation.md\n",
        ], $payload);
    }

    public function test_suggested_entry_skips_draft_specs(): void
    {
        $payload = $this->serviceAt('2026-04-14 16:05:06 -0400')->suggestedEntry(
            new ExecutionSpec(
                specId: 'execution-spec-system/004-spec-auto-log-on-implementation',
                feature: 'execution-spec-system',
                path: 'docs/features/execution-spec-system/specs/drafts/004-spec-auto-log-on-implementation.md',
                name: '004-spec-auto-log-on-implementation',
                id: '004',
            ),
        );

        $this->assertNull($payload);
    }

    public function test_record_if_eligible_is_idempotent_for_existing_spec_entry(): void
    {
        $service = $this->serviceAt('2026-04-14 16:05:06 -0400');

        $first = $service->recordIfEligible($this->activeExecutionSpec());
        $second = $service->recordIfEligible($this->activeExecutionSpec());

        $this->assertSame('Appended implementation log entry: docs/features/implementation-log.md', $first);
        $this->assertNull($second);
        $this->assertSame(1, preg_match_all('/^- spec: execution-spec-system\/004-spec-auto-log-on-implementation\.md$/m', $this->logContents()));
    }

    public function test_record_if_eligible_skips_draft_specs(): void
    {
        $action = $this->serviceAt('2026-04-14 16:05:06 -0400')->recordIfEligible(
            new ExecutionSpec(
                specId: 'execution-spec-system/004-spec-auto-log-on-implementation',
                feature: 'execution-spec-system',
                path: 'docs/features/execution-spec-system/specs/drafts/004-spec-auto-log-on-implementation.md',
                name: '004-spec-auto-log-on-implementation',
                id: '004',
            ),
        );

        $this->assertNull($action);
        $this->assertFileDoesNotExist($this->project->root . '/docs/features/implementation-log.md');
    }

    public function test_record_if_eligible_fails_clearly_when_log_path_is_not_writable(): void
    {
        mkdir($this->project->root . '/docs/features/implementation-log.md', 0777, true);

        $this->expectException(FoundryError::class);
        $this->expectExceptionMessage('Execution spec implementation log path must be a file.');

        try {
            $this->serviceAt('2026-04-14 16:05:06 -0400')->recordIfEligible($this->activeExecutionSpec());
        } catch (FoundryError $error) {
            $this->assertSame('EXECUTION_SPEC_IMPLEMENTATION_LOG_WRITE_FAILED', $error->errorCode);
            $this->assertSame(['path' => 'docs/features/implementation-log.md'], $error->details);

            throw $error;
        }
    }

    private function serviceAt(string $timestamp): ExecutionSpecImplementationLogService
    {
        return new ExecutionSpecImplementationLogService(
            new Paths($this->project->root),
            static fn(): \DateTimeImmutable => new \DateTimeImmutable($timestamp),
        );
    }

    private function activeExecutionSpec(): ExecutionSpec
    {
        return new ExecutionSpec(
            specId: 'execution-spec-system/004-spec-auto-log-on-implementation',
            feature: 'execution-spec-system',
            path: 'docs/features/execution-spec-system/specs/004-spec-auto-log-on-implementation.md',
            name: '004-spec-auto-log-on-implementation',
            id: '004',
        );
    }

    private function logContents(): string
    {
        return (string) file_get_contents($this->project->root . '/docs/features/implementation-log.md');
    }
}
