<?php

declare(strict_types=1);

namespace Foundry\Generate;

final readonly class InteractiveGenerateReviewResult
{
    /**
     * @param array<int,array<string,mixed>> $userDecisions
     * @param array<string,mixed> $preview
     * @param array<string,mixed> $risk
     */
    public function __construct(
        public bool $approved,
        public GenerationPlan $plan,
        public array $userDecisions = [],
        public array $preview = [],
        public array $risk = [],
        public bool $allowRisky = false,
        public bool $modified = false,
        public bool $allowPolicyViolations = false,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'enabled' => true,
            'approved' => $this->approved,
            'rejected' => !$this->approved,
            'allow_risky' => $this->allowRisky,
            'allow_policy_violations' => $this->allowPolicyViolations,
            'modified' => $this->modified,
            'user_decisions' => $this->userDecisions,
            'preview' => $this->preview,
            'risk' => $this->risk,
        ];
    }
}
