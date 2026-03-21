<?php
declare(strict_types=1);

namespace Foundry\Explain\Collectors;

use Foundry\Compiler\ApplicationGraph;
use Foundry\Compiler\Analysis\ImpactAnalyzer;
use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;

final readonly class ImpactContextCollector implements ExplainContextCollectorInterface
{
    public function __construct(
        private ImpactAnalyzer $impactAnalyzer,
        private ApplicationGraph $graph,
    )
    {
    }

    public function supports(ExplainSubject $subject): bool
    {
        return $subject->graphNodeIds !== [];
    }

    public function collect(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): void
    {
        $nodeId = $subject->graphNodeIds[0] ?? null;
        if (!is_string($nodeId) || $nodeId === '') {
            return;
        }

        $context->setImpact($this->impactAnalyzer->reportForNode($this->graph, $nodeId));
    }
}
