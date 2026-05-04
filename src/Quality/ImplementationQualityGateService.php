<?php

declare(strict_types=1);

namespace Foundry\Quality;

use Foundry\Git\GitRepositoryInspector;
use Foundry\Support\Paths;
use Foundry\Tooling\ProcessRunner;

final class ImplementationQualityGateService
{
    private const float GLOBAL_LINE_THRESHOLD = 90.0;
    private const string COVERAGE_CLOVER_PATH = 'build/coverage/clover.xml';

    public function __construct(
        private readonly Paths $paths,
        private readonly ProcessRunner $runner = new ProcessRunner(),
        private readonly ?CloverCoverageVerifier $coverageVerifier = null,
    ) {}

    /**
     * @param list<string>|null $changedFiles
     * @return array<string,mixed>
     */
    public function verify(?array $changedFiles = null): array
    {
        $threshold = self::GLOBAL_LINE_THRESHOLD;
        $fullSuiteCommand = ['php', 'vendor/bin/phpunit'];
        $coverageCommand = [
            'php',
            '-d',
            'xdebug.mode=coverage',
            'vendor/bin/phpunit',
            '--coverage-clover',
            self::COVERAGE_CLOVER_PATH,
        ];
        $cloverPath = $this->paths->join(self::COVERAGE_CLOVER_PATH);

        $this->prepareCloverPath($cloverPath);

        $fullSuiteResult = $this->runner->run($fullSuiteCommand, $this->paths->root());
        $actionsTaken = ['Ran quality gate command: ' . $this->commandString($fullSuiteCommand)];
        $fullSuite = [
            'ran' => true,
            'passed' => (bool) $fullSuiteResult['ok'],
            'command' => $fullSuiteCommand,
            'command_string' => $this->commandString($fullSuiteCommand),
            'exit_code' => (int) $fullSuiteResult['exit_code'],
        ];

        if (!$fullSuiteResult['ok']) {
            return $this->failedResult(
                fullSuite: $fullSuite,
                coverage: $this->notRunCoverage($coverageCommand, $threshold),
                threshold: $threshold,
                actionsTaken: $actionsTaken,
                changedSurface: $this->notComputedChangedSurface(
                    $threshold,
                    'not_run',
                    'Changed-surface coverage was not computed because the full PHPUnit suite failed.',
                ),
                issue: [
                    'code' => 'IMPLEMENTATION_QUALITY_GATE_FULL_SUITE_FAILED',
                    'message' => 'The full PHPUnit suite failed, so implementation completion cannot be reported as final.',
                    'command' => $fullSuiteCommand,
                    'exit_code' => (int) $fullSuiteResult['exit_code'],
                ],
                requiredActions: [
                    'Run `php vendor/bin/phpunit` successfully before treating implementation as complete.',
                ],
            );
        }

        $coverageResult = $this->runner->run($coverageCommand, $this->paths->root());
        $actionsTaken[] = 'Ran quality gate command: ' . $this->commandString($coverageCommand);
        $coverageSummary = $this->coverageVerifier()->summarize(self::COVERAGE_CLOVER_PATH);
        $globalLineCoverage = $coverageSummary['line_coverage_percent'] ?? null;
        $coverage = [
            'ran' => true,
            'passed' => (bool) $coverageResult['ok'],
            'command' => $coverageCommand,
            'command_string' => $this->commandString($coverageCommand),
            'exit_code' => (int) $coverageResult['exit_code'],
            'global_line_coverage' => $globalLineCoverage,
            'covered_lines' => $coverageSummary['covered_lines'] ?? null,
            'total_lines' => $coverageSummary['total_lines'] ?? null,
            'threshold' => $threshold,
            'meets_threshold' => $globalLineCoverage === null ? null : $globalLineCoverage >= $threshold,
        ];

        if (!$coverageResult['ok']) {
            $this->cleanupCloverPath($cloverPath);

            return $this->failedResult(
                fullSuite: $fullSuite,
                coverage: $coverage,
                threshold: $threshold,
                actionsTaken: $actionsTaken,
                changedSurface: $this->notComputedChangedSurface(
                    $threshold,
                    'not_run',
                    'Changed-surface coverage was not computed because the required PHPUnit coverage run failed.',
                ),
                issue: [
                    'code' => 'IMPLEMENTATION_QUALITY_GATE_COVERAGE_FAILED',
                    'message' => 'The required PHPUnit coverage run failed, so implementation completion cannot be reported as final.',
                    'command' => $coverageCommand,
                    'exit_code' => (int) $coverageResult['exit_code'],
                ],
                requiredActions: [
                    'Run `XDEBUG_MODE=coverage php vendor/bin/phpunit --coverage-clover build/coverage/clover.xml` successfully before treating implementation as complete.',
                ],
            );
        }

        if ($globalLineCoverage === null) {
            $this->cleanupCloverPath($cloverPath);

            return $this->failedResult(
                fullSuite: $fullSuite,
                coverage: $coverage,
                threshold: $threshold,
                actionsTaken: $actionsTaken,
                changedSurface: $this->notComputedChangedSurface(
                    $threshold,
                    'not_run',
                    'Changed-surface coverage was not computed because the global coverage summary was unparseable.',
                ),
                issue: [
                    'code' => 'IMPLEMENTATION_QUALITY_GATE_COVERAGE_UNPARSEABLE',
                    'message' => 'The required PHPUnit coverage run completed, but global line coverage could not be parsed deterministically.',
                    'command' => $coverageCommand,
                    'exit_code' => (int) $coverageResult['exit_code'],
                ],
                requiredActions: [
                    'Ensure `XDEBUG_MODE=coverage php vendor/bin/phpunit --coverage-clover build/coverage/clover.xml` emits a readable Clover report with deterministic statement metrics before treating implementation as complete.',
                ],
            );
        }

        $changedSurface = $this->verifyChangedSurface($threshold, $cloverPath, $changedFiles);
        $this->cleanupCloverPath($cloverPath);

        $issues = [];
        $requiredActions = [];

        if ($globalLineCoverage < $threshold) {
            $issues[] = [
                'code' => 'IMPLEMENTATION_QUALITY_GATE_GLOBAL_COVERAGE_BELOW_THRESHOLD',
                'message' => sprintf(
                    'Global line coverage %.2f%% is below the required %.2f%% threshold.',
                    $globalLineCoverage,
                    $threshold,
                ),
                'global_line_coverage' => $globalLineCoverage,
                'threshold' => $threshold,
            ];
            $requiredActions[] = sprintf(
                'Raise global line coverage to at least %.2f%% before treating implementation as complete.',
                $threshold,
            );
        }

        $issues = array_values(array_merge($issues, $changedSurface['issues']));
        $requiredActions = array_values(array_merge($requiredActions, $changedSurface['required_actions']));

        if ($issues !== []) {
            return [
                'status' => 'failed',
                'passed' => false,
                'enforcement_mode' => 'global_and_changed_surface',
                'required_threshold' => $threshold,
                'full_suite' => $fullSuite,
                'coverage' => $coverage,
                'changed_surface' => $changedSurface['report'],
                'issues' => $issues,
                'required_actions' => array_values(array_unique($requiredActions)),
                'actions_taken' => $actionsTaken,
            ];
        }

        return [
            'status' => 'passed',
            'passed' => true,
            'enforcement_mode' => 'global_and_changed_surface',
            'required_threshold' => $threshold,
            'full_suite' => $fullSuite,
            'coverage' => $coverage,
            'changed_surface' => $changedSurface['report'],
            'issues' => [],
            'required_actions' => [],
            'actions_taken' => $actionsTaken,
        ];
    }

    /**
     * @param array<string,mixed> $issue
     * @param list<string> $requiredActions
     * @param array<string,mixed> $changedSurface
     * @param list<string> $actionsTaken
     * @return array<string,mixed>
     */
    private function failedResult(
        array $fullSuite,
        array $coverage,
        float $threshold,
        array $actionsTaken,
        array $changedSurface,
        array $issue,
        array $requiredActions,
    ): array {
        return [
            'status' => 'failed',
            'passed' => false,
            'enforcement_mode' => 'global_and_changed_surface',
            'required_threshold' => $threshold,
            'full_suite' => $fullSuite,
            'coverage' => $coverage,
            'changed_surface' => $changedSurface,
            'issues' => [$issue],
            'required_actions' => array_values($requiredActions),
            'actions_taken' => array_values($actionsTaken),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function notRunCoverage(array $command, float $threshold): array
    {
        return [
            'ran' => false,
            'passed' => false,
            'command' => $command,
            'command_string' => $this->commandString($command),
            'exit_code' => null,
            'global_line_coverage' => null,
            'threshold' => $threshold,
            'meets_threshold' => null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function notComputedChangedSurface(float $threshold, string $status, string $message): array
    {
        return [
            'supported' => true,
            'status' => $status,
            'threshold' => $threshold,
            'coverage' => null,
            'passed' => null,
            'changed_files' => [],
            'examined_files' => [],
            'file_coverages' => [],
            'under_covered' => [],
            'message' => $message,
        ];
    }

    /**
     * @param list<string> $command
     */
    private function commandString(array $command): string
    {
        return implode(' ', $command);
    }

    /**
     * @param list<string>|null $preferredChangedFiles
     * @return array{
     *     report:array<string,mixed>,
     *     issues:list<array<string,mixed>>,
     *     required_actions:list<string>
     * }
     */
    private function verifyChangedSurface(float $threshold, string $cloverPath, ?array $preferredChangedFiles): array
    {
        [$changedFiles, $resolutionError] = $this->resolveChangedFiles($preferredChangedFiles);
        if ($resolutionError !== null) {
            return [
                'report' => [
                    'supported' => true,
                    'status' => 'unresolved',
                    'threshold' => $threshold,
                    'coverage' => null,
                    'passed' => false,
                    'changed_files' => [],
                    'examined_files' => [],
                    'file_coverages' => [],
                    'under_covered' => [],
                    'message' => (string) $resolutionError['message'],
                ],
                'issues' => [$resolutionError],
                'required_actions' => [
                    'Provide a deterministic changed-file set or run implementation from a repository state where changed files can be derived safely.',
                ],
            ];
        }

        $enforcedFiles = $this->enforcedChangedSurfaceFiles($changedFiles);
        if ($enforcedFiles === []) {
            return [
                'report' => [
                    'supported' => true,
                    'status' => 'no_enforced_files',
                    'threshold' => $threshold,
                    'coverage' => null,
                    'passed' => true,
                    'changed_files' => $changedFiles,
                    'examined_files' => [],
                    'file_coverages' => [],
                    'under_covered' => [],
                    'message' => 'No changed PHP source files under enforcement were detected for the current implementation run.',
                ],
                'issues' => [],
                'required_actions' => [],
            ];
        }

        $coverageByFile = $this->parseCloverCoverage($cloverPath);
        if ($coverageByFile === null) {
            return [
                'report' => [
                    'supported' => true,
                    'status' => 'unresolved',
                    'threshold' => $threshold,
                    'coverage' => null,
                    'passed' => false,
                    'changed_files' => $changedFiles,
                    'examined_files' => $enforcedFiles,
                    'file_coverages' => [],
                    'under_covered' => [],
                    'message' => 'Changed-surface coverage could not be computed because the Clover coverage report was missing or unreadable.',
                ],
                'issues' => [[
                    'code' => 'IMPLEMENTATION_QUALITY_GATE_CHANGED_SURFACE_ATTRIBUTION_FAILED',
                    'message' => 'Changed-surface coverage could not be computed because the Clover coverage report was missing or unreadable.',
                    'changed_files' => $enforcedFiles,
                ]],
                'required_actions' => [
                    'Ensure the canonical PHPUnit coverage run produces a readable Clover coverage report before treating implementation as complete.',
                ],
            ];
        }

        $fileCoverages = [];
        $underCovered = [];
        $missingCoverage = [];
        $totalStatements = 0;
        $totalCoveredStatements = 0;

        foreach ($enforcedFiles as $path) {
            $metrics = $coverageByFile[$path] ?? null;
            if (!is_array($metrics)) {
                $missingCoverage[] = $path;
                $fileCoverages[] = [
                    'path' => $path,
                    'line_coverage' => null,
                    'covered_lines' => null,
                    'total_lines' => null,
                    'meets_threshold' => null,
                    'attributed' => false,
                ];
                continue;
            }

            $statements = (int) ($metrics['statements'] ?? 0);
            $coveredStatements = (int) ($metrics['covered_statements'] ?? 0);
            $lineCoverage = $statements === 0
                ? 100.0
                : round(($coveredStatements / $statements) * 100, 2);

            $totalStatements += $statements;
            $totalCoveredStatements += $coveredStatements;

            $row = [
                'path' => $path,
                'line_coverage' => $lineCoverage,
                'covered_lines' => $coveredStatements,
                'total_lines' => $statements,
                'meets_threshold' => $lineCoverage >= $threshold,
                'attributed' => true,
            ];
            $fileCoverages[] = $row;

            if ($lineCoverage < $threshold) {
                $underCovered[] = [
                    'path' => $path,
                    'line_coverage' => $lineCoverage,
                    'covered_lines' => $coveredStatements,
                    'total_lines' => $statements,
                    'threshold' => $threshold,
                ];
            }
        }

        if ($missingCoverage !== []) {
            return [
                'report' => [
                    'supported' => true,
                    'status' => 'unresolved',
                    'threshold' => $threshold,
                    'coverage' => null,
                    'passed' => false,
                    'changed_files' => $changedFiles,
                    'examined_files' => $enforcedFiles,
                    'file_coverages' => $fileCoverages,
                    'under_covered' => [],
                    'message' => 'Changed-surface coverage could not be attributed to every enforced changed file.',
                ],
                'issues' => [[
                    'code' => 'IMPLEMENTATION_QUALITY_GATE_CHANGED_SURFACE_ATTRIBUTION_FAILED',
                    'message' => 'Changed-surface coverage could not be attributed to every enforced changed file.',
                    'changed_files' => $enforcedFiles,
                    'missing_coverage_files' => $missingCoverage,
                ]],
                'required_actions' => [
                    'Ensure every enforced changed PHP source file is included in the canonical coverage run before treating implementation as complete.',
                ],
            ];
        }

        $aggregateCoverage = $totalStatements === 0
            ? 100.0
            : round(($totalCoveredStatements / $totalStatements) * 100, 2);

        if ($underCovered !== []) {
            return [
                'report' => [
                    'supported' => true,
                    'status' => 'failed',
                    'threshold' => $threshold,
                    'coverage' => $aggregateCoverage,
                    'passed' => false,
                    'changed_files' => $changedFiles,
                    'examined_files' => $enforcedFiles,
                    'file_coverages' => $fileCoverages,
                    'under_covered' => $underCovered,
                    'message' => 'One or more changed PHP source files are below the required changed-surface coverage threshold.',
                ],
                'issues' => [[
                    'code' => 'IMPLEMENTATION_QUALITY_GATE_CHANGED_SURFACE_BELOW_THRESHOLD',
                    'message' => 'One or more changed PHP source files are below the required changed-surface coverage threshold.',
                    'threshold' => $threshold,
                    'under_covered' => $underCovered,
                ]],
                'required_actions' => [
                    sprintf(
                        'Raise changed-surface line coverage to at least %.2f%% for every enforced changed PHP source file before treating implementation as complete.',
                        $threshold,
                    ),
                ],
            ];
        }

        return [
            'report' => [
                'supported' => true,
                'status' => 'passed',
                'threshold' => $threshold,
                'coverage' => $aggregateCoverage,
                'passed' => true,
                'changed_files' => $changedFiles,
                'examined_files' => $enforcedFiles,
                'file_coverages' => $fileCoverages,
                'under_covered' => [],
                'message' => 'Changed-surface coverage meets the required threshold for all enforced changed PHP source files.',
            ],
            'issues' => [],
            'required_actions' => [],
        ];
    }

    /**
     * @param list<string>|null $preferredChangedFiles
     * @return array{0:list<string>,1:array<string,mixed>|null}
     */
    private function resolveChangedFiles(?array $preferredChangedFiles): array
    {
        if (is_array($preferredChangedFiles)) {
            return [$this->normalizePaths($preferredChangedFiles), null];
        }

        $state = (new GitRepositoryInspector($this->paths->root()))->inspect();
        if (($state['available'] ?? false) !== true) {
            return [[], [
                'code' => 'IMPLEMENTATION_QUALITY_GATE_CHANGED_SURFACE_UNDETERMINED',
                'message' => 'Changed-surface coverage could not be computed because the repository could not determine changed files deterministically.',
            ]];
        }

        return [$this->normalizePaths((array) ($state['safety_relevant']['changed_files'] ?? [])), null];
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function enforcedChangedSurfaceFiles(array $paths): array
    {
        return array_values(array_filter(
            $this->normalizePaths($paths),
            function (string $path): bool {
                if (!str_ends_with($path, '.php')) {
                    return false;
                }

                foreach ([
                    '.foundry/',
                    'docs/',
                    'vendor/',
                    'storage/',
                    'app/generated/',
                    'stubs/',
                ] as $prefix) {
                    if (str_starts_with($path, $prefix)) {
                        return false;
                    }
                }

                if (preg_match('#(^|/)tests/#', $path) === 1) {
                    return false;
                }

                return true;
            },
        ));
    }

    /**
     * @return array<string,array{statements:int,covered_statements:int}>|null
     */
    private function parseCloverCoverage(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $xml = file_get_contents($path);
        if (!is_string($xml) || trim($xml) === '') {
            return null;
        }

        if (preg_match_all('/<file\s+name="([^"]+)"[^>]*>(.*?)<\/file>/s', $xml, $fileMatches, PREG_SET_ORDER) === false) {
            return null;
        }

        $coverageByFile = [];

        foreach ($fileMatches as $match) {
            $filePath = $this->normalizeCoveragePath((string) $match[1]);
            if ($filePath === null) {
                continue;
            }

            if (preg_match('/<metrics\b([^>]*)\/>/s', (string) $match[2], $metricsMatch) !== 1) {
                continue;
            }

            $attributes = $this->parseXmlAttributes((string) $metricsMatch[1]);
            if (!isset($attributes['statements'], $attributes['coveredstatements'])) {
                continue;
            }

            $coverageByFile[$filePath] = [
                'statements' => (int) $attributes['statements'],
                'covered_statements' => (int) $attributes['coveredstatements'],
            ];
        }

        ksort($coverageByFile);

        return $coverageByFile;
    }

    /**
     * @return array<string,string>
     */
    private function parseXmlAttributes(string $attributes): array
    {
        if (preg_match_all('/([A-Za-z0-9_:-]+)="([^"]*)"/', $attributes, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        $result = [];

        foreach ($matches as $match) {
            $result[(string) $match[1]] = (string) $match[2];
        }

        return $result;
    }

    private function normalizeCoveragePath(string $path): ?string
    {
        $normalized = str_replace('\\', '/', trim($path));
        if ($normalized === '') {
            return null;
        }

        $roots = array_values(array_unique(array_filter([
            rtrim(str_replace('\\', '/', $this->paths->root()), '/'),
            ($resolvedRoot = realpath($this->paths->root())) !== false
                ? rtrim(str_replace('\\', '/', $resolvedRoot), '/')
                : null,
        ])));

        foreach ($roots as $root) {
            if (!str_starts_with($normalized, $root . '/')) {
                continue;
            }

            $normalized = substr($normalized, strlen($root) + 1);
            break;
        }

        return trim($normalized, '/');
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function normalizePaths(array $paths): array
    {
        $normalized = array_values(array_unique(array_filter(array_map(
            static fn(string $path): string => trim(str_replace('\\', '/', $path)),
            $paths,
        ), static fn(string $path): bool => $path !== '')));
        sort($normalized);

        return $normalized;
    }

    private function prepareCloverPath(string $path): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        if (is_file($path)) {
            unlink($path);
        }
    }

    private function cleanupCloverPath(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function coverageVerifier(): CloverCoverageVerifier
    {
        return $this->coverageVerifier ?? new CloverCoverageVerifier($this->paths);
    }
}
