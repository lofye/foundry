<?php
declare(strict_types=1);

namespace Foundry\Compiler\Analysis;

use Foundry\Compiler\ApplicationGraph;
use Foundry\Compiler\Diagnostics\DiagnosticBag;

final readonly class ArchitectureDoctor
{
    /**
     * @param array<int,GraphAnalyzer> $analyzers
     */
    public function __construct(
        private array $analyzers,
        private ImpactAnalyzer $impactAnalyzer,
        private string $commandPrefix = 'foundry',
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function analyze(ApplicationGraph $graph, ?string $featureFilter = null): array
    {
        $context = new AnalyzerContext($featureFilter);
        $diagnostics = new DiagnosticBag();
        $results = [];

        foreach ($this->sortedAnalyzers() as $analyzer) {
            $results[$analyzer->id()] = [
                'description' => $analyzer->description(),
                'result' => $analyzer->analyze($graph, $context, $diagnostics),
            ];
        }
        ksort($results);

        $impact = null;
        if ($featureFilter !== null && $featureFilter !== '') {
            $impact = $this->impactAnalyzer->reportForNode($graph, 'feature:' . $featureFilter);
        }

        $summary = $diagnostics->summary();

        return [
            'feature_filter' => $featureFilter,
            'risk' => $this->riskLevel($summary),
            'diagnostics' => [
                'summary' => $summary,
                'items' => $diagnostics->toArray(),
            ],
            'analyzers' => $results,
            'impact_preview' => $impact,
            'suggested_actions' => $this->suggestedActions($summary, $featureFilter),
        ];
    }

    /**
     * @return array<int,GraphAnalyzer>
     */
    private function sortedAnalyzers(): array
    {
        $rows = array_values(array_filter(
            $this->analyzers,
            static fn (mixed $analyzer): bool => $analyzer instanceof GraphAnalyzer,
        ));

        usort(
            $rows,
            static fn (GraphAnalyzer $a, GraphAnalyzer $b): int => strcmp($a->id(), $b->id()),
        );

        return $rows;
    }

    /**
     * @param array{error:int,warning:int,info:int,total:int} $summary
     * @return array<int,string>
     */
    private function suggestedActions(array $summary, ?string $featureFilter): array
    {
        $actions = [$this->commandPrefix . ' compile graph --json'];

        if ((int) ($summary['error'] ?? 0) > 0 || (int) ($summary['warning'] ?? 0) > 0) {
            if ($featureFilter !== null && $featureFilter !== '') {
                $actions[] = $this->commandPrefix . ' verify feature ' . $featureFilter . ' --json';
                $actions[] = $this->commandPrefix . ' inspect impact feature:' . $featureFilter . ' --json';
            }

            $actions[] = $this->commandPrefix . ' verify graph --json';
            $actions[] = $this->commandPrefix . ' verify contracts --json';
            $actions[] = 'php vendor/bin/phpunit';
        }

        sort($actions);

        return array_values(array_unique($actions));
    }

    /**
     * @param array{error:int,warning:int,info:int,total:int} $summary
     */
    private function riskLevel(array $summary): string
    {
        if ((int) ($summary['error'] ?? 0) > 0) {
            return 'high';
        }

        if ((int) ($summary['warning'] ?? 0) > 0) {
            return 'medium';
        }

        return 'low';
    }
}
