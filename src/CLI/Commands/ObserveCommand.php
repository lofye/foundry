<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Compiler\CompileOptions;
use Foundry\Observability\GraphExecutionMap;
use Foundry\Observability\ObservationComparator;
use Foundry\Observability\ProfileObserver;
use Foundry\Observability\TraceObserver;
use Foundry\Support\FoundryError;
use Foundry\Tooling\BuildArtifactStore;

final class ObserveCommand extends Command
{
    #[\Override]
    public function supportedSignatures(): array
    {
        return ['observe:trace', 'observe:profile', 'observe:compare'];
    }

    #[\Override]
    public function matches(array $args): bool
    {
        return $this->supportsSignature((string) ($args[0] ?? ''));
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $command = (string) ($args[0] ?? '');
        $store = new BuildArtifactStore($context->graphCompiler()->buildLayout());

        if ($command === 'observe:compare') {
            $runA = (string) ($args[1] ?? '');
            $runB = (string) ($args[2] ?? '');
            if ($runA === '' || $runB === '') {
                throw new FoundryError(
                    'OBSERVE_COMPARE_RUNS_REQUIRED',
                    'validation',
                    [],
                    'Observe compare requires two history record ids.',
                );
            }

            $left = $store->loadHistory($runA);
            $right = $store->loadHistory($runB);
            if ($left === null || $right === null) {
                throw new FoundryError(
                    'OBSERVE_COMPARE_RECORD_NOT_FOUND',
                    'not_found',
                    ['run_a' => $runA, 'run_b' => $runB],
                    'Observe compare could not load one or both history records.',
                );
            }

            $payload = (new ObservationComparator())->compare($left, $right);
            $record = $store->persistComparisonReport($payload);
            $payload['record'] = [
                'id' => (string) ($record['id'] ?? ''),
                'sequence' => (int) ($record['sequence'] ?? 0),
            ];

            return [
                'status' => count((array) ($payload['regressions'] ?? [])) > 0 ? 1 : 0,
                'message' => 'Observation comparison completed.',
                'payload' => $payload,
            ];
        }

        [$feature, $routeSignature] = $this->parseTargetOptions($args);
        if ($feature !== null && $feature !== '' && !is_dir($context->paths()->features() . '/' . $feature)) {
            throw new FoundryError(
                'FEATURE_NOT_FOUND',
                'not_found',
                ['feature' => $feature],
                'Feature not found.',
            );
        }

        $memoryStart = memory_get_usage(true);
        $compileStart = microtime(true);
        $compileResult = $context->graphCompiler()->compile(new CompileOptions(
            feature: $feature,
            changedOnly: false,
            emit: true,
        ));
        $compileMs = (microtime(true) - $compileStart) * 1000;
        $store->persistBuildSummary($compileResult);

        $mapStart = microtime(true);
        $executionPaths = (new GraphExecutionMap($compileResult->graph))->paths($feature, $routeSignature);
        $mappingMs = (microtime(true) - $mapStart) * 1000;

        if ($command === 'observe:trace') {
            $payload = (new TraceObserver())->observe(
                $executionPaths,
                $compileResult->graph->sourceHash(),
                $feature,
                $routeSignature,
            );
            $record = $store->persistTraceReport($payload);
        } else {
            $payload = (new ProfileObserver())->observe(
                $executionPaths,
                $compileResult->graph->sourceHash(),
                $compileMs,
                $mappingMs,
                [
                    'start' => $memoryStart,
                    'end' => memory_get_usage(true),
                    'peak' => memory_get_peak_usage(true),
                ],
                $feature,
                $routeSignature,
            );
            $record = $store->persistProfileReport($payload);
        }

        $payload['record'] = [
            'id' => (string) ($record['id'] ?? ''),
            'sequence' => (int) ($record['sequence'] ?? 0),
        ];

        return [
            'status' => 0,
            'message' => $command === 'observe:trace' ? 'Trace summary captured.' : 'Profile summary captured.',
            'payload' => $payload,
        ];
    }

    /**
     * @param array<int,string> $args
     * @return array{0:?string,1:?string}
     */
    private function parseTargetOptions(array $args): array
    {
        $feature = null;
        $routeSignature = null;

        foreach ($args as $index => $arg) {
            if (str_starts_with($arg, '--feature=')) {
                $feature = substr($arg, strlen('--feature='));
                continue;
            }

            if ($arg === '--feature') {
                $value = (string) ($args[$index + 1] ?? '');
                if ($value !== '') {
                    $feature = $value;
                }

                continue;
            }

            if (str_starts_with($arg, '--route=')) {
                $routeSignature = substr($arg, strlen('--route='));
                continue;
            }
        }

        if ($feature === null && isset($args[1]) && !str_starts_with((string) $args[1], '--')) {
            $feature = (string) $args[1];
        }

        return [$feature !== '' ? $feature : null, $routeSignature !== '' ? $routeSignature : null];
    }
}
