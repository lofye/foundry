<?php

declare(strict_types=1);

namespace Foundry\Context;

final class DecisionMissingForStateDivergenceContextDoctorRule implements ContextDoctorDiagnosticRule
{
    private const string CODE = 'DECISION_MISSING_FOR_STATE_DIVERGENCE';
    private const string MESSAGE = 'Current State diverges from the canonical spec without a supporting decision entry.';

    public function __construct(
        private readonly AlignmentChecker $alignmentChecker = new AlignmentChecker(),
    ) {}

    public function evaluate(ContextDoctorDiagnosticRuleContext $context): ?ContextDoctorDiagnosticRuleResult
    {
        if (!$context->hasUsableFiles('spec', 'state', 'decisions')) {
            return null;
        }

        $alignment = $this->alignmentChecker->check(
            $context->fileContents('spec'),
            $context->fileContents('state'),
            $context->fileContents('decisions'),
        )->toArray($context->feature);

        foreach ((array) ($alignment['issues'] ?? []) as $issue) {
            if (!is_array($issue) || (string) ($issue['code'] ?? '') !== 'missing_decision_reference') {
                continue;
            }

            return new ContextDoctorDiagnosticRuleResult(
                code: self::CODE,
                message: self::MESSAGE,
                targets: [
                    new ContextDoctorDiagnosticTarget(
                        bucket: 'decisions',
                        filePath: $context->filePath('decisions'),
                    ),
                ],
                requiredActions: [
                    'Add a decision entry to ' . $context->filePath('decisions') . ' that explains the spec-state divergence.',
                ],
                requiresRepair: true,
            );
        }

        return null;
    }
}
