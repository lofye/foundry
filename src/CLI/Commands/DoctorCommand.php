<?php
declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Compiler\Analysis\ArchitectureDoctor;
use Foundry\Compiler\CompileOptions;
use Foundry\Doctor\Checks\CompileHealthCheck;
use Foundry\Doctor\Checks\DirectoryHealthCheck;
use Foundry\Doctor\Checks\ExtensionCompatibilityCheck;
use Foundry\Doctor\Checks\GraphIntegrityCheck;
use Foundry\Doctor\Checks\InstallCompletenessCheck;
use Foundry\Doctor\Checks\MetadataFreshnessCheck;
use Foundry\Doctor\Checks\PipelineConsistencyCheck;
use Foundry\Doctor\Checks\RuntimeCompatibilityCheck;
use Foundry\Doctor\DoctorContext as FrameworkDoctorContext;
use Foundry\Doctor\DoctorSummary;
use Foundry\Doctor\FrameworkDoctor;
use Foundry\Pipeline\PipelineIntegrityInspector;
use Foundry\Support\CliCommandPrefix;
use Foundry\Support\FoundryError;
use Foundry\Support\Json;
use Foundry\Support\Paths;

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

        $paths = $context->paths();
        $commandPrefix = $this->commandPrefix($paths);
        $extensionRegistry = $context->extensionRegistry();
        $compiler = $context->graphCompiler();
        $compileResult = $compiler->compile(new CompileOptions(
            feature: $feature,
            changedOnly: false,
            emit: true,
        ));
        $extensionReport = $extensionRegistry->compatibilityReport(
            frameworkVersion: $compileResult->graph->frameworkVersion(),
            graphVersion: $compileResult->graph->graphVersion(),
        );
        [$composerConfig, $composerError] = $this->loadComposerConfig($paths->join('composer.json'));

        $doctor = new FrameworkDoctor(
            checks: array_merge($this->builtInChecks(), $extensionRegistry->doctorChecks()),
            architectureDoctor: new ArchitectureDoctor(
                analyzers: $extensionRegistry->graphAnalyzers(),
                impactAnalyzer: $compiler->impactAnalyzer(),
                commandPrefix: $commandPrefix,
            ),
        );
        $analysis = $doctor->diagnose(new FrameworkDoctorContext(
            paths: $paths,
            layout: $compiler->buildLayout(),
            compileResult: $compileResult,
            extensionRegistry: $extensionRegistry,
            extensionReport: $extensionReport,
            featureFilter: $feature,
            commandPrefix: $commandPrefix,
            composerPath: $paths->join('composer.json'),
            composerConfig: $composerConfig,
            composerError: $composerError,
        ));

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

        $payload = [
            'ok' => $status === 0,
            'exit_code' => $status,
            'graph_version' => $compileResult->graph->graphVersion(),
            'framework_version' => $compileResult->graph->frameworkVersion(),
            'compiled_at' => $compileResult->graph->compiledAt(),
            'source_hash' => $compileResult->graph->sourceHash(),
            'feature_filter' => $feature,
            'strict' => $strict,
            'command_prefix' => $commandPrefix,
            'project_type' => $paths->root() === $paths->frameworkRoot() ? 'framework_repository' : 'application',
            'risk' => DoctorSummary::risk($combinedSummary),
            'compile_diagnostics' => [
                'summary' => $compileSummary,
                'items' => $compileResult->diagnostics->toArray(),
            ],
            'config_validation' => $compileResult->configValidation,
            'config_schemas' => [
                'count' => count($compileResult->configSchemas),
                'path' => (string) (($compileResult->manifest['config_schemas']['path'] ?? '')),
            ],
            'doctor_diagnostics' => $analysis['diagnostics'] ?? ['summary' => [], 'items' => []],
            'extension_diagnostics' => $extensionReport->diagnostics,
            'extension_lifecycle' => $extensionReport->lifecycle,
            'extension_load_order' => $extensionReport->loadOrder,
            'diagnostics_summary' => $combinedSummary,
            'checks' => $analysis['checks'] ?? [],
            'analyzers' => $analysis['analyzers'] ?? [],
            'impact_preview' => $analysis['impact_preview'] ?? null,
            'suggested_actions' => $analysis['suggested_actions'] ?? [],
        ];

        return [
            'status' => $status,
            'message' => $context->expectsJson() ? null : $this->renderHumanReport($payload),
            'payload' => $context->expectsJson() ? $payload : null,
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

    /**
     * @return array<int,\Foundry\Doctor\DoctorCheck>
     */
    private function builtInChecks(): array
    {
        return [
            new CompileHealthCheck(),
            new DirectoryHealthCheck(),
            new ExtensionCompatibilityCheck(),
            new GraphIntegrityCheck(),
            new InstallCompletenessCheck(),
            new MetadataFreshnessCheck(),
            new PipelineConsistencyCheck(new PipelineIntegrityInspector()),
            new RuntimeCompatibilityCheck(),
        ];
    }

    private function commandPrefix(Paths $paths): string
    {
        return CliCommandPrefix::foundry($paths);
    }

    /**
     * @return array{0:?array<string,mixed>,1:?string}
     */
    private function loadComposerConfig(string $composerPath): array
    {
        if (!is_file($composerPath)) {
            return [null, null];
        }

        $json = file_get_contents($composerPath);
        if ($json === false) {
            return [null, 'composer.json could not be read'];
        }

        try {
            return [Json::decodeAssoc($json), null];
        } catch (\Throwable $error) {
            return [null, $error->getMessage()];
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderHumanReport(array $payload): string
    {
        $summary = is_array($payload['diagnostics_summary'] ?? null)
            ? $payload['diagnostics_summary']
            : ['error' => 0, 'warning' => 0, 'info' => 0, 'total' => 0];

        $headline = 'Foundry doctor passed.';
        if ((int) ($summary['error'] ?? 0) > 0) {
            $headline = 'Foundry doctor found issues.';
        } elseif ((int) ($summary['warning'] ?? 0) > 0) {
            $headline = 'Foundry doctor completed with warnings.';
        }

        $lines = [$headline];
        $lines[] = sprintf(
            'Summary: %d error(s), %d warning(s), %d info.',
            (int) ($summary['error'] ?? 0),
            (int) ($summary['warning'] ?? 0),
            (int) ($summary['info'] ?? 0),
        );

        $featureFilter = trim((string) ($payload['feature_filter'] ?? ''));
        if ($featureFilter !== '') {
            $lines[] = 'Feature filter: ' . $featureFilter;
        }

        $checks = is_array($payload['checks'] ?? null) ? $payload['checks'] : [];
        if ($checks !== []) {
            $lines[] = '';
            $lines[] = 'Checks:';
            foreach ($checks as $id => $row) {
                if (!is_array($row)) {
                    continue;
                }

                $status = (string) (($row['result']['status'] ?? null) ?? 'passed');
                $lines[] = '- ' . $id . ': ' . $status;
            }
        }

        $diagnostics = array_merge(
            array_values((array) (($payload['compile_diagnostics']['items'] ?? []))),
            array_values((array) (($payload['doctor_diagnostics']['items'] ?? []))),
        );
        if ($diagnostics !== []) {
            $lines[] = '';
            $lines[] = 'Diagnostics:';
            foreach ($diagnostics as $diagnostic) {
                if (!is_array($diagnostic)) {
                    continue;
                }

                $severity = strtoupper((string) ($diagnostic['severity'] ?? 'info'));
                $code = (string) ($diagnostic['code'] ?? 'FDY0000_UNKNOWN');
                $message = (string) ($diagnostic['message'] ?? 'Diagnostic emitted.');
                $lines[] = '- [' . $severity . '] ' . $code . ' ' . $message;

                $why = trim((string) ($diagnostic['why_it_matters'] ?? ''));
                if ($why !== '') {
                    $lines[] = '  Why: ' . $why;
                }

                $fix = trim((string) ($diagnostic['suggested_fix'] ?? ''));
                if ($fix !== '') {
                    $lines[] = '  Fix: ' . $fix;
                }
            }
        }

        $actions = array_values(array_filter(array_map(
            'strval',
            (array) ($payload['suggested_actions'] ?? []),
        )));
        if ($actions !== []) {
            $lines[] = '';
            $lines[] = 'Suggested actions:';
            foreach ($actions as $action) {
                $lines[] = '- ' . $action;
            }
        }

        return implode(PHP_EOL, $lines);
    }
}
