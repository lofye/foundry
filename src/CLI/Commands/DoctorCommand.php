<?php
declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Compiler\Analysis\ArchitectureDoctor;
use Foundry\Compiler\CompileOptions;
use Foundry\Support\FoundryError;

final class DoctorCommand extends Command
{
    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'doctor';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        [$feature, $strict] = $this->parseOptions($args);
        if ($feature !== null && $feature !== '' && !$this->featureExists($context, $feature)) {
            throw new FoundryError(
                'FEATURE_NOT_FOUND',
                'not_found',
                ['feature' => $feature],
                'Feature not found.',
            );
        }

        $compiler = $context->graphCompiler();
        $compileResult = $compiler->compile(new CompileOptions(
            feature: $feature,
            changedOnly: false,
            emit: true,
        ));

        $doctor = new ArchitectureDoctor(
            analyzers: $context->extensionRegistry()->graphAnalyzers(),
            impactAnalyzer: $compiler->impactAnalyzer(),
        );
        $analysis = $doctor->analyze($compileResult->graph, $feature);

        $compileSummary = $compileResult->diagnostics->summary();
        $doctorSummary = is_array($analysis['diagnostics']['summary'] ?? null)
            ? $analysis['diagnostics']['summary']
            : ['error' => 0, 'warning' => 0, 'info' => 0, 'total' => 0];

        $combinedSummary = [
            'error' => (int) ($compileSummary['error'] ?? 0) + (int) ($doctorSummary['error'] ?? 0),
            'warning' => (int) ($compileSummary['warning'] ?? 0) + (int) ($doctorSummary['warning'] ?? 0),
            'info' => (int) ($compileSummary['info'] ?? 0) + (int) ($doctorSummary['info'] ?? 0),
            'total' => (int) ($compileSummary['total'] ?? 0) + (int) ($doctorSummary['total'] ?? 0),
        ];

        $status = (int) ($combinedSummary['error'] ?? 0) > 0 ? 1 : 0;
        if ($strict && ((int) ($combinedSummary['warning'] ?? 0) > 0 || (int) ($combinedSummary['error'] ?? 0) > 0)) {
            $status = 1;
        }

        return [
            'status' => $status,
            'message' => $status === 0 ? 'Doctor checks passed.' : 'Doctor found architecture issues.',
            'payload' => [
                'graph_version' => $compileResult->graph->graphVersion(),
                'framework_version' => $compileResult->graph->frameworkVersion(),
                'compiled_at' => $compileResult->graph->compiledAt(),
                'source_hash' => $compileResult->graph->sourceHash(),
                'feature_filter' => $feature,
                'strict' => $strict,
                'risk' => (string) ($analysis['risk'] ?? 'low'),
                'compile_diagnostics' => [
                    'summary' => $compileSummary,
                    'items' => $compileResult->diagnostics->toArray(),
                ],
                'doctor_diagnostics' => $analysis['diagnostics'] ?? ['summary' => [], 'items' => []],
                'diagnostics_summary' => $combinedSummary,
                'analyzers' => $analysis['analyzers'] ?? [],
                'impact_preview' => $analysis['impact_preview'] ?? null,
                'suggested_actions' => $analysis['suggested_actions'] ?? [],
            ],
        ];
    }

    /**
     * @param array<int,string> $args
     * @return array{0:?string,1:bool}
     */
    private function parseOptions(array $args): array
    {
        $feature = null;
        $strict = false;

        foreach ($args as $index => $arg) {
            if ($arg === '--strict') {
                $strict = true;
                continue;
            }

            if (str_starts_with($arg, '--feature=')) {
                $feature = substr($arg, strlen('--feature='));
                continue;
            }

            if ($arg === '--feature') {
                $value = (string) ($args[$index + 1] ?? '');
                if ($value !== '') {
                    $feature = $value;
                }
            }
        }

        if ($feature === '') {
            $feature = null;
        }

        return [$feature, $strict];
    }

    private function featureExists(CommandContext $context, string $feature): bool
    {
        return is_dir($context->paths()->features() . '/' . $feature);
    }
}

