<?php
declare(strict_types=1);

namespace Foundry\Explain\Renderers;

use Foundry\Explain\ExplanationPlan;
use Foundry\Support\Json;

final class JsonExplanationRenderer implements ExplanationRendererInterface
{
    public function render(ExplanationPlan $plan): string
    {
        return Json::encode($plan->toArray(), true);
    }
}
