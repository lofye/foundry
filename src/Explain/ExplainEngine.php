<?php
declare(strict_types=1);

namespace Foundry\Explain;

use Foundry\Compiler\ApplicationGraph;
use Foundry\Explain\Collectors\ExplainContextCollectorInterface;

final class ExplainEngine implements ExplainEngineInterface
{
    /**
     * @param array<int,ExplainContextCollectorInterface> $collectors
     */
    public function __construct(
        private readonly ApplicationGraph $graph,
        private readonly ExplainTargetResolver $resolver,
        private readonly ExplanationPlanAssembler $planAssembler,
        private readonly array $collectors,
        private readonly string $commandPrefix,
    ) {
    }

    public function explain(ExplainTarget $target, ExplainOptions $options): ExplanationPlan
    {
        $subject = $this->resolver->resolve($target);
        $context = new ExplainContext($subject, $this->commandPrefix);

        foreach ($this->collectors as $collector) {
            if ($collector->supports($subject)) {
                $collector->collect($subject, $context, $options);
            }
        }

        return $this->planAssembler->assemble(
            subject: $subject,
            context: $context,
            options: $options,
            metadata: $this->metadata($target, $options, $context),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function metadata(ExplainTarget $target, ExplainOptions $options, ExplainContext $context): array
    {
        $impact = $context->impact();

        return [
            'schema_version' => 2,
            'target' => $target->toArray(),
            'options' => $options->toArray(),
            'graph' => [
                'graph_version' => $this->graph->graphVersion(),
                'framework_version' => $this->graph->frameworkVersion(),
                'source_hash' => $this->graph->sourceHash(),
            ],
            'command_prefix' => $this->commandPrefix,
            'impact' => is_array($impact) ? $impact : null,
        ];
    }
}
