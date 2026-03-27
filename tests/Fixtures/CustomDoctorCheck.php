<?php
declare(strict_types=1);

namespace Foundry\Tests\Fixtures;

use Foundry\Compiler\Diagnostics\DiagnosticBag;
use Foundry\Doctor\DoctorCheck;
use Foundry\Doctor\DoctorContext;
use Foundry\Doctor\DoctorSummary;

final class CustomDoctorCheck implements DoctorCheck
{
    public function id(): string
    {
        return 'fixture_custom_doctor';
    }

    public function description(): string
    {
        return 'Fixture doctor check used by automated tests.';
    }

    public function check(DoctorContext $context, DiagnosticBag $diagnostics): array
    {
        $diagnostics->warning(
            code: 'FDY9901_FIXTURE_DOCTOR_CHECK',
            category: 'testing',
            message: 'Fixture doctor check executed for ' . $context->projectType() . '.',
            suggestedFix: 'Remove the fixture extension registration when you no longer need the test diagnostic.',
            pass: 'doctor.fixture_custom_doctor',
            whyItMatters: 'This fixture proves that application- and extension-registered doctor checks are loaded into the doctor pipeline.',
            details: ['project_type' => $context->projectType()],
        );

        $summary = $diagnostics->summary();

        return [
            'status' => DoctorSummary::status($summary),
            'diagnostics_summary' => $summary,
            'project_type' => $context->projectType(),
        ];
    }
}
