<?php
declare(strict_types=1);

namespace Foundry\Doctor;

use Foundry\Compiler\Diagnostics\DiagnosticBag;

interface DoctorCheck
{
    public function id(): string;

    public function description(): string;

    /**
     * @return array<string,mixed>
     */
    public function check(DoctorContext $context, DiagnosticBag $diagnostics): array;
}
