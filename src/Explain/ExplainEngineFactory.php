<?php
declare(strict_types=1);

namespace Foundry\Explain;

use Foundry\Compiler\ApplicationGraph;
use Foundry\Compiler\Analysis\ImpactAnalyzer;
use Foundry\Compiler\BuildLayout;
use Foundry\Explain\Analyzers\CommandSubjectAnalyzer;
use Foundry\Explain\Analyzers\DependencyAnalyzer;
use Foundry\Explain\Analyzers\EventSubjectAnalyzer;
use Foundry\Explain\Analyzers\EventEmissionAnalyzer;
use Foundry\Explain\Analyzers\ExtensionSubjectAnalyzer;
use Foundry\Explain\Analyzers\FeatureSubjectAnalyzer;
use Foundry\Explain\Analyzers\GenericGraphSubjectAnalyzer;
use Foundry\Explain\Analyzers\GraphRelationshipsAnalyzer;
use Foundry\Explain\Analyzers\JobSubjectAnalyzer;
use Foundry\Explain\Analyzers\PermissionAnalyzer;
use Foundry\Explain\Analyzers\PipelineStageSubjectAnalyzer;
use Foundry\Explain\Analyzers\RelatedCommandsAnalyzer;
use Foundry\Explain\Analyzers\RelatedDocsAnalyzer;
use Foundry\Explain\Analyzers\RouteSubjectAnalyzer;
use Foundry\Explain\Analyzers\SchemaSubjectAnalyzer;
use Foundry\Explain\Analyzers\SchemaInteractionAnalyzer;
use Foundry\Explain\Analyzers\DiagnosticsAnalyzer;
use Foundry\Explain\Analyzers\DependentAnalyzer;
use Foundry\Explain\Analyzers\ExecutionFlowAnalyzer;
use Foundry\Explain\Analyzers\TriggerAnalyzer;
use Foundry\Explain\Analyzers\WorkflowSubjectAnalyzer;
use Foundry\Explain\Collectors\CommandContextCollector;
use Foundry\Explain\Collectors\DiagnosticsContextCollector;
use Foundry\Explain\Collectors\DocsContextCollector;
use Foundry\Explain\Collectors\ExtensionContextCollector;
use Foundry\Explain\Collectors\GraphNeighborhoodCollector;
use Foundry\Explain\Collectors\ImpactContextCollector;
use Foundry\Explain\Collectors\EventContextCollector;
use Foundry\Explain\Collectors\PipelineContextCollector;
use Foundry\Explain\Collectors\SchemaContextCollector;
use Foundry\Explain\Collectors\WorkflowContextCollector;
use Foundry\Explain\Contributors\ExplainContributorInterface;
use Foundry\Explain\Contributors\ExplainContributorRegistry;
use Foundry\Support\ApiSurfaceRegistry;
use Foundry\Support\Paths;

final class ExplainEngineFactory
{
    /**
     * @param array<int,array<string,mixed>> $extensionRows
     * @param array<int,ExplainContributorInterface> $contributors
     */
    public static function create(
        ApplicationGraph $graph,
        Paths $paths,
        ApiSurfaceRegistry $apiSurfaceRegistry,
        ImpactAnalyzer $impactAnalyzer,
        array $extensionRows = [],
        ?string $commandPrefix = null,
        array $contributors = [],
    ): ExplainEngineInterface {
        $artifacts = new ExplainArtifactCatalog(
            new BuildLayout($paths),
            $paths,
            $apiSurfaceRegistry,
            $extensionRows,
        );
        $subjectFactory = new ExplainSubjectFactory();

        return new ExplainEngine(
            graph: $graph,
            resolver: new ExplainTargetResolver($graph, $artifacts, $subjectFactory),
            planAssembler: new ExplanationPlanAssembler(
                new SummarySectionBuilder(),
                new SuggestedFixesBuilder(),
                [
                    new GenericGraphSubjectAnalyzer(),
                    new FeatureSubjectAnalyzer(),
                    new RouteSubjectAnalyzer(),
                    new EventSubjectAnalyzer(),
                    new WorkflowSubjectAnalyzer(),
                    new CommandSubjectAnalyzer(),
                    new JobSubjectAnalyzer(),
                    new SchemaSubjectAnalyzer(),
                    new ExtensionSubjectAnalyzer(),
                    new PipelineStageSubjectAnalyzer(),
                ],
                [
                    new ExecutionFlowAnalyzer(),
                    new DependencyAnalyzer(),
                    new DependentAnalyzer(),
                    new EventEmissionAnalyzer(),
                    new TriggerAnalyzer(),
                    new PermissionAnalyzer(),
                    new SchemaInteractionAnalyzer(),
                    new GraphRelationshipsAnalyzer(),
                    new RelatedCommandsAnalyzer(),
                    new RelatedDocsAnalyzer(),
                    new DiagnosticsAnalyzer(),
                ],
                new ExplainContributorRegistry($contributors),
            ),
            collectors: [
                new GraphNeighborhoodCollector($graph),
                new PipelineContextCollector($artifacts),
                new EventContextCollector($artifacts),
                new WorkflowContextCollector($graph, $artifacts),
                new SchemaContextCollector($artifacts),
                new DiagnosticsContextCollector($graph, $artifacts),
                new CommandContextCollector($artifacts),
                new ExtensionContextCollector($artifacts),
                new DocsContextCollector($artifacts),
                new ImpactContextCollector($impactAnalyzer, $graph),
            ],
            commandPrefix: $commandPrefix ?? ExplainSupport::commandPrefix($paths),
        );
    }
}
