<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Compiler\Extensions\VersionConstraint;
use PHPUnit\Framework\TestCase;

final class VersionConstraintTest extends TestCase
{
    public function test_matches_supports_wildcard_exact_and_caret_constraints(): void
    {
        $this->assertTrue(VersionConstraint::matches('1.2.3', '*'));
        $this->assertTrue(VersionConstraint::matches('1.2.3', '1.2.3'));
        $this->assertFalse(VersionConstraint::matches('1.2.4', '1.2.3'));

        $this->assertTrue(VersionConstraint::matches('1.5.0', '^1.0'));
        $this->assertFalse(VersionConstraint::matches('2.0.0', '^1.0'));

        $this->assertTrue(VersionConstraint::matches('0.5.3', '^0.5'));
        $this->assertFalse(VersionConstraint::matches('0.6.0', '^0.5'));

        $this->assertTrue(VersionConstraint::matches('dev-main', '*'));
        $this->assertFalse(VersionConstraint::matches('dev-main', '^1'));
    }
}
