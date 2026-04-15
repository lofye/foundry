<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Context\ContextDoctorService;
use Foundry\Context\ContextFileResolver;
use Foundry\Context\ContextInitService;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class ContextServicesTest extends TestCase
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

    public function test_init_service_creates_missing_files_correctly(): void
    {
        $result = $this->initService()->init('event-bus');

        $this->assertTrue($result['success']);
        $this->assertSame('event-bus', $result['feature']);
        $this->assertTrue($result['feature_valid']);
        $this->assertSame([
            'docs/features/event-bus.spec.md',
            'docs/features/event-bus.md',
            'docs/features/event-bus.decisions.md',
        ], $result['created']);
        $this->assertSame([], $result['existing']);
        $this->assertFileExists($this->project->root . '/docs/features/event-bus.spec.md');
        $this->assertFileExists($this->project->root . '/docs/features/event-bus.md');
        $this->assertFileExists($this->project->root . '/docs/features/event-bus.decisions.md');
        $this->assertStringContainsString('# Feature Spec: event-bus', (string) file_get_contents($this->project->root . '/docs/features/event-bus.spec.md'));
        $this->assertStringContainsString('Timestamp: <ISO-8601>', (string) file_get_contents($this->project->root . '/docs/features/event-bus.decisions.md'));
    }

    public function test_init_service_does_not_overwrite_existing_files(): void
    {
        $path = $this->project->root . '/docs/features/event-bus.spec.md';
        $this->writeFile($path, "# Custom Spec\n");

        $result = $this->initService()->init('event-bus');

        $this->assertSame([
            'docs/features/event-bus.md',
            'docs/features/event-bus.decisions.md',
        ], $result['created']);
        $this->assertSame(['docs/features/event-bus.spec.md'], $result['existing']);
        $this->assertSame("# Custom Spec\n", file_get_contents($path));
    }

    public function test_init_service_normalizes_underscore_input_to_canonical_feature_paths(): void
    {
        $result = $this->initService()->init('event_bus');

        $this->assertTrue($result['success']);
        $this->assertSame('event-bus', $result['feature']);
        $this->assertSame([
            'docs/features/event-bus.spec.md',
            'docs/features/event-bus.md',
            'docs/features/event-bus.decisions.md',
        ], $result['created']);
        $this->assertFileExists($this->project->root . '/docs/features/event-bus.spec.md');
        $this->assertFileDoesNotExist($this->project->root . '/docs/features/event_bus.spec.md');
    }

    public function test_doctor_service_maps_validation_results_to_statuses_consistently(): void
    {
        $doctor = $this->doctorService();

        $missing = $doctor->checkFeature('event-bus');
        $invalid = $doctor->checkFeature('Event_Bus');

        $this->assertSame('repairable', $missing['status']);
        $this->assertFalse($missing['can_proceed']);
        $this->assertTrue($missing['requires_repair']);

        $this->assertSame('non_compliant', $invalid['status']);
        $this->assertFalse($invalid['can_proceed']);
        $this->assertTrue($invalid['requires_repair']);

        $this->initService()->init('event-bus');
        $ok = $doctor->checkFeature('event-bus');
        $this->assertSame('ok', $ok['status']);
        $this->assertTrue($ok['can_proceed']);
        $this->assertFalse($ok['requires_repair']);
    }

    public function test_doctor_service_generates_required_actions_correctly(): void
    {
        $this->initService()->init('event-bus');
        $resolver = new ContextFileResolver();
        $this->writeFile(
            $this->project->root . '/' . $resolver->specPath('event-bus'),
            str_replace(
                ["# Feature Spec: event-bus\n", "## Goals\n\n- TBD.\n\n"],
                ["# Spec: event-bus\n", ''],
                (string) file_get_contents($this->project->root . '/' . $resolver->specPath('event-bus')),
            ),
        );

        $result = $this->doctorService()->checkFeature('event-bus');

        $this->assertSame('repairable', $result['status']);
        $this->assertFalse($result['can_proceed']);
        $this->assertTrue($result['requires_repair']);
        $this->assertContains('Fix malformed spec heading in docs/features/event-bus.spec.md.', $result['required_actions']);
        $this->assertContains('Add missing required section "## Goals" to docs/features/event-bus.spec.md.', $result['required_actions']);
    }

    public function test_doctor_service_does_not_emit_execution_spec_drift_without_execution_specs(): void
    {
        $result = $this->doctorService()->checkFeature('event-bus');

        $this->assertSame(['CONTEXT_FILE_MISSING'], $this->doctorIssueCodes($result, 'spec'));
        $this->assertSame(['CONTEXT_FILE_MISSING'], $this->doctorIssueCodes($result, 'state'));
        $this->assertSame(['CONTEXT_FILE_MISSING'], $this->doctorIssueCodes($result, 'decisions'));
        $this->assertNotContains(
            'Run foundry context init event-bus --json when appropriate to initialize missing canonical context files.',
            $result['required_actions'],
        );
    }

    public function test_doctor_service_does_not_emit_execution_spec_drift_when_canonical_context_exists(): void
    {
        $this->initService()->init('event-bus');
        $this->writeExecutionSpec('event-bus', '001-initial');

        $result = $this->doctorService()->checkFeature('event-bus');

        $this->assertSame('ok', $result['status']);
        $this->assertSame([], $this->doctorIssueCodes($result, 'spec'));
        $this->assertSame([], $this->doctorIssueCodes($result, 'state'));
        $this->assertSame([], $this->doctorIssueCodes($result, 'decisions'));
        $this->assertSame([], $result['required_actions']);
    }

    public function test_doctor_service_emits_execution_spec_drift_for_active_specs_when_canonical_context_is_missing(): void
    {
        $this->writeExecutionSpec('event-bus', '001-initial');

        $result = $this->doctorService()->checkFeature('event-bus');

        $this->assertSame('repairable', $result['status']);
        $this->assertFalse($result['can_proceed']);
        $this->assertTrue($result['requires_repair']);
        $this->assertSame(['CONTEXT_FILE_MISSING', 'EXECUTION_SPEC_DRIFT'], $this->doctorIssueCodes($result, 'spec'));
        $this->assertSame(['CONTEXT_FILE_MISSING', 'EXECUTION_SPEC_DRIFT'], $this->doctorIssueCodes($result, 'state'));
        $this->assertSame(['CONTEXT_FILE_MISSING', 'EXECUTION_SPEC_DRIFT'], $this->doctorIssueCodes($result, 'decisions'));
        $this->assertSame([
            'Create missing spec file: docs/features/event-bus.spec.md',
            'Create missing state file: docs/features/event-bus.md',
            'Create missing decision ledger: docs/features/event-bus.decisions.md',
            'Create or initialize the missing canonical feature context files for event-bus.',
            'Run foundry context init event-bus --json when appropriate to initialize missing canonical context files.',
            'Do not rely on execution specs as the source of truth for event-bus.',
        ], $result['required_actions']);
    }

    public function test_doctor_service_emits_execution_spec_drift_for_draft_specs_when_context_is_incomplete(): void
    {
        $this->initService()->init('event-bus');
        $this->writeExecutionSpec('event-bus', '001-initial', draft: true);
        unlink($this->project->root . '/docs/features/event-bus.md');

        $result = $this->doctorService()->checkFeature('event-bus');

        $this->assertSame('repairable', $result['status']);
        $this->assertSame([], $this->doctorIssueCodes($result, 'spec'));
        $this->assertSame(['CONTEXT_FILE_MISSING', 'EXECUTION_SPEC_DRIFT'], $this->doctorIssueCodes($result, 'state'));
        $this->assertSame([], $this->doctorIssueCodes($result, 'decisions'));
        $this->assertSame([
            'Create missing state file: docs/features/event-bus.md',
            'Create or initialize the missing canonical feature context files for event-bus.',
            'Run foundry context init event-bus --json when appropriate to initialize missing canonical context files.',
            'Do not rely on execution specs as the source of truth for event-bus.',
        ], $result['required_actions']);
    }

    private function initService(): ContextInitService
    {
        return new ContextInitService(new Paths($this->project->root));
    }

    private function doctorService(): ContextDoctorService
    {
        return new ContextDoctorService(new Paths($this->project->root));
    }

    private function writeFile(string $path, string $contents): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($path, $contents);
    }

    private function writeExecutionSpec(string $feature, string $name, bool $draft = false): void
    {
        $directory = $this->project->root . '/docs/specs/' . $feature . ($draft ? '/drafts' : '');
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $path = $directory . '/' . $name . '.md';
        file_put_contents($path, <<<MD
# Execution Spec: {$name}

## Feature
- {$feature}
MD);
    }

    /**
     * @param array<string,mixed> $result
     * @return list<string>
     */
    private function doctorIssueCodes(array $result, string $kind): array
    {
        return array_values(array_map(
            static fn(array $issue): string => (string) ($issue['code'] ?? ''),
            (array) (($result['files'][$kind] ?? [])['issues'] ?? []),
        ));
    }
}
