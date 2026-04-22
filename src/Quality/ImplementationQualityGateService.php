<?php

declare(strict_types=1);

namespace Foundry\Quality;

use Foundry\Support\Paths;
use Foundry\Tooling\ProcessRunner;

final class ImplementationQualityGateService
{
    private const float GLOBAL_LINE_THRESHOLD = 90.0;

    public function __construct(
        private readonly Paths $paths,
        private readonly ProcessRunner $runner = new ProcessRunner(),
    ) {}

    /**
     * @return array{
     *     status:string,
     *     passed:bool,
     *     enforcement_mode:string,
     *     required_threshold:float,
     *     full_suite:array{
     *         ran:bool,
     *         passed:bool,
     *         command:list<string>,
     *         command_string:string,
     *         exit_code:int|null
     *     },
     *     coverage:array{
     *         ran:bool,
     *         passed:bool,
     *         command:list<string>,
     *         command_string:string,
     *         exit_code:int|null,
     *         global_line_coverage:float|null,
     *         threshold:float,
     *         meets_threshold:bool|null
     *     },
     *     changed_surface:array{
     *         supported:bool,
     *         status:string,
     *         threshold:float,
     *         coverage:float|null,
     *         passed:bool|null,
     *         under_covered:list<array<string,mixed>>,
     *         message:string
     *     },
     *     issues:list<array<string,mixed>>,
     *     required_actions:list<string>,
     *     actions_taken:list<string>
     * }
     */
    public function verify(): array
    {
        $threshold = self::GLOBAL_LINE_THRESHOLD;
        $fullSuiteCommand = ['php', 'vendor/bin/phpunit'];
        $coverageCommand = ['php', '-d', 'xdebug.mode=coverage', 'vendor/bin/phpunit', '--coverage-text'];

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
        $globalLineCoverage = $this->parseGlobalLineCoverage(
            trim(($coverageResult['stdout'] !== '' ? $coverageResult['stdout'] : $coverageResult['stderr']) ?? ''),
        );
        $coverage = [
            'ran' => true,
            'passed' => (bool) $coverageResult['ok'],
            'command' => $coverageCommand,
            'command_string' => $this->commandString($coverageCommand),
            'exit_code' => (int) $coverageResult['exit_code'],
            'global_line_coverage' => $globalLineCoverage,
            'threshold' => $threshold,
            'meets_threshold' => $globalLineCoverage === null ? null : $globalLineCoverage >= $threshold,
        ];

        if (!$coverageResult['ok']) {
            return $this->failedResult(
                fullSuite: $fullSuite,
                coverage: $coverage,
                threshold: $threshold,
                actionsTaken: $actionsTaken,
                issue: [
                    'code' => 'IMPLEMENTATION_QUALITY_GATE_COVERAGE_FAILED',
                    'message' => 'The required PHPUnit coverage run failed, so implementation completion cannot be reported as final.',
                    'command' => $coverageCommand,
                    'exit_code' => (int) $coverageResult['exit_code'],
                ],
                requiredActions: [
                    'Run `php -d xdebug.mode=coverage vendor/bin/phpunit --coverage-text` successfully before treating implementation as complete.',
                ],
            );
        }

        if ($globalLineCoverage === null) {
            return $this->failedResult(
                fullSuite: $fullSuite,
                coverage: $coverage,
                threshold: $threshold,
                actionsTaken: $actionsTaken,
                issue: [
                    'code' => 'IMPLEMENTATION_QUALITY_GATE_COVERAGE_UNPARSEABLE',
                    'message' => 'The required PHPUnit coverage run completed, but global line coverage could not be parsed deterministically.',
                    'command' => $coverageCommand,
                    'exit_code' => (int) $coverageResult['exit_code'],
                ],
                requiredActions: [
                    'Ensure `php -d xdebug.mode=coverage vendor/bin/phpunit --coverage-text` emits a parseable global line-coverage summary before treating implementation as complete.',
                ],
            );
        }

        if ($globalLineCoverage < $threshold) {
            return $this->failedResult(
                fullSuite: $fullSuite,
                coverage: $coverage,
                threshold: $threshold,
                actionsTaken: $actionsTaken,
                issue: [
                    'code' => 'IMPLEMENTATION_QUALITY_GATE_GLOBAL_COVERAGE_BELOW_THRESHOLD',
                    'message' => sprintf(
                        'Global line coverage %.2f%% is below the required %.2f%% threshold.',
                        $globalLineCoverage,
                        $threshold,
                    ),
                    'global_line_coverage' => $globalLineCoverage,
                    'threshold' => $threshold,
                ],
                requiredActions: [
                    sprintf(
                        'Raise global line coverage to at least %.2f%% before treating implementation as complete.',
                        $threshold,
                    ),
                ],
            );
        }

        return [
            'status' => 'passed',
            'passed' => true,
            'enforcement_mode' => 'global_only_pending_changed_surface',
            'required_threshold' => $threshold,
            'full_suite' => $fullSuite,
            'coverage' => $coverage,
            'changed_surface' => $this->unsupportedChangedSurface($threshold),
            'issues' => [],
            'required_actions' => [],
            'actions_taken' => $actionsTaken,
        ];
    }

    /**
     * @param array{ran:bool,passed:bool,command:list<string>,command_string:string,exit_code:int|null} $fullSuite
     * @param array{ran:bool,passed:bool,command:list<string>,command_string:string,exit_code:int|null,global_line_coverage:float|null,threshold:float,meets_threshold:bool|null} $coverage
     * @param list<string> $actionsTaken
     * @param array<string,mixed> $issue
     * @param list<string> $requiredActions
     * @return array{
     *     status:string,
     *     passed:bool,
     *     enforcement_mode:string,
     *     required_threshold:float,
     *     full_suite:array{
     *         ran:bool,
     *         passed:bool,
     *         command:list<string>,
     *         command_string:string,
     *         exit_code:int|null
     *     },
     *     coverage:array{
     *         ran:bool,
     *         passed:bool,
     *         command:list<string>,
     *         command_string:string,
     *         exit_code:int|null,
     *         global_line_coverage:float|null,
     *         threshold:float,
     *         meets_threshold:bool|null
     *     },
     *     changed_surface:array{
     *         supported:bool,
     *         status:string,
     *         threshold:float,
     *         coverage:float|null,
     *         passed:bool|null,
     *         under_covered:list<array<string,mixed>>,
     *         message:string
     *     },
     *     issues:list<array<string,mixed>>,
     *     required_actions:list<string>,
     *     actions_taken:list<string>
     * }
     */
    private function failedResult(
        array $fullSuite,
        array $coverage,
        float $threshold,
        array $actionsTaken,
        array $issue,
        array $requiredActions,
    ): array {
        return [
            'status' => 'failed',
            'passed' => false,
            'enforcement_mode' => 'global_only_pending_changed_surface',
            'required_threshold' => $threshold,
            'full_suite' => $fullSuite,
            'coverage' => $coverage,
            'changed_surface' => $this->unsupportedChangedSurface($threshold),
            'issues' => [$issue],
            'required_actions' => array_values($requiredActions),
            'actions_taken' => array_values($actionsTaken),
        ];
    }

    /**
     * @return array{
     *     ran:bool,
     *     passed:bool,
     *     command:list<string>,
     *     command_string:string,
     *     exit_code:int|null,
     *     global_line_coverage:float|null,
     *     threshold:float,
     *     meets_threshold:bool|null
     * }
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
     * @return array{
     *     supported:bool,
     *     status:string,
     *     threshold:float,
     *     coverage:float|null,
     *     passed:bool|null,
     *     under_covered:list<array<string,mixed>>,
     *     message:string
     * }
     */
    private function unsupportedChangedSurface(float $threshold): array
    {
        return [
            'supported' => false,
            'status' => 'not_supported',
            'threshold' => $threshold,
            'coverage' => null,
            'passed' => null,
            'under_covered' => [],
            'message' => 'Changed-surface coverage is not yet deterministically computed by the repository quality gate.',
        ];
    }

    private function parseGlobalLineCoverage(string $output): ?float
    {
        if ($output === '') {
            return null;
        }

        if (preg_match('/^\s*Lines:\s+([0-9]+(?:\.[0-9]+)?)%/mi', $output, $matches) !== 1) {
            return null;
        }

        return round((float) $matches[1], 2);
    }

    /**
     * @param list<string> $command
     */
    private function commandString(array $command): string
    {
        return implode(' ', $command);
    }
}
