<?php
declare(strict_types=1);

namespace Foundry\Tests\Fixtures;

use Foundry\Compiler\Extensions\AbstractCompilerExtension;
use Foundry\Doctor\DoctorCheck;

final class CustomDoctorExtension extends AbstractCompilerExtension
{
    public function name(): string
    {
        return 'tests.custom_doctor';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    /**
     * @return array<int,DoctorCheck>
     */
    public function doctorChecks(): array
    {
        return [new CustomDoctorCheck()];
    }
}
