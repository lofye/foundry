<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Observability\ObservationComparator;
use Foundry\Tooling\BuildArtifactStore;

final class RegressionsCommand extends Command
{
    #[\Override]
    public function supportedSignatures(): array
    {
        return ['regressions'];
    }

    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'regressions';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $store = new BuildArtifactStore($context->graphCompiler()->buildLayout());
        (new DoctorCommand())->run(['doctor', '--quality'], $context);
        (new ObserveCommand())->run(['observe:profile'], $context);

        $currentQuality = $store->latestRecord('quality');
        $currentProfile = $store->latestRecord('profile');
        $previousQuality = $currentQuality !== null
            ? $store->previousRecord('quality', (string) ($currentQuality['id'] ?? ''))
            : null;
        $previousProfile = $currentProfile !== null
            ? $store->previousRecord('profile', (string) ($currentProfile['id'] ?? ''))
            : null;

        $comparator = new ObservationComparator();
        $qualityComparison = ($currentQuality !== null && $previousQuality !== null)
            ? $comparator->compare($previousQuality, $currentQuality)
            : null;
        $profileComparison = ($currentProfile !== null && $previousProfile !== null)
            ? $comparator->compare($previousProfile, $currentProfile)
            : null;

        $qualityRegressions = array_values((array) (($qualityComparison['regressions'] ?? [])));
        $profileRegressions = array_values((array) (($profileComparison['regressions'] ?? [])));
        $allRegressions = array_merge($qualityRegressions, $profileRegressions);

        $qualityPayload = is_array($currentQuality['payload'] ?? null) ? $currentQuality['payload'] : [];
        $changedPaths = array_values((array) (($profileComparison['changed_execution_paths'] ?? [])));
        $localizedFeatures = [];
        foreach ($changedPaths as $row) {
            if (!is_array($row)) {
                continue;
            }

            $before = is_array($row['before'] ?? null) ? $row['before'] : [];
            $after = is_array($row['after'] ?? null) ? $row['after'] : [];
            $feature = (string) (($after['feature'] ?? $before['feature'] ?? ''));
            if ($feature !== '') {
                $localizedFeatures[] = $feature;
            }
        }
        sort($localizedFeatures);
        $localizedFeatures = array_values(array_unique($localizedFeatures));

        $payload = [
            'summary' => [
                'new_failures' => $this->countRegressionType($qualityRegressions, 'new_failures'),
                'performance_regressions' => $this->countRegressionType($profileRegressions, 'timing_regression')
                    + $this->countRegressionType($profileRegressions, 'memory_regression'),
                'static_analysis_regressions' => $this->countRegressionType($qualityRegressions, 'static_analysis_regression'),
            ],
            'quality_comparison' => $qualityComparison,
            'profile_comparison' => $profileComparison,
            'regressions' => $allRegressions,
            'bug_fix_loop' => [
                'failure_detection' => $allRegressions,
                'evidence_collection' => array_values(array_filter([
                    $context->paths()->join('app/.foundry/build/quality/summary.json'),
                    $context->paths()->join('app/.foundry/build/observability/profile.json'),
                ])),
                'localization' => $localizedFeatures,
                'suggested_fixes' => array_values((array) ($qualityPayload['suggested_actions'] ?? [])),
                'revalidation' => [
                    'php bin/foundry doctor --quality --json',
                    'php bin/foundry observe:profile --json',
                ],
            ],
        ];

        return [
            'status' => $allRegressions === [] ? 0 : 1,
            'message' => $allRegressions === [] ? 'No regressions detected.' : 'Regressions detected.',
            'payload' => $payload,
        ];
    }

    /**
     * @param array<int,mixed> $regressions
     */
    private function countRegressionType(array $regressions, string $type): int
    {
        $count = 0;
        foreach ($regressions as $row) {
            if (!is_array($row) || (string) ($row['type'] ?? '') !== $type) {
                continue;
            }

            $count++;
        }

        return $count;
    }
}
