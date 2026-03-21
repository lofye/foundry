<?php
declare(strict_types=1);

namespace Foundry\Explain\Collectors;

use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;
use Foundry\Explain\ExplainSupport;

final class WorkflowContextCollector implements ExplainContextCollectorInterface
{
    public function supports(ExplainSubject $subject): bool
    {
        return in_array($subject->kind, ['workflow', 'event'], true);
    }

    public function collect(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): void
    {
        $rows = [];

        if ($subject->kind === 'workflow') {
            $resource = trim((string) ($subject->metadata['resource'] ?? $subject->label));
            $workflow = $context->artifacts->workflowIndex()[$resource] ?? null;
            if (is_array($workflow)) {
                $rows[$resource] = $workflow;
            }
        }

        if ($subject->kind === 'event') {
            foreach ($subject->graphNodeIds as $nodeId) {
                foreach ($context->graph->dependents($nodeId) as $edge) {
                    if ($edge->type !== 'workflow_to_event_emit') {
                        continue;
                    }

                    $node = $context->graph->node($edge->from);
                    if ($node !== null) {
                        $rows[$node->id()] = array_merge(
                            ['id' => $node->id(), 'label' => ExplainSupport::nodeLabel($node)],
                            $node->payload(),
                        );
                    }
                }
            }
        }

        ksort($rows);
        $context->set('workflows', ['items' => array_values($rows)]);
    }
}
