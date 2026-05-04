<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Context\ContextDoctorService;
use Foundry\Context\ContextInitService;
use Foundry\Support\FoundryError;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class ContextInitAndDoctorServiceTest extends TestCase
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

    public function test_init_uses_canonical_feature_paths_when_features_root_exists(): void
    {
        mkdir($this->project->root . '/Features', 0777, true);

        $result = $this->initService()->init('event-bus');

        $this->assertTrue($result['success']);
        $this->assertSame([
            'Features/EventBus/event-bus.spec.md',
            'Features/EventBus/event-bus.md',
            'Features/EventBus/event-bus.decisions.md',
        ], $result['created']);
    }

    public function test_init_returns_validation_issues_for_invalid_feature_name(): void
    {
        $result = $this->initService()->init('EventBus');

        $this->assertFalse($result['success']);
        $this->assertFalse($result['feature_valid']);
        $this->assertSame([], $result['created']);
        $this->assertSame('CONTEXT_FEATURE_NAME_UPPERCASE', $result['issues'][0]['code']);
    }

    public function test_init_reports_existing_files_on_second_run(): void
    {
        $this->initService()->init('event-bus');

        $result = $this->initService()->init('event-bus');

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['created']);
        $this->assertSame([
            'docs/features/event-bus/event-bus.spec.md',
            'docs/features/event-bus/event-bus.md',
            'docs/features/event-bus/event-bus.decisions.md',
        ], $result['existing']);
    }

    public function test_init_fails_when_context_file_path_is_blocked_by_directory(): void
    {
        $blocked = $this->project->root . '/docs/features/event-bus/event-bus.spec.md';
        mkdir($blocked, 0777, true);

        $this->expectException(FoundryError::class);
        $this->expectExceptionMessage('Context file path exists but is not a file.');

        try {
            $this->initService()->init('event-bus');
        } catch (FoundryError $error) {
            $this->assertSame('CONTEXT_FILE_PATH_BLOCKED', $error->errorCode);
            $this->assertSame('docs/features/event-bus/event-bus.spec.md', $error->details['path']);
            throw $error;
        }
    }

    public function test_doctor_returns_non_compliant_for_invalid_feature_name(): void
    {
        $result = $this->doctorService()->checkFeature('EventBus');

        $this->assertSame('non_compliant', $result['status']);
        $this->assertFalse($result['can_proceed']);
        $this->assertTrue($result['requires_repair']);
        $this->assertSame(['Use a lowercase kebab-case feature name.'], $result['required_actions']);
    }

    public function test_doctor_check_all_aggregates_initialized_feature_as_ok(): void
    {
        $this->initService()->init('event-bus');

        $result = $this->doctorService()->checkAll();

        $this->assertSame('ok', $result['status']);
        $this->assertTrue($result['can_proceed']);
        $this->assertFalse($result['requires_repair']);
        $this->assertSame(1, $result['summary']['ok']);
        $this->assertSame(1, $result['summary']['total']);
        $this->assertSame('event-bus', $result['features'][0]['feature']);
    }

    public function test_doctor_check_all_discovers_canonical_features_and_collects_required_actions(): void
    {
        $canonicalDir = $this->project->root . '/Features/EventBus';
        mkdir($canonicalDir . '/specs', 0777, true);
        file_put_contents($canonicalDir . '/event-bus.spec.md', "# Feature Spec: event-bus\n\n## Purpose\n\nx\n");
        file_put_contents($canonicalDir . '/event-bus.md', "# Feature: event-bus\n\n## Current State\n\nx\n\n## Open Questions\n\nx\n\n## Next Steps\n\nx\n");
        file_put_contents($canonicalDir . '/event-bus.decisions.md', "## Decision 001\n\n- **Context**: x\n- **Decision**: x\n- **Reasoning**: x\n- **Alternatives**: x\n- **Impact**: x\n");
        file_put_contents($canonicalDir . '/specs/001-example.md', "# Execution Spec: 001-example\n");

        mkdir($this->project->root . '/Features/not-canonical', 0777, true);
        mkdir($this->project->root . '/docs/features/invalid_name', 0777, true);

        $result = $this->doctorService()->checkAll();

        $this->assertGreaterThanOrEqual(1, $result['summary']['total']);
        $this->assertContains('event-bus', array_map(static fn(array $row): string => (string) $row['feature'], $result['features']));
    }

    public function test_doctor_flatten_issues_skips_non_array_rows_and_preserves_sections(): void
    {
        $issues = $this->doctorService()->flattenIssues([
            'files' => [
                'spec' => [
                    'path' => 'Features/EventBus/event-bus.spec.md',
                    'issues' => [
                        'invalid-row',
                        [
                            'code' => 'CONTEXT_SPEC_SECTION_MISSING',
                            'message' => 'Missing section',
                            'file_path' => 'Features/EventBus/event-bus.spec.md',
                            'section' => 'Goals',
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertCount(1, $issues);
        $this->assertSame('doctor', $issues[0]['source']);
        $this->assertSame('Goals', $issues[0]['section']);
    }

    public function test_init_fails_when_feature_context_directory_cannot_be_created(): void
    {
        $parent = $this->project->root . '/docs/features';
        if (!is_dir($parent)) {
            mkdir($parent, 0777, true);
        }
        file_put_contents($parent . '/event-bus', 'blocked');

        $this->expectException(FoundryError::class);
        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            if ($severity === E_WARNING) {
                $warnings[] = $message;

                return true;
            }

            return false;
        });

        try {
            $this->initService()->init('event-bus');
        } catch (FoundryError $error) {
            $this->assertSame('CONTEXT_DIRECTORY_CREATE_FAILED', $error->errorCode);
            $this->assertSame('docs/features/event-bus', $error->details['path']);
            $this->assertNotSame([], $warnings);
            throw $error;
        } finally {
            restore_error_handler();
        }
    }

    public function test_init_fails_when_context_file_cannot_be_written(): void
    {
        $directory = $this->project->root . '/docs/features/event-bus';
        mkdir($directory, 0777, true);
        file_put_contents($directory . '/event-bus.spec.md', '# existing');
        chmod($directory, 0555);

        $this->expectException(FoundryError::class);
        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            if ($severity === E_WARNING) {
                $warnings[] = $message;

                return true;
            }

            return false;
        });

        try {
            $this->initService()->init('event-bus');
        } catch (FoundryError $error) {
            $this->assertSame('CONTEXT_FILE_WRITE_FAILED', $error->errorCode);
            $this->assertSame('docs/features/event-bus/event-bus.md', $error->details['path']);
            $this->assertNotSame([], $warnings);
            throw $error;
        } finally {
            restore_error_handler();
            chmod($directory, 0755);
        }
    }

    private function initService(): ContextInitService
    {
        return new ContextInitService(new Paths($this->project->root));
    }

    private function doctorService(): ContextDoctorService
    {
        return new ContextDoctorService(new Paths($this->project->root));
    }
}
