<?php
declare(strict_types=1);

namespace Foundry\Explain;

use Foundry\Compiler\ApplicationGraph;
use Foundry\Compiler\Analysis\ImpactAnalyzer;
use Foundry\Compiler\BuildLayout;
use Foundry\Explain\Analyzers\CommandSubjectAnalyzer;
use Foundry\Explain\Analyzers\EventSubjectAnalyzer;
use Foundry\Explain\Analyzers\ExtensionSubjectAnalyzer;
use Foundry\Explain\Analyzers\FeatureSubjectAnalyzer;
use Foundry\Explain\Analyzers\GenericGraphSubjectAnalyzer;
use Foundry\Explain\Analyzers\JobSubjectAnalyzer;
use Foundry\Explain\Analyzers\PipelineStageSubjectAnalyzer;
use Foundry\Explain\Analyzers\RouteSubjectAnalyzer;
use Foundry\Explain\Analyzers\SchemaSubjectAnalyzer;
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
use Foundry\Support\ApiSurfaceRegistry;
use Foundry\Support\Paths;

final class ExplainEngineFactory
{
    /**
     * @param array<int,array<string,mixed>> $extensionRows
     */
    public static function create(
        ApplicationGraph $graph,
        Paths $paths,
        ApiSurfaceRegistry $apiSurfaceRegistry,
        ImpactAnalyzer $impactAnalyzer,
        array $extensionRows = [],
        ?string $commandPrefix = null,
    ): ExplainEngineInterface {
        $artifacts = new ExplainArtifactCatalog(
            new BuildLayout($paths),
            $apiSurfaceRegistry,
            $extensionRows,
        );

        return new ExplainEngine(
            graph: $graph,
            resolver: new ExplainTargetResolver($graph, $artifacts),
            artifacts: $artifacts,
            summaryBuilder: new RuleBasedSummaryBuilder(),
            collectors: [
                new GraphNeighborhoodCollector(),
                new PipelineContextCollector(),
                new EventContextCollector(),
                new WorkflowContextCollector(),
                new SchemaContextCollector(),
                new DiagnosticsContextCollector(),
                new CommandContextCollector(),
                new ExtensionContextCollector(),
                new DocsContextCollector(),
                new ImpactContextCollector($impactAnalyzer),
            ],
            analyzers: [
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
            contributors: [],
            commandPrefix: $commandPrefix ?? ExplainSupport::commandPrefix($paths),
        );
    }
}
