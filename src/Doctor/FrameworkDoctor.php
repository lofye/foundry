<?php
declare(strict_types=1);

namespace Foundry\Doctor;

use Foundry\Compiler\Analysis\ArchitectureDoctor;
use Foundry\Compiler\Diagnostics\DiagnosticBag;

final readonly class FrameworkDoctor
{
    /**
     * @param array<int,DoctorCheck> $checks
     */
    public function __construct(
        private array $checks,
        private ArchitectureDoctor $architectureDoctor,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function diagnose(DoctorContext $context): array
    {
        $checkResults = [];
        $doctorItems = [];

        foreach ($this->sortedChecks() as $check) {
            $diagnostics = new DiagnosticBag();
            $result = $check->check($context, $diagnostics);
            $summary = is_array($result['diagnostics_summary'] ?? null)
                ? $result['diagnostics_summary']
                : $diagnostics->summary();
            $result['diagnostics_summary'] = $summary;
            $result['status'] = (string) ($result['status'] ?? DoctorSummary::status($summary));

            $checkResults[$check->id()] = [
                'description' => $check->description(),
                'result' => $result,
            ];

            $doctorItems = array_merge($doctorItems, $diagnostics->toArray());
        }
        ksort($checkResults);

        $architecture = $this->architectureDoctor->analyze($context->compileResult->graph, $context->featureFilter);
        $doctorItems = array_merge($doctorItems, (array) ($architecture['diagnostics']['items'] ?? []));
        $doctorSummary = DoctorSummary::fromRows($doctorItems);

        return [
            'feature_filter' => $context->featureFilter,
            'risk' => DoctorSummary::risk($doctorSummary),
            'diagnostics' => [
                'summary' => $doctorSummary,
                'items' => $doctorItems,
            ],
            'checks' => $checkResults,
            'analyzers' => $architecture['analyzers'] ?? [],
            'impact_preview' => $architecture['impact_preview'] ?? null,
            'suggested_actions' => $this->suggestedActions($context, $architecture, $doctorItems),
        ];
    }

    /**
     * @return array<int,DoctorCheck>
     */
    private function sortedChecks(): array
    {
        $checks = array_values(array_filter(
            $this->checks,
            static fn (mixed $check): bool => $check instanceof DoctorCheck,
        ));

        usort($checks, static fn (DoctorCheck $a, DoctorCheck $b): int => strcmp($a->id(), $b->id()));

        return $checks;
    }

    /**
     * @param array<string,mixed> $architecture
     * @param array<int,array<string,mixed>> $doctorItems
     * @return array<int,string>
     */
    private function suggestedActions(DoctorContext $context, array $architecture, array $doctorItems): array
    {
        $actions = array_values(array_filter(array_map(
            'strval',
            (array) ($architecture['suggested_actions'] ?? []),
        )));

        $extensionSummary = DoctorSummary::fromRows($context->extensionReport->diagnostics);
        if ((int) ($extensionSummary['error'] ?? 0) > 0 || (int) ($extensionSummary['warning'] ?? 0) > 0) {
            $actions[] = $context->commandPrefix . ' verify compatibility --json';
        }

        foreach ($doctorItems as $item) {
            if (!is_array($item) || (string) ($item['code'] ?? '') !== 'FDY9114_CONTEXT_MANIFEST_STALE') {
                continue;
            }

            $feature = (string) (($item['details']['feature'] ?? null) ?? '');
            if ($feature !== '') {
                $actions[] = $context->commandPrefix . ' generate context ' . $feature . ' --json';
            }
        }

        sort($actions);

        return array_values(array_unique($actions));
    }
}
