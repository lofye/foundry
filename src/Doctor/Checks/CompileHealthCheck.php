<?php
declare(strict_types=1);

namespace Foundry\Doctor\Checks;

use Foundry\Compiler\Diagnostics\DiagnosticBag;
use Foundry\Doctor\DoctorCheck;
use Foundry\Doctor\DoctorContext;
use Foundry\Doctor\DoctorSummary;

final class CompileHealthCheck implements DoctorCheck
{
    public function id(): string
    {
        return 'compile_health';
    }

    public function description(): string
    {
        return 'Summarizes compile-time config, schema, graph, and validation diagnostics.';
    }

    public function check(DoctorContext $context, DiagnosticBag $diagnostics): array
    {
        $items = $context->compileResult->diagnostics->toArray();
        $summary = $context->compileResult->diagnostics->summary();

        $categories = [];
        foreach ($items as $item) {
            $category = (string) ($item['category'] ?? 'unknown');
            $categories[$category] = ($categories[$category] ?? 0) + 1;
        }
        ksort($categories);

        return [
            'status' => DoctorSummary::status($summary),
            'diagnostics_summary' => $summary,
            'categories' => $categories,
            'mode' => $context->compileResult->plan->mode,
            'incremental' => $context->compileResult->plan->incremental,
            'reason' => $context->compileResult->plan->reason,
        ];
    }
}
