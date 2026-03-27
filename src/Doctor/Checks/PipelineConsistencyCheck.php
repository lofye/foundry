<?php
declare(strict_types=1);

namespace Foundry\Doctor\Checks;

use Foundry\Compiler\Diagnostics\DiagnosticBag;
use Foundry\Doctor\DoctorCheck;
use Foundry\Doctor\DoctorContext;
use Foundry\Doctor\DoctorSummary;
use Foundry\Pipeline\PipelineIntegrityInspector;

final class PipelineConsistencyCheck implements DoctorCheck
{
    public function __construct(private readonly PipelineIntegrityInspector $inspector = new PipelineIntegrityInspector())
    {
    }

    public function id(): string
    {
        return 'pipeline_consistency';
    }

    public function description(): string
    {
        return 'Validates route, guard, interceptor, and execution-plan consistency across the compiled pipeline.';
    }

    public function check(DoctorContext $context, DiagnosticBag $diagnostics): array
    {
        $report = $this->inspector->inspect($context->compileResult->graph, $context->featureFilter);
        foreach ((array) ($report['issues'] ?? []) as $issue) {
            if (!is_array($issue)) {
                continue;
            }

            $severity = (string) ($issue['severity'] ?? 'warning');
            $method = match ($severity) {
                'error' => 'error',
                'info' => 'info',
                default => 'warning',
            };

            $diagnostics->{$method}(
                code: (string) ($issue['code'] ?? 'FDY9125_PIPELINE_ISSUE'),
                category: (string) ($issue['category'] ?? 'pipeline'),
                message: (string) ($issue['message'] ?? 'Pipeline consistency issue detected.'),
                nodeId: isset($issue['node_id']) ? (string) $issue['node_id'] : null,
                sourcePath: isset($issue['source_path']) ? (string) $issue['source_path'] : null,
                suggestedFix: isset($issue['suggested_fix']) ? (string) $issue['suggested_fix'] : null,
                pass: 'doctor.pipeline_consistency',
                whyItMatters: isset($issue['why_it_matters']) ? (string) $issue['why_it_matters'] : null,
                details: is_array($issue['details'] ?? null) ? $issue['details'] : [],
            );
        }

        $summary = $diagnostics->summary();

        return [
            'status' => DoctorSummary::status($summary),
            'diagnostics_summary' => $summary,
            'summary' => is_array($report['summary'] ?? null) ? $report['summary'] : [],
        ];
    }
}
