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
    ) {
        $this->executionFlow = $executionFlow instanceof ExecutionFlowSection ? $executionFlow : new ExecutionFlowSection($executionFlow);
        $this->dependencies = $dependencies instanceof RelationshipSection ? $dependencies : new RelationshipSection($dependencies);
        $this->dependents = $dependents instanceof RelationshipSection ? $dependents : new RelationshipSection($dependents);
        $this->graphRelationships = $graphRelationships instanceof GraphRelationshipsSection ? $graphRelationships : new GraphRelationshipsSection($graphRelationships);
        $this->diagnostics = $diagnostics instanceof DiagnosticsSection ? $diagnostics : new DiagnosticsSection($diagnostics);
        $this->sections = array_values(array_filter(array_map(
            static fn (mixed $section): ?ExplainSection => $section instanceof ExplainSection
                ? $section
                : (is_array($section) ? ExplainSection::fromArray($section) : null),
            $sections,
        )));
    }

    public readonly ExecutionFlowSection $executionFlow;
    public readonly RelationshipSection $dependencies;
    public readonly RelationshipSection $dependents;
    public readonly GraphRelationshipsSection $graphRelationships;
    public readonly DiagnosticsSection $diagnostics;

    /**
     * @var array<int,ExplainSection>
     */
    public readonly array $sections;

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'subject' => $this->subject,
            'summary' => $this->summary,
            'responsibilities' => $this->responsibilities,
            'executionFlow' => $this->executionFlow->toArray(),
            'relationships' => [
                'dependsOn' => $this->dependencies->toArray(),
                'usedBy' => $this->dependents->toArray(),
                'graph' => $this->graphRelationships->toArray(),
            ],
            'emits' => $this->emits,
            'triggers' => $this->triggers,
            'permissions' => $this->permissions,
            'schemaInteraction' => $this->schemaInteraction,
            'relatedCommands' => $this->relatedCommands,
            'relatedDocs' => $this->relatedDocs,
            'diagnostics' => $this->diagnostics->toArray(),
            'suggestedFixes' => $this->suggestedFixes,
            'sections' => array_map(
                static fn (ExplainSection $section): array => $section->toArray(),
                $this->sections,
            ),
            'sectionOrder' => $this->sectionOrder,
            'metadata' => $this->metadata,
        ];
    }
}
