<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Application;
use Foundry\CLI\CliSurfaceVerifier;
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
use Foundry\Pro\CLI\Concerns\InteractsWithPro;
use Foundry\Pro\DeepDiagnosticsBuilder;
use Foundry\Quality\QualityToolRunner;
use Foundry\Support\CliCommandPrefix;
use Foundry\Support\FoundryError;
use Foundry\Support\Json;
use Foundry\Support\Paths;
use Foundry\Tooling\BuildArtifactStore;

final class DoctorCommand extends Command
{
    use InteractsWithPro;

    #[\Override]
    public function supportedSignatures(): array
    {
        return ['doctor'];
    }

    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'doctor';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        [$feature, $strict, $includeCli, $deep, $staticMode, $styleMode, $qualityMode, $includeTests] = $this->parseOptions($args);
        $runStatic = $qualityMode || $staticMode;
        $runStyle = $qualityMode || $styleMode;

        if ($feature !== null && $feature !== '' && !$this->featureExists($context, $feature)) {
            throw new FoundryError(
                'FEATURE_NOT_FOUND',
                'not_found',
                ['feature' => $feature],
                'Feature not found.',
            );
        }
        $deepLicense = $deep ? $this->requirePro('doctor --deep', ['deep_diagnostics']) : null;

        $paths = $context->paths();
        $commandPrefix = $this->commandPrefix($paths);
        $extensionRegistry = $context->extensionRegistry();
        $compiler = $context->graphCompiler();
        $compileResult = $compiler->compile(new CompileOptions(
            feature: $feature,
            changedOnly: false,
            emit: true,
        ));
        $artifactStore = new BuildArtifactStore($compiler->buildLayout());
        $artifactStore->persistBuildSummary($compileResult);
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
        $qualityRunner = new QualityToolRunner($paths);
        $staticAnalysis = $runStatic ? $qualityRunner->runStaticAnalysis() : $this->skippedToolResult('phpstan');
        $styleViolations = $runStyle ? $qualityRunner->runStyleCheck() : $this->skippedToolResult('pint');
        $testSummary = ($qualityMode && $includeTests) ? $qualityRunner->runTests() : null;

        $qualityDiagnostics = array_merge(
            array_values((array) ($staticAnalysis['diagnostics'] ?? [])),
            array_values((array) ($styleViolations['diagnostics'] ?? [])),
        );
        $qualitySummary = DoctorSummary::fromRows($qualityDiagnostics);

        $checkRows = is_array($analysis['checks'] ?? null) ? $analysis['checks'] : [];
        if ($runStatic) {
            $checkRows['static_analysis'] = [
                'description' => 'Runs PHPStan static analysis over the current project root.',
                'result' => [
                    'status' => (string) ($staticAnalysis['status'] ?? 'passed'),
                    'diagnostics_summary' => DoctorSummary::fromRows((array) ($staticAnalysis['diagnostics'] ?? [])),
                    'summary' => $staticAnalysis['summary'] ?? [],
                ],
            ];
        }
        if ($runStyle) {
            $checkRows['style'] = [
                'description' => 'Runs Pint formatting checks over the current project root.',
                'result' => [
                    'status' => (string) ($styleViolations['status'] ?? 'passed'),
                    'diagnostics_summary' => DoctorSummary::fromRows((array) ($styleViolations['diagnostics'] ?? [])),
                    'summary' => $styleViolations['summary'] ?? [],
                ],
            ];
        }

        $combinedSummary = [
            'error' => (int) ($compileSummary['error'] ?? 0) + (int) ($doctorSummary['error'] ?? 0) + (int) ($qualitySummary['error'] ?? 0),
            'warning' => (int) ($compileSummary['warning'] ?? 0) + (int) ($doctorSummary['warning'] ?? 0) + (int) ($qualitySummary['warning'] ?? 0),
            'info' => (int) ($compileSummary['info'] ?? 0) + (int) ($doctorSummary['info'] ?? 0) + (int) ($qualitySummary['info'] ?? 0),
            'total' => (int) ($compileSummary['total'] ?? 0) + (int) ($doctorSummary['total'] ?? 0) + (int) ($qualitySummary['total'] ?? 0),
        ];

        $status = (int) ($combinedSummary['error'] ?? 0) > 0 ? 1 : 0;
        if ($strict && ((int) ($combinedSummary['warning'] ?? 0) > 0 || (int) ($combinedSummary['error'] ?? 0) > 0)) {
            $status = 1;
        }
        if ($runStatic && (int) ($staticAnalysis['summary']['total'] ?? 0) > 0) {
            $status = 1;
        }
        if ($runStyle && (int) ($styleViolations['summary']['total'] ?? 0) > 0) {
            $status = 1;
        }
        if (is_array($testSummary) && (($testSummary['ok'] ?? false) !== true)) {
            $status = 1;
        }

        $payload = [
            'graph_version' => $compileResult->graph->graphVersion(),
            'framework_version' => $compileResult->graph->frameworkVersion(),
            'compiled_at' => $compileResult->graph->compiledAt(),
            'source_hash' => $compileResult->graph->sourceHash(),
            'feature_filter' => $feature,
            'strict' => $strict,
            'cli' => $includeCli,
            'command_prefix' => $commandPrefix,
            'quality_mode' => [
                'static' => $runStatic,
                'style' => $runStyle,
                'quality' => $qualityMode,
                'tests' => $includeTests,
            ],
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
            'static_analysis' => $staticAnalysis,
            'style_violations' => $styleViolations,
            'test_summary' => $testSummary,
            'extension_diagnostics' => $extensionReport->diagnostics,
            'extension_lifecycle' => $extensionReport->lifecycle,
            'extension_load_order' => $extensionReport->loadOrder,
            'diagnostics_summary' => $combinedSummary,
            'checks' => $checkRows,
            'analyzers' => $analysis['analyzers'] ?? [],
            'impact_preview' => $analysis['impact_preview'] ?? null,
            'suggested_actions' => $this->qualitySuggestedActions(
                array_values(array_map('strval', (array) ($analysis['suggested_actions'] ?? []))),
                $runStatic,
                $runStyle,
                $includeTests,
                $staticAnalysis,
                $styleViolations,
                $testSummary,
            ),
        ];
        if ($deep && is_array($deepLicense)) {
            $payload['deep'] = true;
            $payload['pro'] = [
                'license' => $deepLicense,
                'deep_diagnostics' => (new DeepDiagnosticsBuilder())->build(
                    $compileResult->graph,
                    $feature,
                ),
            ];
        }

        if ($runStatic || $runStyle || $qualityMode) {
            $qualityRecord = $artifactStore->persistQualityReport($payload);
            $payload['quality_record'] = [
                'id' => (string) ($qualityRecord['id'] ?? ''),
                'sequence' => (int) ($qualityRecord['sequence'] ?? 0),
            ];
        }

        if ($includeCli) {
            $payload['cli_surface'] = (new CliSurfaceVerifier(
                $context->apiSurfaceRegistry(),
                Application::registeredCommands(),
            ))->verify();

            $cliSurface = is_array($payload['cli_surface']) ? $payload['cli_surface'] : [];
            if ((int) ($cliSurface['invalid'] ?? 0) > 0
                || (int) ($cliSurface['ambiguous'] ?? 0) > 0
                || (int) ($cliSurface['orphan_handlers'] ?? 0) > 0) {
                $status = 1;
            }
        }

        $payload['ok'] = $status === 0;
        $payload['exit_code'] = $status;

        return [
            'status' => $status,
            'message' => $context->expectsJson() ? null : $this->renderHumanReport($payload),
            'payload' => $context->expectsJson() ? $payload : null,
        ];
    }

    /**
     * @param array<int,string> $args
     * @return array{0:?string,1:bool,2:bool,3:bool,4:bool,5:bool,6:bool,7:bool}
     */
    private function parseOptions(array $args): array
    {
        $feature = null;
        $strict = false;
        $includeCli = false;
        $deep = false;
        $staticMode = false;
        $styleMode = false;
        $qualityMode = false;
        $includeTests = false;

        foreach ($args as $index => $arg) {
            if ($arg === '--strict') {
                $strict = true;
                continue;
            }

            if ($arg === '--cli') {
                $includeCli = true;
                continue;
            }

            if ($arg === '--deep') {
                $deep = true;
                continue;
            }

            if ($arg === '--static') {
                $staticMode = true;
                continue;
            }

            if ($arg === '--style') {
                $styleMode = true;
                continue;
            }

            if ($arg === '--quality') {
                $qualityMode = true;
                continue;
            }

            if ($arg === '--tests') {
                $includeTests = true;
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

        return [$feature, $strict, $includeCli, $deep, $staticMode, $styleMode, $qualityMode, $includeTests];
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
        if (($payload['deep'] ?? false) === true) {
            $headline = 'Foundry doctor completed with deep diagnostics.';
            if ((int) ($summary['error'] ?? 0) > 0) {
                $headline = 'Foundry doctor found issues during deep diagnostics.';
            } elseif ((int) ($summary['warning'] ?? 0) > 0) {
                $headline = 'Foundry doctor completed with warnings and deep diagnostics.';
            }
        } elseif ((int) ($summary['error'] ?? 0) > 0) {
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

        $cliSurface = is_array($payload['cli_surface'] ?? null) ? $payload['cli_surface'] : null;
        if ($cliSurface !== null) {
            $lines[] = '';
            $lines[] = 'CLI surface:';
            $lines[] = sprintf('Coverage: %.2f%%', ((float) ($cliSurface['coverage'] ?? 0.0)) * 100);

            $invalid = array_values(array_filter(array_map('strval', (array) (($cliSurface['details']['invalid'] ?? [])))));
            if ($invalid !== []) {
                $lines[] = '- invalid signatures: ' . implode(', ', $invalid);
            }

            $ambiguous = array_values(array_filter(array_map('strval', (array) (($cliSurface['details']['ambiguous'] ?? [])))));
            if ($ambiguous !== []) {
                $lines[] = '- ambiguous mappings: ' . implode(', ', $ambiguous);
            }

            $orphans = array_values(array_filter(array_map('strval', (array) (($cliSurface['details']['orphan_handlers'] ?? [])))));
            if ($orphans !== []) {
                $lines[] = '- orphan handlers: ' . implode(', ', $orphans);
            }
        }

        $staticAnalysis = is_array($payload['static_analysis'] ?? null) ? $payload['static_analysis'] : null;
        if ($staticAnalysis !== null && (string) ($staticAnalysis['status'] ?? 'skipped') !== 'skipped') {
            $lines[] = '';
            $lines[] = 'Static analysis:';
            $lines[] = sprintf(
                'PHPStan: %s (%d issue(s))',
                (string) ($staticAnalysis['status'] ?? 'unknown'),
                (int) (($staticAnalysis['summary']['total'] ?? 0)),
            );
        }

        $styleViolations = is_array($payload['style_violations'] ?? null) ? $payload['style_violations'] : null;
        if ($styleViolations !== null && (string) ($styleViolations['status'] ?? 'skipped') !== 'skipped') {
            $lines[] = '';
            $lines[] = 'Style:';
            $lines[] = sprintf(
                'Pint: %s (%d issue(s))',
                (string) ($styleViolations['status'] ?? 'unknown'),
                (int) (($styleViolations['summary']['total'] ?? 0)),
            );
        }

        $deep = is_array($payload['pro']['deep_diagnostics'] ?? null)
            ? $payload['pro']['deep_diagnostics']
            : [];
        $graph = is_array($deep['graph'] ?? null) ? $deep['graph'] : [];
        if (($payload['deep'] ?? false) === true) {
            $lines[] = sprintf(
                'Graph: %d node(s), %d edge(s).',
                (int) ($graph['node_count'] ?? 0),
                (int) ($graph['edge_count'] ?? 0),
            );

            $hotspots = array_values(array_filter(
                (array) ($deep['hotspots'] ?? []),
                static fn(mixed $row): bool => is_array($row),
            ));
            if ($hotspots !== []) {
                $lines[] = 'Top hotspots:';
                foreach (array_slice($hotspots, 0, 5) as $row) {
                    $lines[] = sprintf(
                        '- %s (%s, %d connection(s))',
                        (string) ($row['label'] ?? $row['node_id'] ?? 'unknown'),
                        (string) ($row['type'] ?? 'node'),
                        (int) ($row['connections'] ?? 0),
                    );
                }
            }
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
            array_values((array) (($payload['static_analysis']['diagnostics'] ?? []))),
            array_values((array) (($payload['style_violations']['diagnostics'] ?? []))),
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

    /**
     * @return array<string,mixed>
     */
    private function skippedToolResult(string $tool): array
    {
        return [
            'tool' => $tool,
            'available' => false,
            'ok' => true,
            'status' => 'skipped',
            'exit_code' => null,
            'command' => [],
            'summary' => ['total' => 0],
            'issues' => [],
            'diagnostics' => [],
        ];
    }

    /**
     * @param array<int,string> $existing
     * @param array<string,mixed> $staticAnalysis
     * @param array<string,mixed> $styleViolations
     * @param array<string,mixed>|null $testSummary
     * @return array<int,string>
     */
    private function qualitySuggestedActions(
        array $existing,
        bool $runStatic,
        bool $runStyle,
        bool $includeTests,
        array $staticAnalysis,
        array $styleViolations,
        ?array $testSummary,
    ): array {
        $actions = $existing;

        if ($runStatic && (int) ($staticAnalysis['summary']['total'] ?? 0) > 0) {
            $actions[] = 'composer analyse';
        }

        if ($runStyle && (int) ($styleViolations['summary']['total'] ?? 0) > 0) {
            $actions[] = 'composer lint:fix';
        }

        if ($includeTests && is_array($testSummary) && (($testSummary['ok'] ?? false) !== true)) {
            $actions[] = 'vendor/bin/phpunit';
        }

        sort($actions);

        return array_values(array_unique($actions));
    }
}
