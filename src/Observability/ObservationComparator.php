<?php

declare(strict_types=1);

namespace Foundry\Observability;

final class ObservationComparator
{
    /**
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     * @return array<string,mixed>
     */
    public function compare(array $left, array $right): array
    {
        $leftKind = (string) ($left['kind'] ?? '');
        $rightKind = (string) ($right['kind'] ?? '');

        if ($leftKind !== $rightKind) {
            return [
                'kind' => 'mismatch',
                'run_a' => $this->meta($left),
                'run_b' => $this->meta($right),
                'regressions' => [[
                    'type' => 'kind_mismatch',
                    'message' => 'Comparison requires two records of the same kind.',
                ]],
                'performance_changes' => [],
                'changed_execution_paths' => [],
            ];
        }

        return match ($leftKind) {
            'quality' => $this->compareQuality($left, $right),
            'profile' => $this->compareProfile($left, $right),
            'trace' => $this->compareTrace($left, $right),
            default => [
                'kind' => $leftKind,
                'run_a' => $this->meta($left),
                'run_b' => $this->meta($right),
                'regressions' => [],
                'performance_changes' => [],
                'changed_execution_paths' => [],
            ],
        };
    }

    /**
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     * @return array<string,mixed>
     */
    private function compareQuality(array $left, array $right): array
    {
        $leftPayload = (array) ($left['payload'] ?? []);
        $rightPayload = (array) ($right['payload'] ?? []);

        $leftStatic = (int) (($leftPayload['static_analysis']['summary']['total'] ?? 0));
        $rightStatic = (int) (($rightPayload['static_analysis']['summary']['total'] ?? 0));
        $leftStyle = (int) (($leftPayload['style_violations']['summary']['total'] ?? 0));
        $rightStyle = (int) (($rightPayload['style_violations']['summary']['total'] ?? 0));
        $leftErrors = (int) (($leftPayload['diagnostics_summary']['error'] ?? 0));
        $rightErrors = (int) (($rightPayload['diagnostics_summary']['error'] ?? 0));

        $regressions = [];
        if ($rightErrors > $leftErrors) {
            $regressions[] = [
                'type' => 'new_failures',
                'message' => sprintf('Doctor errors increased from %d to %d.', $leftErrors, $rightErrors),
            ];
        }

        if ($rightStatic > $leftStatic) {
            $regressions[] = [
                'type' => 'static_analysis_regression',
                'message' => sprintf('Static analysis findings increased from %d to %d.', $leftStatic, $rightStatic),
            ];
        }

        if ($rightStyle > $leftStyle) {
            $regressions[] = [
                'type' => 'style_regression',
                'message' => sprintf('Style violations increased from %d to %d.', $leftStyle, $rightStyle),
            ];
        }

        return [
            'kind' => 'quality',
            'run_a' => $this->meta($left),
            'run_b' => $this->meta($right),
            'regressions' => $regressions,
            'performance_changes' => [],
            'changed_execution_paths' => [],
            'static_analysis_changes' => [
                'baseline' => $leftStatic,
                'current' => $rightStatic,
                'delta' => $rightStatic - $leftStatic,
            ],
            'style_changes' => [
                'baseline' => $leftStyle,
                'current' => $rightStyle,
                'delta' => $rightStyle - $leftStyle,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     * @return array<string,mixed>
     */
    private function compareProfile(array $left, array $right): array
    {
        $leftPayload = (array) ($left['payload'] ?? []);
        $rightPayload = (array) ($right['payload'] ?? []);

        $leftCompile = (float) (($leftPayload['timings']['compile_ms'] ?? 0.0));
        $rightCompile = (float) (($rightPayload['timings']['compile_ms'] ?? 0.0));
        $leftPeak = (int) (($leftPayload['memory']['peak_bytes'] ?? 0));
        $rightPeak = (int) (($rightPayload['memory']['peak_bytes'] ?? 0));

        $regressions = [];
        if ($rightCompile > $leftCompile && $rightCompile > ($leftCompile * 1.1)) {
            $regressions[] = [
                'type' => 'timing_regression',
                'message' => sprintf('Compile time regressed from %.3fms to %.3fms.', $leftCompile, $rightCompile),
            ];
        }

        if ($rightPeak > $leftPeak && $rightPeak > (int) round($leftPeak * 1.1)) {
            $regressions[] = [
                'type' => 'memory_regression',
                'message' => sprintf('Peak memory regressed from %d bytes to %d bytes.', $leftPeak, $rightPeak),
            ];
        }

        return [
            'kind' => 'profile',
            'run_a' => $this->meta($left),
            'run_b' => $this->meta($right),
            'regressions' => $regressions,
            'performance_changes' => [
                'compile_ms' => [
                    'baseline' => $leftCompile,
                    'current' => $rightCompile,
                    'delta' => round($rightCompile - $leftCompile, 3),
                ],
                'peak_memory_bytes' => [
                    'baseline' => $leftPeak,
                    'current' => $rightPeak,
                    'delta' => $rightPeak - $leftPeak,
                ],
            ],
            'changed_execution_paths' => $this->changedExecutionPaths(
                (array) ($leftPayload['execution_profiles'] ?? []),
                (array) ($rightPayload['execution_profiles'] ?? []),
            ),
        ];
    }

    /**
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     * @return array<string,mixed>
     */
    private function compareTrace(array $left, array $right): array
    {
        $leftPayload = (array) ($left['payload'] ?? []);
        $rightPayload = (array) ($right['payload'] ?? []);

        $changed = $this->changedExecutionPaths(
            (array) ($leftPayload['execution_paths'] ?? []),
            (array) ($rightPayload['execution_paths'] ?? []),
        );

        return [
            'kind' => 'trace',
            'run_a' => $this->meta($left),
            'run_b' => $this->meta($right),
            'regressions' => [],
            'performance_changes' => [],
            'changed_execution_paths' => $changed,
        ];
    }

    /**
     * @param array<int,mixed> $left
     * @param array<int,mixed> $right
     * @return array<int,array<string,mixed>>
     */
    private function changedExecutionPaths(array $left, array $right): array
    {
        $leftByPlan = [];
        foreach ($left as $row) {
            if (!is_array($row)) {
                continue;
            }

            $leftByPlan[(string) ($row['execution_plan'] ?? '')] = $this->executionSignature($row);
        }

        $rightByPlan = [];
        foreach ($right as $row) {
            if (!is_array($row)) {
                continue;
            }

            $rightByPlan[(string) ($row['execution_plan'] ?? '')] = $this->executionSignature($row);
        }

        $plans = array_values(array_unique(array_merge(array_keys($leftByPlan), array_keys($rightByPlan))));
        sort($plans);

        $changed = [];
        foreach ($plans as $plan) {
            if (($leftByPlan[$plan] ?? null) === ($rightByPlan[$plan] ?? null)) {
                continue;
            }

            $changed[] = [
                'execution_plan' => $plan,
                'before' => $leftByPlan[$plan] ?? null,
                'after' => $rightByPlan[$plan] ?? null,
            ];
        }

        return $changed;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function executionSignature(array $row): array
    {
        $guards = [];
        foreach ((array) ($row['guards'] ?? []) as $guard) {
            if (is_array($guard)) {
                $guards[] = (string) ($guard['id'] ?? '');
                continue;
            }

            $guards[] = (string) $guard;
        }

        $interceptors = [];
        foreach ((array) ($row['interceptors'] ?? []) as $interceptor) {
            if (is_array($interceptor)) {
                $interceptors[] = (string) ($interceptor['id'] ?? '');
                continue;
            }

            $interceptors[] = (string) $interceptor;
        }

        sort($guards);
        sort($interceptors);

        return [
            'feature' => (string) ($row['feature'] ?? ''),
            'route_signature' => $row['route_signature'] ?? null,
            'pipeline_stages' => array_values(array_map('strval', (array) ($row['pipeline_stages'] ?? []))),
            'guards' => array_values(array_filter($guards, static fn(string $value): bool => $value !== '')),
            'interceptors' => array_values(array_filter($interceptors, static fn(string $value): bool => $value !== '')),
        ];
    }

    /**
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private function meta(array $record): array
    {
        return [
            'id' => (string) ($record['id'] ?? ''),
            'kind' => (string) ($record['kind'] ?? ''),
            'label' => (string) ($record['label'] ?? ''),
            'source_hash' => $record['source_hash'] ?? null,
        ];
    }
}
