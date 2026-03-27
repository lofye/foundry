<?php
declare(strict_types=1);

namespace Foundry\Explain;

use Foundry\Explain\Analyzers\SectionAnalyzerInterface;
use Foundry\Explain\Analyzers\SubjectAnalyzerInterface;
use Foundry\Explain\Contributors\ExplainContributorRegistry;

final class ExplanationPlanAssembler
{
    /**
     * @param array<int,SubjectAnalyzerInterface> $subjectAnalyzers
     * @param array<int,SectionAnalyzerInterface> $sectionAnalyzers
     */
    public function __construct(
        private readonly SummarySectionBuilder $summaryBuilder,
        private readonly SuggestedFixesBuilder $suggestedFixesBuilder,
        private readonly array $subjectAnalyzers,
        private readonly array $sectionAnalyzers,
        private readonly ExplainContributorRegistry $contributors = new ExplainContributorRegistry(),
    ) {
    }

    /**
     * @param array<string,mixed> $metadata
     */
    public function assemble(
        ExplainSubject $subject,
        ExplainContext $context,
        ExplainOptions $options,
        array $metadata,
    ): ExplanationPlan {
        $responsibilities = [];
        $summaryInputs = [];
        $sections = [];

        foreach ($this->subjectAnalyzers as $analyzer) {
            if (!$analyzer->supports($subject)) {
                continue;
            }

            $analysis = $analyzer->analyze($subject, $context, $options);
            $responsibilities = array_merge($responsibilities, array_values(array_map('strval', $analysis->responsibilities)));
            $summaryInputs = array_replace_recursive($summaryInputs, $analysis->summaryInputs);
            $sections = array_merge($sections, $this->normalizeSections($analysis->sections));
        }

        $sectionData = [];
        foreach ($this->sectionAnalyzers as $analyzer) {
            if (!$analyzer->supports($subject)) {
                continue;
            }

            $sectionData[$analyzer->sectionId()] = $analyzer->analyze($subject, $context, $options);
        }

        $contributorCommands = [];
        $contributorDocs = [];
        foreach ($this->contributors->contributionsFor($subject, $context, $options) as $contribution) {
            $sections = array_merge($sections, $this->normalizeSections($contribution->sections));
            $contributorCommands = array_merge($contributorCommands, $contribution->relatedCommands);
            $contributorDocs = array_merge($contributorDocs, $this->uniqueDocs($this->rowList($contribution->relatedDocs)));
        }

        $responsibilities = ExplainSupport::orderedUniqueStrings($responsibilities);
        $summary = $this->summaryBuilder->build($subject, $options, $summaryInputs, $sectionData);
        $relatedCommands = ExplainSupport::uniqueStrings(array_merge(
            array_values(array_map('strval', (array) ($sectionData['related_commands']['items'] ?? []))),
            $contributorCommands,
        ));
        $relatedDocs = $this->uniqueDocs(array_merge(
            $this->rowList($sectionData['related_docs']['items'] ?? []),
            $contributorDocs,
        ));
        $suggestedFixes = $this->suggestedFixesBuilder->build($subject, $sectionData);

        $sectionOrder = $this->sectionOrder(
            responsibilities: $responsibilities,
            executionFlow: $sectionData['execution_flow'] ?? [],
            dependencies: $sectionData['dependencies'] ?? [],
            dependents: $sectionData['dependents'] ?? [],
            emits: $sectionData['emits'] ?? [],
            triggers: $sectionData['triggers'] ?? [],
            permissions: $sectionData['permissions'] ?? [],
            schemaInteraction: $sectionData['schema_interaction'] ?? [],
            graphRelationships: $sectionData['graph_relationships'] ?? [],
            relatedCommands: $relatedCommands,
            relatedDocs: $relatedDocs,
            diagnostics: $sectionData['diagnostics'] ?? [],
            suggestedFixes: $suggestedFixes,
            sections: $sections,
        );

        return new ExplanationPlan(
            subject: $subject->toArray(),
            summary: $summary,
            responsibilities: ['items' => $responsibilities],
            executionFlow: new ExecutionFlowSection($this->normalizeExecutionFlow($sectionData['execution_flow'] ?? [])),
            dependencies: new RelationshipSection($this->normalizeRowsSection($sectionData['dependencies'] ?? [])),
            dependents: new RelationshipSection($this->normalizeRowsSection($sectionData['dependents'] ?? [])),
            emits: $this->normalizeRowsSection($sectionData['emits'] ?? []),
            triggers: $this->normalizeRowsSection($sectionData['triggers'] ?? []),
            permissions: $this->normalizePermissions($sectionData['permissions'] ?? []),
            schemaInteraction: $this->normalizeSchemaInteraction($sectionData['schema_interaction'] ?? []),
            graphRelationships: new GraphRelationshipsSection($this->normalizeGraphRelationships($sectionData['graph_relationships'] ?? [])),
            diagnostics: new DiagnosticsSection($this->normalizeDiagnostics($sectionData['diagnostics'] ?? [])),
            relatedCommands: $relatedCommands,
            relatedDocs: $relatedDocs,
            suggestedFixes: $suggestedFixes,
            sections: $sections,
            sectionOrder: $sectionOrder,
            metadata: $metadata,
        );
    }

    /**
     * @param array<int,ExplainSection|array<string,mixed>> $sections
     * @return array<int,ExplainSection>
     */
    private function normalizeSections(array $sections): array
    {
        $rows = [];
        foreach ($sections as $index => $section) {
            $normalized = $section instanceof ExplainSection ? $section : (is_array($section) ? ExplainSection::fromArray($section) : null);
            if (!$normalized instanceof ExplainSection || !$normalized->isRenderable()) {
                continue;
            }

            $rows[] = [
                'section' => $normalized,
                'render_index' => $index,
            ];
        }

        usort($rows, function (array $left, array $right): int {
            /** @var ExplainSection $leftSection */
            $leftSection = $left['section'];
            /** @var ExplainSection $rightSection */
            $rightSection = $right['section'];

            return ($this->sectionPriority($leftSection->id()) <=> $this->sectionPriority($rightSection->id()))
                ?: ((int) ($left['render_index'] ?? 0) <=> (int) ($right['render_index'] ?? 0))
                ?: strcmp($leftSection->title(), $rightSection->title());
        });

        return array_values(array_map(
            static fn (array $row): ExplainSection => $row['section'],
            $rows,
        ));
    }

    /**
     * @param array<string,mixed> $section
     * @return array<string,mixed>
     */
    private function normalizeRowsSection(array $section): array
    {
        return [
            'items' => $this->rowList($section['items'] ?? []),
        ];
    }

    /**
     * @param array<string,mixed> $executionFlow
     * @return array<string,mixed>
     */
    private function normalizeExecutionFlow(array $executionFlow): array
    {
        return [
            'entries' => $this->rowList($executionFlow['entries'] ?? []),
            'stages' => $this->rowList($executionFlow['stages'] ?? []),
            'guards' => $this->rowList($executionFlow['guards'] ?? []),
            'action' => is_array($executionFlow['action'] ?? null) ? $executionFlow['action'] : null,
            'events' => $this->rowList($executionFlow['events'] ?? []),
            'workflows' => $this->rowList($executionFlow['workflows'] ?? []),
            'jobs' => $this->rowList($executionFlow['jobs'] ?? []),
        ];
    }

    /**
     * @param array<string,mixed> $diagnostics
     * @return array<string,mixed>
     */
    private function normalizeDiagnostics(array $diagnostics): array
    {
        return [
            'summary' => is_array($diagnostics['summary'] ?? null)
                ? $diagnostics['summary']
                : ['error' => 0, 'warning' => 0, 'info' => 0, 'total' => 0],
            'items' => $this->rowList($diagnostics['items'] ?? []),
        ];
    }

    /**
     * @param array<string,mixed> $permissions
     * @return array<string,mixed>
     */
    private function normalizePermissions(array $permissions): array
    {
        return [
            'required' => ExplainSupport::uniqueStrings(array_values(array_map('strval', (array) ($permissions['required'] ?? [])))),
            'enforced_by' => $this->rowList($permissions['enforced_by'] ?? []),
            'defined_in' => $this->rowList($permissions['defined_in'] ?? []),
            'missing' => ExplainSupport::uniqueStrings(array_values(array_map('strval', (array) ($permissions['missing'] ?? [])))),
        ];
    }

    /**
     * @param array<string,mixed> $schemaInteraction
     * @return array<string,mixed>
     */
    private function normalizeSchemaInteraction(array $schemaInteraction): array
    {
        return [
            'items' => $this->rowList($schemaInteraction['items'] ?? []),
            'reads' => $this->rowList($schemaInteraction['reads'] ?? []),
            'writes' => $this->rowList($schemaInteraction['writes'] ?? []),
            'fields' => $this->rowList($schemaInteraction['fields'] ?? []),
            'subject' => is_array($schemaInteraction['subject'] ?? null) ? $schemaInteraction['subject'] : null,
        ];
    }

    /**
     * @param array<string,mixed> $graphRelationships
     * @return array<string,mixed>
     */
    private function normalizeGraphRelationships(array $graphRelationships): array
    {
        return [
            'inbound' => $this->rowList($graphRelationships['inbound'] ?? []),
            'outbound' => $this->rowList($graphRelationships['outbound'] ?? []),
            'lateral' => $this->rowList($graphRelationships['lateral'] ?? []),
        ];
    }

    /**
     * @param mixed $rows
     * @return array<int,array<string,mixed>>
     */
    private function rowList(mixed $rows): array
    {
        $filtered = [];
        foreach ((array) $rows as $row) {
            if (is_array($row)) {
                $filtered[] = $row;
            }
        }

        return $filtered;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function uniqueDocs(array $rows): array
    {
        $unique = [];
        foreach ($rows as $row) {
            $id = trim((string) ($row['id'] ?? $row['path'] ?? $row['title'] ?? ''));
            if ($id === '') {
                $id = md5(serialize($row));
            }

            $unique[$id] = $row;
        }

        usort(
            $unique,
            static fn (array $left, array $right): int => strcmp((string) ($left['title'] ?? ''), (string) ($right['title'] ?? ''))
                ?: strcmp((string) ($left['path'] ?? ''), (string) ($right['path'] ?? '')),
        );

        return array_values($unique);
    }

    private function sectionPriority(string $id): int
    {
        return match ($id) {
            'subject' => 0,
            'summary' => 10,
            'responsibilities' => 20,
            'execution_flow' => 30,
            'dependencies' => 40,
            'dependents' => 50,
            'emits' => 60,
            'triggers' => 70,
            'permissions' => 80,
            'schema_interaction' => 90,
            'graph_relationships' => 100,
            'related_commands' => 110,
            'related_docs' => 120,
            'diagnostics' => 130,
            'suggested_fixes' => 140,
            'impact' => 900,
            default => 800,
        };
    }

    /**
     * @param array<string,mixed> $executionFlow
     * @param array<string,mixed> $dependencies
     * @param array<string,mixed> $dependents
     * @param array<string,mixed> $emits
     * @param array<string,mixed> $triggers
     * @param array<string,mixed> $permissions
     * @param array<string,mixed> $schemaInteraction
     * @param array<string,mixed> $graphRelationships
     * @param array<int,string> $relatedCommands
     * @param array<int,array<string,mixed>> $relatedDocs
     * @param array<string,mixed> $diagnostics
     * @param array<int,string> $suggestedFixes
     * @param array<int,ExplainSection> $sections
     * @return array<int,string>
     */
    private function sectionOrder(
        array $responsibilities,
        array $executionFlow,
        array $dependencies,
        array $dependents,
        array $emits,
        array $triggers,
        array $permissions,
        array $schemaInteraction,
        array $graphRelationships,
        array $relatedCommands,
        array $relatedDocs,
        array $diagnostics,
        array $suggestedFixes,
        array $sections,
    ): array {
        $order = ['subject', 'summary'];

        $presence = [
            'responsibilities' => $responsibilities !== [],
            'execution_flow' => $this->rowList($executionFlow['entries'] ?? []) !== [],
            'dependencies' => $this->rowList($dependencies['items'] ?? []) !== [],
            'dependents' => $this->rowList($dependents['items'] ?? []) !== [],
            'emits' => $this->rowList($emits['items'] ?? []) !== [],
            'triggers' => $this->rowList($triggers['items'] ?? []) !== [],
            'permissions' => $this->permissionsPresent($permissions),
            'schema_interaction' => $this->schemaInteractionPresent($schemaInteraction),
            'graph_relationships' => $this->graphRelationshipsPresent($graphRelationships),
            'related_commands' => $relatedCommands !== [],
            'related_docs' => $relatedDocs !== [],
            'diagnostics' => true,
            'suggested_fixes' => $suggestedFixes !== [],
        ];

        foreach ($presence as $id => $present) {
            if ($present) {
                $order[] = $id;
            }
        }

        foreach ($sections as $section) {
            $id = $section->id();
            if ($id !== '') {
                $order[] = $id;
            }
        }

        return ExplainSupport::orderedUniqueStrings($order);
    }

    /**
     * @param array<string,mixed> $permissions
     */
    private function permissionsPresent(array $permissions): bool
    {
        return ExplainSupport::uniqueStrings(array_values(array_map('strval', (array) ($permissions['required'] ?? [])))) !== []
            || $this->rowList($permissions['enforced_by'] ?? []) !== []
            || $this->rowList($permissions['defined_in'] ?? []) !== []
            || ExplainSupport::uniqueStrings(array_values(array_map('strval', (array) ($permissions['missing'] ?? [])))) !== [];
    }

    /**
     * @param array<string,mixed> $schemaInteraction
     */
    private function schemaInteractionPresent(array $schemaInteraction): bool
    {
        return $this->rowList($schemaInteraction['items'] ?? []) !== []
            || $this->rowList($schemaInteraction['reads'] ?? []) !== []
            || $this->rowList($schemaInteraction['writes'] ?? []) !== []
            || $this->rowList($schemaInteraction['fields'] ?? []) !== [];
    }

    /**
     * @param array<string,mixed> $graphRelationships
     */
    private function graphRelationshipsPresent(array $graphRelationships): bool
    {
        return $this->rowList($graphRelationships['inbound'] ?? []) !== []
            || $this->rowList($graphRelationships['outbound'] ?? []) !== []
            || $this->rowList($graphRelationships['lateral'] ?? []) !== [];
    }
}
