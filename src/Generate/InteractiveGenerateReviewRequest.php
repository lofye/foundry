<?php

declare(strict_types=1);

namespace Foundry\Generate;

final readonly class InteractiveGenerateReviewRequest
{
    public function __construct(
        public Intent $intent,
        public GenerationPlan $plan,
        public GenerationContextPacket $context,
        public ?string $explainRendered = null,
    ) {}
}
