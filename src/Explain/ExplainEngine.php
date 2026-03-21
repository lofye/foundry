<?php
declare(strict_types=1);

namespace Foundry\Explain;

use Foundry\Compiler\ApplicationGraph;
use Foundry\Explain\Analyzers\SubjectAnalyzerInterface;
use Foundry\Explain\Collectors\ExplainContextCollectorInterface;
use Foundry\Explain\Contributors\ExplainContributorInterface;

final class ExplainEngine implements ExplainEngineInterface
{
    /**
     * @param array<int,ExplainContextCollectorInterface> $collectors
     * @param array<int,SubjectAnalyzerInterface> $analyzers
     * @param array<int,ExplainContributorInterface> $contributors
     */
    public function __construct(
        private readonly ApplicationGraph $graph,
        private readonly ExplainTargetResolver $resolver,
        private readonly ExplainArtifactCatalog $artifacts,
        private readonly RuleBasedSummaryBuilder $summaryBuilder,
        private readonly array $collectors,
        private readonly array $analyzers,
        private readonly array $contributors,
        private readonly string $commandPrefix,
    ) {
    }

    public function explain(ExplainTarget $target, ExplainOptions $options): ExplanationPlan
    {
        $subject = $this->resolver->resolve($target);
        $context = new ExplainContext($this->graph, $this->artifacts, $subject, $this->commandPrefix);

        foreach ($this->collectors as $collector) {
            if ($collector->supports($subject)) {
                $collector->collect($subject, $context, $options);
            }
        }

        $sections = [];
        $executionFlow = [];
        $relatedCommands = [];
        $relatedDocs = [];

        foreach ($this->analyzers as $analyzer) {
            if (!$analyzer->supports($subject)) {
                continue;
            }

            $contribution = $analyzer->analyze($subject, $context, $options);
            $sections = array_merge($sections, $this->sectionRows($contribution['sections'] ?? []));
            $executionFlow = array_replace_recursive($executionFlow, is_array($contribution['execution_flow'] ?? null) ? $contribution['execution_flow'] : []);
            $relatedCommands = array_merge($relatedCommands, array_values(array_map('strval', (array) ($contribution['related_commands'] ?? []))));
            $relatedDocs = array_merge($relatedDocs, $this->docRows($contribution['related_docs'] ?? []));
        }

        foreach ($this->contributors as $contributor) {
            if (!$contributor->supports($subject)) {
                continue;
            }

            $contribution = $contributor->contribute($subject, $context, $options);
            $sections = array_merge($sections, $this->sectionRows($contribution['sections'] ?? []));
            $executionFlow = array_replace_recursive($executionFlow, is_array($contribution['execution_flow'] ?? null) ? $contribution['execution_flow'] : []);
            $relatedCommands = array_merge($relatedCommands, array_values(array_map('strval', (array) ($contribution['related_commands'] ?? []))));
            $relatedDocs = array_merge($relatedDocs, $this->docRows($contribution['related_docs'] ?? []));
        }

        $summary = $this->summaryBuilder->build($subject, $context, $options);
        $relationships = $options->includeNeighbors ? $this->relationships($context) : ['depends_on' => [], 'depended_on_by' => [], 'neighbors' => []];
        $diagnostics = $options->includeDiagnostics ? $this->diagnostics($context) : ['summary' => ['error' => 0, 'warning' => 0, 'info' => 0, 'total' => 0], 'items' => []];
        if (!$options->includeExecutionFlow) {
            $executionFlow = [];
        }
        if (!$options->includeRelatedCommands) {
            $relatedCommands = [];
        }
        if (!$options->includeRelatedDocs) {
            $relatedDocs = [];
        }

        return new ExplanationPlan(
            subject: $subject->toArray(),
            summary: $summary,
            sections: $sections,
            relationships: $relationships,
            executionFlow: $executionFlow,
            diagnostics: $diagnostics,
            relatedCommands: ExplainSupport::uniqueStrings($relatedCommands),
            relatedDocs: $this->uniqueDocs($relatedDocs),
            metadata: $this->metadata($target, $options, $context),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function relationships(ExplainContext $context): array
    {
        $neighborhood = (array) $context->get('graph_neighborhood', []);

        return [
            'depends_on' => $this->rowList($neighborhood['depends_on'] ?? []),
            'depended_on_by' => $this->rowList($neighborhood['depended_on_by'] ?? []),
            'neighbors' => $this->rowList($neighborhood['neighbors'] ?? []),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function diagnostics(ExplainContext $context): array
    {
        $diagnostics = (array) $context->get('diagnostics', []);

        return [
            'summary' => is_array($diagnostics['summary'] ?? null) ? $diagnostics['summary'] : ['error' => 0, 'warning' => 0, 'info' => 0, 'total' => 0],
            'items' => $this->rowList($diagnostics['items'] ?? []),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function metadata(ExplainTarget $target, ExplainOptions $options, ExplainContext $context): array
    {
        $impact = $context->get('impact');

        return [
            'schema_version' => 1,
            'target' => $target->toArray(),
            'options' => $options->toArray(),
            'graph' => [
                'graph_version' => $this->graph->graphVersion(),
                'framework_version' => $this->graph->frameworkVersion(),
                'compiled_at' => $this->graph->compiledAt(),
                'source_hash' => $this->graph->sourceHash(),
            ],
            'command_prefix' => $this->commandPrefix,
            'impact' => is_array($impact) ? $impact : null,
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
     * @param mixed $rows
     * @return array<int,array<string,mixed>>
     */
    private function sectionRows(mixed $rows): array
    {
        return $this->rowList($rows);
    }

    /**
     * @param mixed $rows
     * @return array<int,array<string,mixed>>
     */
    private function docRows(mixed $rows): array
    {
        return $this->rowList($rows);
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
}
