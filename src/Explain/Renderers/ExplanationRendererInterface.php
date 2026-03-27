<?php

declare(strict_types=1);

namespace Foundry\Explain\Renderers;

use Foundry\Explain\ExplanationPlan;

interface ExplanationRendererInterface
{
    public function render(ExplanationPlan $plan): string;
}
