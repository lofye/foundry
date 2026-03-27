<?php
declare(strict_types=1);

namespace Foundry\Pro;

use Foundry\Compiler\ApplicationGraph;
use Foundry\Compiler\Analysis\ImpactAnalyzer;
use Foundry\Explain\Contributors\ExplainContributorInterface;
use Foundry\Explain\ExplainEngineFactory;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainResponse;
use Foundry\Explain\ExplainTarget;
use Foundry\Explain\Renderers\ExplanationRendererFactory;
use Foundry\Support\ApiSurfaceRegistry;
use Foundry\Support\Paths;

final readonly class ArchitectureExplainer
{
    /**
     * @param array<int,array<string,mixed>> $extensionRows
     * @param array<int,ExplainContributorInterface> $contributors
     */
    public function __construct(
        private Paths $paths,
        private ImpactAnalyzer $impactAnalyzer,
        private ApiSurfaceRegistry $apiSurfaceRegistry,
        private array $extensionRows = [],
        private array $contributors = [],
        private ?string $commandPrefix = null,
        private ?ExplanationRendererFactory $rendererFactory = null,
    ) {
    }

    public function explain(ApplicationGraph $graph, string|ExplainTarget $target, ExplainOptions $options = new ExplainOptions()): ExplainResponse
    {
        $resolvedTarget = is_string($target) ? ExplainTarget::parse($target) : $target;
        $engine = ExplainEngineFactory::create(
            graph: $graph,
            paths: $this->paths,
            apiSurfaceRegistry: $this->apiSurfaceRegistry,
            impactAnalyzer: $this->impactAnalyzer,
            extensionRows: $this->extensionRows,
            commandPrefix: $this->commandPrefix,
            contributors: $this->contributors,
        );

        $plan = $engine->explain($resolvedTarget, $options);
        $renderer = ($this->rendererFactory ?? new ExplanationRendererFactory())->forFormat($options->format);

        return new ExplainResponse($plan, $renderer->render($plan));
    }
}
