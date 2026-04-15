<?php

declare(strict_types=1);

namespace Foundry\Context;

final class ExecutionSpecDriftContextDoctorRule implements ContextDoctorDiagnosticRule
{
    private const string CODE = 'EXECUTION_SPEC_DRIFT';
    private const string MESSAGE = 'Execution specs exist for this feature, but canonical feature context is missing or incomplete.';

    public function evaluate(ContextDoctorDiagnosticRuleContext $context): ?ContextDoctorDiagnosticRuleResult
    {
        if (!$context->featureHasExecutionSpecs) {
            return null;
        }

        $targets = $context->missingCanonicalTargets();
        if ($targets === []) {
            return null;
        }

        return new ContextDoctorDiagnosticRuleResult(
            code: self::CODE,
            message: self::MESSAGE,
            targets: $targets,
            requiredActions: [
                'Create or initialize the missing canonical feature context files for ' . $context->feature . '.',
                'Run foundry context init ' . $context->feature . ' --json when appropriate to initialize missing canonical context files.',
                'Do not rely on execution specs as the source of truth for ' . $context->feature . '.',
            ],
            requiresRepair: true,
        );
    }
}
