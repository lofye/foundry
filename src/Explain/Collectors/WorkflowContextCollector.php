<?php

declare(strict_types=1);

namespace Foundry\Explain\Collectors;

use Foundry\Compiler\ApplicationGraph;
use Foundry\Compiler\IR\GraphNode;
use Foundry\Explain\ExplainArtifactCatalog;
use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOrigin;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;
use Foundry\Explain\ExplainSupport;

final readonly class WorkflowContextCollector implements ExplainContextCollectorInterface
{
    public function __construct(
        private ApplicationGraph $graph,
        private ExplainArtifactCatalog $artifacts,
    ) {}

    public function supports(ExplainSubject $subject): bool
    {
        return in_array($subject->kind, ['feature', 'route', 'workflow', 'event'], true);
    }

    public function collect(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): void
    {
        $rows = [];

        if ($subject->kind === 'workflow') {
            $resource = trim((string) ($subject->metadata['resource'] ?? $subject->label));
            $workflow = $this->artifacts->workflowIndex()[$resource] ?? null;
            if (is_array($workflow)) {
                $rows[$resource] = $this->normalizeWorkflowRow('workflow:' . $resource, $workflow);
            }
        }

        if (in_array($subject->kind, ['feature', 'route'], true)) {
            foreach ($this->workflowRowsFromRelatedGraphNodes($subject) as $id => $row) {
                $rows[$id] = $row;
            }

            $eventContext = $context->events();
            foreach (array_keys((array) ($eventContext['emitted'] ?? [])) as $eventName) {
                foreach ($this->workflowRowsForEventName((string) $eventName) as $id => $row) {
                    $rows[$id] = $row;
                }
            }
        }

        if ($subject->kind === 'event') {
            foreach ($subject->graphNodeIds as $nodeId) {
                foreach ($this->workflowRowsForEventNode($nodeId) as $id => $row) {
                    $rows[$id] = $row;
                }
            }
        }

        $context->setWorkflows(['items' => ExplainOrigin::sortAttributedRows(array_values($rows))]);
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function workflowRowsFromRelatedGraphNodes(ExplainSubject $subject): array
    {
        $rows = [];

        foreach ($subject->graphNodeIds as $nodeId) {
            foreach ($this->graph->dependencies($nodeId) as $edge) {
                $node = $this->graph->node($edge->to);
                if ($node !== null && $node->type() === 'workflow') {
                    $rows[$node->id()] = $this->workflowRow($node->id());
                }

                if ($node !== null && $node->type() === 'event') {
                    foreach ($this->workflowRowsForEventNode($node->id()) as $id => $row) {
                        $rows[$id] = $row;
                    }
                }
            }

            foreach ($this->graph->dependents($nodeId) as $edge) {
                $node = $this->graph->node($edge->from);
                if ($node !== null && $node->type() === 'workflow') {
                    $rows[$node->id()] = $this->workflowRow($node->id());
                }
            }
        }

        return array_filter($rows, 'is_array');
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function workflowRowsForEventNode(string $nodeId): array
    {
        $rows = [];
        foreach ($this->graph->dependents($nodeId) as $edge) {
            $node = $this->graph->node($edge->from);
            if ($node === null || $node->type() !== 'workflow') {
                continue;
            }

            $rows[$edge->from] = $this->workflowRow($edge->from);
        }

        return array_filter($rows, 'is_array');
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function workflowRowsForEventName(string $eventName): array
    {
        $eventName = trim($eventName);
        if ($eventName === '') {
            return [];
        }

        return $this->workflowRowsForEventNode('event:' . $eventName);
    }

    /**
     * @return array<string,mixed>
     */
    private function workflowRow(string $nodeId): array
    {
        $node = $this->graph->node($nodeId);
        if ($node === null) {
            return [];
        }

        return $this->normalizeWorkflowRow($node->id(), $node->payload(), $node);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function normalizeWorkflowRow(string $id, array $row, ?GraphNode $node = null): array
    {
        $resource = trim((string) ($row['resource'] ?? $row['name'] ?? $id));
        $transitions = is_array($row['transitions'] ?? null) ? $row['transitions'] : [];
        $emits = [];
        foreach ($transitions as $transition) {
            if (!is_array($transition)) {
                continue;
            }

            foreach ((array) ($transition['emit'] ?? []) as $eventName) {
                $emits[] = [
                    'id' => 'event:' . (string) $eventName,
                    'kind' => 'event',
                    'label' => (string) $eventName,
                    'name' => (string) $eventName,
                ];
            }
        }

        return [
            'id' => $id,
            'kind' => 'workflow',
            'label' => $resource !== '' ? $resource : ($node !== null ? ExplainSupport::nodeLabel($node) : $id),
            'resource' => $resource,
            'source_path' => $node?->sourcePath(),
            'extension' => $node !== null ? (trim((string) ($node->payload()['extension'] ?? '')) ?: null) : null,
            'states' => array_values(array_map('strval', (array) ($row['states'] ?? []))),
            'transitions' => $transitions,
            'emits' => ExplainSupport::uniqueRows($emits),
        ];
    }
}
