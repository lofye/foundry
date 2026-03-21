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
        return in_array($subject->kind, ['feature', 'route', 'workflow', 'event'], true);
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

        if (in_array($subject->kind, ['feature', 'route'], true)) {
            foreach ($this->workflowRowsFromRelatedGraphNodes($subject, $context) as $id => $row) {
                $rows[$id] = $row;
            }

            $eventContext = (array) $context->get('events', []);
            foreach (array_keys((array) ($eventContext['emitted'] ?? [])) as $eventName) {
                foreach ($this->workflowRowsForEventName((string) $eventName, $context) as $id => $row) {
                    $rows[$id] = $row;
                }
            }
        }

        if ($subject->kind === 'event') {
            foreach ($subject->graphNodeIds as $nodeId) {
                foreach ($this->workflowRowsForEventNode($nodeId, $context) as $id => $row) {
                    $rows[$id] = $row;
                }
            }
        }

        ksort($rows);
        $context->set('workflows', ['items' => array_values($rows)]);
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function workflowRowsFromRelatedGraphNodes(ExplainSubject $subject, ExplainContext $context): array
    {
        $rows = [];

        foreach ($subject->graphNodeIds as $nodeId) {
            foreach ($context->graph->dependencies($nodeId) as $edge) {
                $node = $context->graph->node($edge->to);
                if ($node !== null && $node->type() === 'workflow') {
                    $rows[$node->id()] = $this->workflowRow($node->id(), $context);
                }

                if ($node !== null && $node->type() === 'event') {
                    foreach ($this->workflowRowsForEventNode($node->id(), $context) as $id => $row) {
                        $rows[$id] = $row;
                    }
                }
            }

            foreach ($context->graph->dependents($nodeId) as $edge) {
                $node = $context->graph->node($edge->from);
                if ($node !== null && $node->type() === 'workflow') {
                    $rows[$node->id()] = $this->workflowRow($node->id(), $context);
                }
            }
        }

        return array_filter($rows, 'is_array');
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function workflowRowsForEventNode(string $nodeId, ExplainContext $context): array
    {
        $rows = [];
        foreach ($context->graph->dependents($nodeId) as $edge) {
            if ($edge->type !== 'workflow_to_event_emit') {
                continue;
            }

            $rows[$edge->from] = $this->workflowRow($edge->from, $context);
        }

        return array_filter($rows, 'is_array');
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function workflowRowsForEventName(string $eventName, ExplainContext $context): array
    {
        $eventName = trim($eventName);
        if ($eventName === '') {
            return [];
        }

        return $this->workflowRowsForEventNode('event:' . $eventName, $context);
    }

    /**
     * @return array<string,mixed>
     */
    private function workflowRow(string $nodeId, ExplainContext $context): array
    {
        $node = $context->graph->node($nodeId);
        if ($node === null) {
            return [];
        }

        return array_merge(
            ['id' => $node->id(), 'label' => ExplainSupport::nodeLabel($node)],
            $node->payload(),
        );
    }
}
