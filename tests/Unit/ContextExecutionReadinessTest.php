<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Context\ContextExecutionReadiness;
use PHPUnit\Framework\TestCase;

final class ContextExecutionReadinessTest extends TestCase
{
    public function test_can_proceed_is_derived_from_doctor_and_alignment_statuses(): void
    {
        $this->assertSame(
            ['can_proceed' => true, 'requires_repair' => false],
            ContextExecutionReadiness::fromDoctorAndAlignment('ok', 'warning'),
        );
        $this->assertSame(
            ['can_proceed' => false, 'requires_repair' => true],
            ContextExecutionReadiness::fromDoctorAndAlignment('repairable', 'ok'),
        );
        $this->assertSame(
            ['can_proceed' => false, 'requires_repair' => true],
            ContextExecutionReadiness::fromDoctorAndAlignment('ok', 'mismatch'),
        );
    }

    public function test_verify_status_aligns_with_can_proceed(): void
    {
        $pass = ContextExecutionReadiness::verifyStatus('ok', 'warning');
        $fail = ContextExecutionReadiness::verifyStatus('non_compliant', 'mismatch');

        $this->assertSame('pass', $pass);
        $this->assertSame(
            ['can_proceed' => true, 'requires_repair' => false],
            ContextExecutionReadiness::fromVerifyStatus($pass),
        );

        $this->assertSame('fail', $fail);
        $this->assertSame(
            ['can_proceed' => false, 'requires_repair' => true],
            ContextExecutionReadiness::fromVerifyStatus($fail),
        );
    }
}
