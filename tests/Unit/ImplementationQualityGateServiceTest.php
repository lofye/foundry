<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Quality\ImplementationQualityGateService;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class ImplementationQualityGateServiceTest extends TestCase
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

    public function test_quality_gate_passes_when_full_suite_and_coverage_meet_threshold(): void
    {
        $result = $this->service()->verify();

        $this->assertTrue($result['passed']);
        $this->assertSame('passed', $result['status']);
        $this->assertTrue($result['full_suite']['ran']);
        $this->assertTrue($result['full_suite']['passed']);
        $this->assertTrue($result['coverage']['ran']);
        $this->assertTrue($result['coverage']['passed']);
        $this->assertSame(95.0, $result['coverage']['global_line_coverage']);
        $this->assertFalse($result['changed_surface']['supported']);
        $this->assertSame('not_supported', $result['changed_surface']['status']);
    }

    public function test_quality_gate_fails_when_full_suite_fails(): void
    {
        file_put_contents($this->project->root . '/.foundry-test-phpunit-exit-code', "1\n");

        $result = $this->service()->verify();

        $this->assertFalse($result['passed']);
        $this->assertSame('failed', $result['status']);
        $this->assertSame('IMPLEMENTATION_QUALITY_GATE_FULL_SUITE_FAILED', $result['issues'][0]['code']);
        $this->assertFalse($result['coverage']['ran']);
    }

    public function test_quality_gate_fails_when_coverage_run_fails(): void
    {
        file_put_contents($this->project->root . '/.foundry-test-coverage-exit-code', "1\n");

        $result = $this->service()->verify();

        $this->assertFalse($result['passed']);
        $this->assertSame('IMPLEMENTATION_QUALITY_GATE_COVERAGE_FAILED', $result['issues'][0]['code']);
        $this->assertTrue($result['full_suite']['passed']);
        $this->assertTrue($result['coverage']['ran']);
        $this->assertFalse($result['coverage']['passed']);
    }

    public function test_quality_gate_fails_when_global_coverage_is_below_threshold(): void
    {
        file_put_contents($this->project->root . '/.foundry-test-coverage-lines', "89.50\n");

        $result = $this->service()->verify();

        $this->assertFalse($result['passed']);
        $this->assertSame('IMPLEMENTATION_QUALITY_GATE_GLOBAL_COVERAGE_BELOW_THRESHOLD', $result['issues'][0]['code']);
        $this->assertSame(89.5, $result['coverage']['global_line_coverage']);
        $this->assertFalse($result['coverage']['meets_threshold']);
    }

    public function test_quality_gate_fails_when_coverage_output_is_unparseable(): void
    {
        file_put_contents($this->project->root . '/.foundry-test-coverage-output', "Coverage completed without a summary line.\n");

        $result = $this->service()->verify();

        $this->assertFalse($result['passed']);
        $this->assertSame('IMPLEMENTATION_QUALITY_GATE_COVERAGE_UNPARSEABLE', $result['issues'][0]['code']);
        $this->assertNull($result['coverage']['global_line_coverage']);
        $this->assertNull($result['coverage']['meets_threshold']);
    }

    private function service(): ImplementationQualityGateService
    {
        return new ImplementationQualityGateService(new Paths($this->project->root));
    }
}
