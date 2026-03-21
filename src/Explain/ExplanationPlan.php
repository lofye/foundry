<?php
declare(strict_types=1);

namespace Foundry\Explain;

final readonly class ExplanationPlan
{
    /**
     * @param array<string,mixed> $subject
     * @param array<string,mixed> $summary
     * @param array<string,mixed> $responsibilities
     * @param array<string,mixed> $executionFlow
     * @param array<string,mixed> $dependencies
     * @param array<string,mixed> $dependents
     * @param array<string,mixed> $emits
     * @param array<string,mixed> $triggers
     * @param array<string,mixed> $permissions
     * @param array<string,mixed> $schemaInteraction
     * @param array<string,mixed> $graphRelationships
     * @param array<string,mixed> $diagnostics
     * @param array<int,string> $relatedCommands
     * @param array<int,array<string,mixed>> $relatedDocs
     * @param array<int,string> $suggestedFixes
     * @param array<int,array<string,mixed>> $sections
     * @param array<int,string> $sectionOrder
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public array $subject,
        public array $summary,
        public array $responsibilities,
        public array $executionFlow,
        public array $dependencies,
        public array $dependents,
        public array $emits,
        public array $triggers,
        public array $permissions,
        public array $schemaInteraction,
        public array $graphRelationships,
        public array $diagnostics,
        public array $relatedCommands,
        public array $relatedDocs,
        public array $suggestedFixes,
        public array $sections,
        public array $sectionOrder,
        public array $metadata,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'subject' => $this->subject,
            'summary' => $this->summary,
            'responsibilities' => $this->responsibilities,
            'execution_flow' => $this->executionFlow,
            'dependencies' => $this->dependencies,
            'dependents' => $this->dependents,
            'emits' => $this->emits,
            'triggers' => $this->triggers,
            'permissions' => $this->permissions,
            'schema_interaction' => $this->schemaInteraction,
            'graph_relationships' => $this->graphRelationships,
            'diagnostics' => $this->diagnostics,
            'related_commands' => $this->relatedCommands,
            'related_docs' => $this->relatedDocs,
            'suggested_fixes' => $this->suggestedFixes,
            'sections' => $this->sections,
            'section_order' => $this->sectionOrder,
            'metadata' => $this->metadata,
        ];
    }
}
