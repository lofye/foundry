<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Context\ContextInitService;
use Foundry\Context\ContextInspectionService;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class ContextInspectionServiceTest extends TestCase
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

    public function test_aggregation_combines_doctor_and_alignment_results_correctly(): void
    {
        $this->initService()->init('event-bus');

        $result = $this->service()->inspectFeature('event-bus');

        $this->assertSame('event-bus', $result['feature']);
        $this->assertTrue($result['can_proceed']);
        $this->assertFalse($result['requires_repair']);
        $this->assertSame('ok', $result['summary']['doctor_status']);
        $this->assertSame('warning', $result['summary']['alignment_status']);
        $this->assertSame('ok', $result['doctor']['status']);
        $this->assertSame('warning', $result['alignment']['status']);
        $this->assertSame([
            'Update the feature state to reflect current implementation.',
        ], $result['required_actions']);
    }

    public function test_verification_mapping_produces_correct_pass_fail_outcomes(): void
    {
        $this->initService()->init('pass-feature');

        $pass = $this->service()->verifyFeature('pass-feature');
        $fail = $this->service()->verifyFeature('missing-feature');

        $this->assertSame('pass', $pass['status']);
        $this->assertTrue($pass['can_proceed']);
        $this->assertFalse($pass['requires_repair']);
        $this->assertSame('ok', $pass['doctor_status']);
        $this->assertSame('warning', $pass['alignment_status']);
        $this->assertSame([
            'Update the feature state to reflect current implementation.',
        ], $pass['required_actions']);
        $this->assertSame('fail', $fail['status']);
        $this->assertFalse($fail['can_proceed']);
        $this->assertTrue($fail['requires_repair']);
        $this->assertSame('repairable', $fail['doctor_status']);
        $this->assertSame('mismatch', $fail['alignment_status']);
    }

    public function test_verify_all_returns_deterministic_feature_ordering(): void
    {
        $this->initService()->init('zeta-feature');
        $this->initService()->init('alpha-feature');

        $result = $this->service()->verifyAll();
        $features = array_values(array_map(
            static fn(array $feature): string => (string) ($feature['feature'] ?? ''),
            $result['features'],
        ));

        $this->assertSame('pass', $result['status']);
        $this->assertTrue($result['can_proceed']);
        $this->assertFalse($result['requires_repair']);
        $this->assertSame(['alpha-feature', 'zeta-feature'], $features);
        $this->assertSame(2, $result['summary']['pass']);
        $this->assertSame(2, $result['summary']['total']);
    }

    private function service(): ContextInspectionService
    {
        return new ContextInspectionService(new Paths($this->project->root));
    }

    private function initService(): ContextInitService
    {
        return new ContextInitService(new Paths($this->project->root));
    }
}
