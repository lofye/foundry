<?php

declare(strict_types=1);

namespace Foundry\Context;

interface ContextDoctorDiagnosticRule
{
    public function evaluate(ContextDoctorDiagnosticRuleContext $context): ?ContextDoctorDiagnosticRuleResult;
}
