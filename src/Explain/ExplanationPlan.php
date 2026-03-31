<?php

declare(strict_types=1);

namespace Foundry\Explain;

final class ExplanationPlan
{
    /**
     * @param array<string,mixed> $subject
     * @param array<string,mixed> $summary
     * @param array<string,mixed> $responsibilities
     * @param array<string,mixed>|ExecutionFlowSection $executionFlow
     * @param array<string,mixed>|RelationshipSection $dependencies
     * @param array<string,mixed>|RelationshipSection $dependents
     * @param array<string,mixed> $emits
     * @param array<string,mixed> $triggers
     * @param array<string,mixed> $permissions
     * @param array<string,mixed> $schemaInteraction
     * @param array<string,mixed>|GraphRelationshipsSection $graphRelationships
     * @param array<string,mixed>|DiagnosticsSection $diagnostics
     * @param array<int,string> $relatedCommands
     * @param array<int,array<string,mixed>> $relatedDocs
     * @param array<int,string> $suggestedFixes
     * @param array<int,ExplainSection|array<string,mixed>> $sections
     * @param array<int,string> $sectionOrder
     * @param array<string,mixed> $metadata
     * @param ExplainModel|array<string,mixed>|null $model
     */
    public function __construct(
        public readonly array $subject,
        public readonly array $summary,
        public readonly array $responsibilities,
        array|ExecutionFlowSection $executionFlow,
        array|RelationshipSection $dependencies,
        array|RelationshipSection $dependents,
        public readonly array $emits,
        public readonly array $triggers,
        public readonly array $permissions,
        public readonly array $schemaInteraction,
        array|GraphRelationshipsSection $graphRelationships,
        array|DiagnosticsSection $diagnostics,
        public readonly array $relatedCommands,
        public readonly array $relatedDocs,
        public readonly array $suggestedFixes,
        array $sections,
        public readonly array $sectionOrder,
        public readonly array $metadata,
        ExplainModel|array|null $model = null,
    ) {
        $this->executionFlow = $executionFlow instanceof ExecutionFlowSection ? $executionFlow : new ExecutionFlowSection($executionFlow);
        $this->dependencies = $dependencies instanceof RelationshipSection ? $dependencies : new RelationshipSection($dependencies);
        $this->dependents = $dependents instanceof RelationshipSection ? $dependents : new RelationshipSection($dependents);
        $this->graphRelationships = $graphRelationships instanceof GraphRelationshipsSection ? $graphRelationships : new GraphRelationshipsSection($graphRelationships);
        $this->diagnostics = $diagnostics instanceof DiagnosticsSection ? $diagnostics : new DiagnosticsSection($diagnostics);
        $this->sections = array_values(array_filter(array_map(
            static fn(mixed $section): ?ExplainSection => $section instanceof ExplainSection
                ? $section
                : (is_array($section) ? ExplainSection::fromArray($section) : null),
            $sections,
        )));
        $this->model = $model instanceof ExplainModel
            ? $model
            : new ExplainModel(
                subject: ExplainOrigin::applyToRow($this->subject),
                graph: [
                    'node_ids' => array_values(array_map('strval', (array) ($this->subject['graph_node_ids'] ?? []))),
                    'subject_node' => null,
                    'neighbors' => [
                        'inbound' => [],
                        'outbound' => is_array($this->graphRelationships->toArray()['outbound'] ?? null) ? $this->graphRelationships->toArray()['outbound'] : [],
                        'lateral' => is_array($this->graphRelationships->toArray()['lateral'] ?? null) ? $this->graphRelationships->toArray()['lateral'] : [],
                    ],
                ],
                execution: [
                    'entries' => is_array($this->executionFlow->toArray()['entries'] ?? null) ? $this->executionFlow->toArray()['entries'] : [],
                    'stages' => is_array($this->executionFlow->toArray()['stages'] ?? null) ? $this->executionFlow->toArray()['stages'] : [],
                    'action' => is_array($this->executionFlow->toArray()['action'] ?? null) ? $this->executionFlow->toArray()['action'] : null,
                    'workflows' => is_array($this->executionFlow->toArray()['workflows'] ?? null) ? $this->executionFlow->toArray()['workflows'] : [],
                    'jobs' => is_array($this->executionFlow->toArray()['jobs'] ?? null) ? $this->executionFlow->toArray()['jobs'] : [],
                ],
                guards: [
                    'items' => is_array($this->executionFlow->toArray()['guards'] ?? null) ? $this->executionFlow->toArray()['guards'] : [],
                ],
                events: ['emits' => [], 'subscriptions' => [], 'emitters' => [], 'subscribers' => []],
                schemas: [
                    'subject' => is_array($this->schemaInteraction['subject'] ?? null) ? $this->schemaInteraction['subject'] : null,
                    'items' => is_array($this->schemaInteraction['items'] ?? null) ? $this->schemaInteraction['items'] : [],
                    'reads' => is_array($this->schemaInteraction['reads'] ?? null) ? $this->schemaInteraction['reads'] : [],
                    'writes' => is_array($this->schemaInteraction['writes'] ?? null) ? $this->schemaInteraction['writes'] : [],
                    'fields' => is_array($this->schemaInteraction['fields'] ?? null) ? $this->schemaInteraction['fields'] : [],
                ],
                relationships: [
                    'dependsOn' => ['items' => is_array($this->dependencies->toArray()['items'] ?? null) ? $this->dependencies->toArray()['items'] : []],
                    'usedBy' => ['items' => is_array($this->dependents->toArray()['items'] ?? null) ? $this->dependents->toArray()['items'] : []],
                    'graph' => $this->graphRelationships->toArray(),
                ],
                diagnostics: $this->diagnostics->toArray(),
                docs: ['related' => $this->relatedDocs],
                impact: [],
                commands: [
                    'subject' => null,
                    'related' => array_values(array_map(
                        static fn(string $command): array => ['id' => 'command:' . $command, 'kind' => 'command', 'label' => $command, 'signature' => $command],
                        array_values(array_filter(array_map('strval', $this->relatedCommands))),
                    )),
                ],
                metadata: $this->metadata,
                extensions: is_array($model['extensions'] ?? null) ? $model['extensions'] : [],
            );
    }

    public readonly ExecutionFlowSection $executionFlow;
    public readonly RelationshipSection $dependencies;
    public readonly RelationshipSection $dependents;
    public readonly GraphRelationshipsSection $graphRelationships;
    public readonly DiagnosticsSection $diagnostics;
    public readonly ExplainModel $model;

    /**
     * @var array<int,ExplainSection>
     */
    public readonly array $sections;

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return $this->model->toArray() + [
            'summary' => $this->summary,
            'responsibilities' => $this->responsibilities,
            'executionFlow' => $this->executionFlow->toArray(),
            'emits' => $this->emits,
            'triggers' => $this->triggers,
            'permissions' => $this->permissions,
            'schemaInteraction' => $this->schemaInteraction,
            'relatedCommands' => $this->relatedCommands,
            'relatedDocs' => $this->relatedDocs,
            'suggestedFixes' => $this->suggestedFixes,
            'sections' => array_map(
                static fn(ExplainSection $section): array => $section->toArray(),
                $this->sections,
            ),
            'sectionOrder' => $this->sectionOrder,
        ];
    }
}
