<?php
declare(strict_types=1);

namespace Foundry\Explain\Collectors;

use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;
use Foundry\Explain\ExplainSupport;

final class GraphNeighborhoodCollector implements ExplainContextCollectorInterface
{
    public function supports(ExplainSubject $subject): bool
    {
        return $subject->graphNodeIds !== [];
    }

    public function collect(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): void
    {
        $dependsOn = [];
        $dependedOnBy = [];

        foreach ($subject->graphNodeIds as $nodeId) {
            foreach ($context->graph->dependencies($nodeId) as $edge) {
                $dependsOn[] = ExplainSupport::summarizeGraphNodeById($context->graph, $edge->to, $edge->type);
            }

            foreach ($context->graph->dependents($nodeId) as $edge) {
                $dependedOnBy[] = ExplainSupport::summarizeGraphNodeById($context->graph, $edge->from, $edge->type);
            }
        }

        $context->set('graph_neighborhood', [
            'depends_on' => ExplainSupport::uniqueRows($dependsOn),
            'depended_on_by' => ExplainSupport::uniqueRows($dependedOnBy),
            'neighbors' => ExplainSupport::uniqueRows(array_merge($dependsOn, $dependedOnBy)),
        ]);
    }
}
