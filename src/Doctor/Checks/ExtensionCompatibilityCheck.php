<?php
declare(strict_types=1);

namespace Foundry\Doctor\Checks;

use Foundry\Compiler\Diagnostics\DiagnosticBag;
use Foundry\Doctor\DoctorCheck;
use Foundry\Doctor\DoctorContext;
use Foundry\Doctor\DoctorSummary;

final class ExtensionCompatibilityCheck implements DoctorCheck
{
    public function id(): string
    {
        return 'extension_compatibility';
    }

    public function description(): string
    {
        return 'Summarizes extension registration, lifecycle, and compatibility diagnostics.';
    }

    public function check(DoctorContext $context, DiagnosticBag $diagnostics): array
    {
        $summary = DoctorSummary::fromRows($context->extensionReport->diagnostics);

        return [
            'status' => DoctorSummary::status($summary),
            'diagnostics_summary' => $summary,
            'ok' => $context->extensionReport->ok,
            'load_order' => $context->extensionReport->loadOrder,
            'registration_sources' => $context->extensionRegistry->registrationSources(),
            'diagnostic_codes' => array_values(array_map(
                static fn (array $row): string => (string) ($row['code'] ?? ''),
                $context->extensionReport->diagnostics,
            )),
        ];
    }
}
