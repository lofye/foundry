<?php

declare(strict_types=1);

namespace Foundry\Explain\Collectors;

use Foundry\Compiler\ApplicationGraph;
use Foundry\Compiler\IR\GraphNode;
use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;
use Foundry\Explain\ExplainSupport;

final readonly class GraphNeighborhoodCollector implements ExplainContextCollectorInterface
{
    public function __construct(private ApplicationGraph $graph) {}

    public function supports(ExplainSubject $subject): bool
    {
        return $subject->graphNodeIds !== [];
    }

    public function collect(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): void
    {
        $dependencies = [];
        $dependents = [];

        foreach ($subject->graphNodeIds as $nodeId) {
            $node = $this->graph->node($nodeId);
            if ($node instanceof GraphNode && $context->subjectNode() === []) {
                $context->setSubjectNode(array_merge(
                    ExplainSupport::summarizeGraphNode($node),
                    ['payload' => $node->payload()],
                ));
            }

            foreach ($this->graph->dependencies($nodeId) as $edge) {
                $summary = ExplainSupport::summarizeGraphNodeById($this->graph, $edge->to, $edge->type);
                if (($summary['kind'] ?? 'internal') === 'internal') {
                    continue;
                }

                $dependencies[] = $summary;
            }

            foreach ($this->graph->dependents($nodeId) as $edge) {
                $summary = ExplainSupport::summarizeGraphNodeById($this->graph, $edge->from, $edge->type);
                if (($summary['kind'] ?? 'internal') === 'internal') {
                    continue;
                }

                $dependents[] = $summary;
            }
        }

        $context->setGraphNeighborhood([
            'dependencies' => ExplainSupport::uniqueRows($dependencies),
            'dependents' => ExplainSupport::uniqueRows($dependents),
            'inbound' => ExplainSupport::uniqueRows($dependents),
            'outbound' => ExplainSupport::uniqueRows($dependencies),
            'neighbors' => ExplainSupport::uniqueRows(array_merge($dependencies, $dependents)),
        ]);
    }
}
