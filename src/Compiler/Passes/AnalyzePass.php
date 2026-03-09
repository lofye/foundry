<?php
declare(strict_types=1);

namespace Foundry\Compiler\Passes;

use Foundry\Compiler\Analysis\ImpactAnalyzer;
use Foundry\Compiler\CompilationState;
use Foundry\Compiler\CompilerPass;

final class AnalyzePass implements CompilerPass
{
    public function __construct(private readonly ImpactAnalyzer $impactAnalyzer)
    {
    }

    public function name(): string
    {
        return 'analyze';
    }

    public function run(CompilationState $state): void
    {
        $changeImpact = [];
        $overallRisk = 'low';

        foreach ($state->plan->changedFeatures as $feature) {
            $nodeId = 'feature:' . $feature;
            if ($state->graph->node($nodeId) === null) {
                continue;
            }

            $report = $this->impactAnalyzer->reportForNode($state->graph, $nodeId);
            $changeImpact[$nodeId] = $report;
            $overallRisk = $this->maxRisk($overallRisk, (string) ($report['risk'] ?? 'low'));
        }

        if ($changeImpact === [] && $state->plan->mode === 'full') {
            foreach ($state->graph->nodesByType('feature') as $featureNode) {
                $nodeId = $featureNode->id();
                $report = $this->impactAnalyzer->reportForNode($state->graph, $nodeId);
                $changeImpact[$nodeId] = $report;
                $overallRisk = $this->maxRisk($overallRisk, (string) ($report['risk'] ?? 'low'));
            }
        }

        ksort($changeImpact);

        $state->analysis['change_impact'] = $changeImpact;
        $state->analysis['change_risk'] = $overallRisk;
    }

    private function maxRisk(string $a, string $b): string
    {
        $order = ['low' => 0, 'medium' => 1, 'high' => 2];

        return ($order[$a] ?? 0) >= ($order[$b] ?? 0) ? $a : $b;
    }
}
