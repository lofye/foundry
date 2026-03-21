<?php
declare(strict_types=1);

namespace Foundry\Explain;

final readonly class ExplainResponse
{
    public function __construct(
        public ExplanationPlan $plan,
        public string $rendered,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return $this->plan->toArray();
    }
}
