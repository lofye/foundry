<?php
declare(strict_types=1);

namespace Foundry\Explain;

interface ExplainEngineInterface
{
    public function explain(ExplainTarget $target, ExplainOptions $options): ExplanationPlan;
}
