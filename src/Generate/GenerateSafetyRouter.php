<?php

declare(strict_types=1);

namespace Foundry\Generate;

final class GenerateSafetyRouter
{
    /**
     * @param null|\Closure():bool $ciDetector
     */
    public function __construct(
        private readonly ?GeneratePlanRiskAnalyzer $riskAnalyzer = null,
        private readonly ?\Closure $ciDetector = null,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function route(Intent $intent, GenerationPlan $plan): array
    {
        $risk = ($this->riskAnalyzer ?? new GeneratePlanRiskAnalyzer())->analyze($plan);
        $ci = $this->isCi();
        $confidenceBand = trim((string) ($plan->confidence['band'] ?? 'unknown'));
        $confidenceScore = isset($plan->confidence['score']) ? (float) $plan->confidence['score'] : null;
        $recommendedMode = 'non_interactive';
        $reasonCodes = [];
        $reasons = [];
        $forced = false;

        if ($intent->interactive) {
            $recommendedMode = 'interactive';
            $forced = true;
            $reasonCodes[] = 'explicit_interactive';
            $reasons[] = 'Explicit interactive mode overrides automatic routing.';
        } elseif ($ci) {
            $recommendedMode = 'non_interactive';
            $reasonCodes[] = 'ci_context';
            $reasons[] = 'CI/CD context prefers non-interactive execution because interactive review is not available.';
        } elseif (($risk['level'] ?? 'LOW') !== 'LOW') {
            $recommendedMode = 'interactive';
            $reasonCodes[] = 'elevated_risk';
            $reasons[] = 'Medium or high plan risk routes through interactive review.';
        } elseif ($intent->mode === 'new') {
            $recommendedMode = 'non_interactive';
            $reasonCodes[] = 'low_risk_additive';
            $reasons[] = 'Low-risk additive scaffolding can use the fast non-interactive path.';
        } elseif (in_array($confidenceBand, ['low', 'very_low'], true)) {
            $recommendedMode = 'interactive';
            $reasonCodes[] = 'low_plan_confidence';
            $reasons[] = 'Low plan confidence routes through interactive review for safer approval.';
        } else {
            $recommendedMode = 'non_interactive';
            $reasonCodes[] = 'low_risk_additive';
            $reasons[] = 'Low-risk additive work can use the fast non-interactive path.';
        }

        return [
            'skill' => [
                'name' => 'generate-with-safety-routing',
                'contract_version' => 1,
            ],
            'recommended_mode' => $recommendedMode,
            'recommended_interactive' => $recommendedMode === 'interactive',
            'recommended_flags' => $recommendedMode === 'interactive' ? ['--interactive'] : [],
            'forced_by_user' => $forced,
            'signals' => [
                'ci' => $ci,
                'intent_mode' => $intent->mode,
                'interactive_requested' => $intent->interactive,
                'risk_level' => (string) ($risk['level'] ?? 'LOW'),
                'risk_reasons' => array_values(array_map('strval', (array) ($risk['reasons'] ?? []))),
                'plan_confidence_band' => $confidenceBand,
                'plan_confidence_score' => $confidenceScore,
            ],
            'reason_codes' => $reasonCodes,
            'reasons' => $reasons,
        ];
    }

    private function isCi(): bool
    {
        if ($this->ciDetector instanceof \Closure) {
            return (bool) ($this->ciDetector)();
        }

        $value = getenv('CI');
        if (!is_string($value)) {
            return false;
        }

        $normalized = strtolower(trim($value));

        return $normalized !== '' && !in_array($normalized, ['0', 'false', 'no', 'off'], true);
    }
}
