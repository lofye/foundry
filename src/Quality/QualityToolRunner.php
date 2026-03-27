<?php

declare(strict_types=1);

namespace Foundry\Quality;

use Foundry\Support\Json;
use Foundry\Support\Paths;
use Foundry\Tooling\ProcessRunner;

final class QualityToolRunner
{
    public function __construct(
        private readonly Paths $paths,
        private readonly ProcessRunner $runner = new ProcessRunner(),
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function runStaticAnalysis(): array
    {
        $executable = $this->resolveExecutable('phpstan');
        if ($executable === null) {
            return $this->missingToolResult(
                tool: 'phpstan',
                code: 'FDY9201_STATIC_ANALYSIS_TOOL_MISSING',
                message: 'PHPStan is not installed in this project.',
                suggestedFix: 'Install PHPStan in require-dev and rerun `composer analyse`.',
            );
        }

        $result = $this->runner->run([
            $executable,
            'analyse',
            '--no-progress',
            '--error-format=json',
            '--memory-limit=1G',
            '--debug',
        ], $this->paths->root());

        $output = trim($result['stdout'] !== '' ? $result['stdout'] : $result['stderr']);
        $issues = [];
        $internalErrors = 0;

        if ($output !== '') {
            $jsonPayload = $this->extractTrailingJson($output);
            try {
                $decoded = Json::decodeAssoc($jsonPayload ?? $output);
                $totals = is_array($decoded['totals'] ?? null) ? $decoded['totals'] : [];
                $files = is_array($decoded['files'] ?? null) ? $decoded['files'] : [];
                $internalErrors = (int) ($totals['errors'] ?? 0);

                foreach ($files as $path => $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    foreach ((array) ($row['messages'] ?? []) as $message) {
                        if (!is_array($message)) {
                            continue;
                        }

                        $issues[] = [
                            'path' => (string) $path,
                            'line' => isset($message['line']) ? (int) $message['line'] : null,
                            'message' => (string) ($message['message'] ?? 'Static analysis issue detected.'),
                            'identifier' => (string) ($message['identifier'] ?? ''),
                            'tip' => (string) ($message['tip'] ?? ''),
                        ];
                    }
                }
            } catch (\Throwable) {
                if ($result['ok']) {
                    return [
                        'tool' => 'phpstan',
                        'available' => true,
                        'ok' => true,
                        'status' => 'passed',
                        'exit_code' => (int) $result['exit_code'],
                        'command' => $result['command'],
                        'summary' => [
                            'internal_errors' => 0,
                            'total' => 0,
                        ],
                        'issues' => [],
                        'diagnostics' => [],
                    ];
                }

                $issues[] = [
                    'path' => null,
                    'line' => null,
                    'message' => $output,
                    'identifier' => '',
                    'tip' => '',
                ];
            }
        }

        if ($issues === [] && !$result['ok'] && $output !== '') {
            $issues[] = [
                'path' => null,
                'line' => null,
                'message' => $output,
                'identifier' => '',
                'tip' => '',
            ];
        }

        $diagnostics = array_map(
            static fn(array $issue): array => [
                'id' => 'static:' . substr(hash('sha256', Json::encode($issue)), 0, 12),
                'code' => 'FDY9202_STATIC_ANALYSIS_VIOLATION',
                'severity' => 'error',
                'category' => 'quality',
                'message' => (string) ($issue['message'] ?? 'Static analysis issue detected.'),
                'node_id' => null,
                'source_path' => $issue['path'],
                'source_line' => $issue['line'],
                'related_nodes' => [],
                'suggested_fix' => 'Resolve the PHPStan finding and rerun `composer analyse`.',
                'pass' => 'doctor.static_analysis',
                'why_it_matters' => 'Static analysis catches type and contract drift before runtime failures occur.',
                'details' => [
                    'identifier' => (string) ($issue['identifier'] ?? ''),
                    'tip' => (string) ($issue['tip'] ?? ''),
                ],
            ],
            $issues,
        );

        if ($internalErrors > 0 && $issues === []) {
            $diagnostics[] = [
                'id' => 'static:internal',
                'code' => 'FDY9203_STATIC_ANALYSIS_INTERNAL_ERROR',
                'severity' => 'error',
                'category' => 'quality',
                'message' => 'PHPStan reported an internal analysis error.',
                'node_id' => null,
                'source_path' => null,
                'source_line' => null,
                'related_nodes' => [],
                'suggested_fix' => 'Inspect the PHPStan output and configuration, then rerun `composer analyse`.',
                'pass' => 'doctor.static_analysis',
                'why_it_matters' => 'An internal analyzer failure prevents Foundry from trusting static enforcement results.',
                'details' => ['internal_errors' => $internalErrors],
            ];
        }

        return [
            'tool' => 'phpstan',
            'available' => true,
            'ok' => $result['ok'] && $diagnostics === [],
            'status' => ($result['ok'] && $diagnostics === []) ? 'passed' : 'error',
            'exit_code' => (int) $result['exit_code'],
            'command' => $result['command'],
            'summary' => [
                'internal_errors' => $internalErrors,
                'total' => count($diagnostics),
            ],
            'issues' => $issues,
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function runStyleCheck(): array
    {
        $executable = $this->resolveExecutable('pint');
        if ($executable === null) {
            return $this->missingToolResult(
                tool: 'pint',
                code: 'FDY9204_STYLE_TOOL_MISSING',
                message: 'Laravel Pint is not installed in this project.',
                suggestedFix: 'Install Pint in require-dev and rerun `composer lint`.',
            );
        }

        $result = $this->runner->run([
            $executable,
            '--test',
            '--format=json',
        ], $this->paths->root());

        $output = trim($result['stdout'] !== '' ? $result['stdout'] : $result['stderr']);
        $issues = [];

        if ($output !== '') {
            try {
                $decoded = Json::decodeAssoc($output);
                foreach ($this->extractStyleFiles($decoded) as $filePath) {
                    $issues[] = [
                        'path' => $filePath,
                        'message' => 'Style violations detected by Pint.',
                    ];
                }
            } catch (\Throwable) {
                foreach (preg_split('/\R+/', $output) ?: [] as $line) {
                    $trimmed = trim((string) $line);
                    if ($trimmed === '') {
                        continue;
                    }

                    $issues[] = [
                        'path' => null,
                        'message' => $trimmed,
                    ];
                }
            }
        }

        if ($issues === [] && !$result['ok']) {
            $issues[] = [
                'path' => null,
                'message' => $output !== '' ? $output : 'Style violations detected by Pint.',
            ];
        }

        $diagnostics = array_map(
            static fn(array $issue): array => [
                'id' => 'style:' . substr(hash('sha256', Json::encode($issue)), 0, 12),
                'code' => 'FDY9205_STYLE_VIOLATION',
                'severity' => 'warning',
                'category' => 'quality',
                'message' => (string) ($issue['message'] ?? 'Style violation detected.'),
                'node_id' => null,
                'source_path' => $issue['path'],
                'source_line' => null,
                'related_nodes' => [],
                'suggested_fix' => 'Run `composer lint:fix` to normalize formatting.',
                'pass' => 'doctor.style',
                'why_it_matters' => 'Consistent formatting keeps generated diffs and LLM editing loops predictable.',
                'details' => [],
            ],
            $issues,
        );

        return [
            'tool' => 'pint',
            'available' => true,
            'ok' => $result['ok'] && $diagnostics === [],
            'status' => ($result['ok'] && $diagnostics === []) ? 'passed' : 'warning',
            'exit_code' => (int) $result['exit_code'],
            'command' => $result['command'],
            'summary' => [
                'total' => count($diagnostics),
            ],
            'issues' => $issues,
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function runTests(): array
    {
        $executable = $this->resolveExecutable('phpunit');
        if ($executable === null) {
            return [
                'available' => false,
                'ok' => false,
                'status' => 'missing',
                'exit_code' => null,
                'summary' => [
                    'executed' => false,
                    'passed' => false,
                ],
            ];
        }

        $result = $this->runner->run([$executable, '--no-output'], $this->paths->root());

        return [
            'available' => true,
            'ok' => $result['ok'],
            'status' => $result['ok'] ? 'passed' : 'error',
            'exit_code' => (int) $result['exit_code'],
            'summary' => [
                'executed' => true,
                'passed' => $result['ok'],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function extractStyleFiles(array $decoded): array
    {
        $paths = [];

        foreach ((array) ($decoded['files'] ?? []) as $key => $row) {
            if (is_string($row) && $row !== '') {
                $paths[] = $row;
                continue;
            }

            if (is_string($key) && $key !== '') {
                $paths[] = $key;
                continue;
            }

            if (is_array($row)) {
                $name = trim((string) ($row['name'] ?? $row['file'] ?? ''));
                if ($name !== '') {
                    $paths[] = $name;
                }
            }
        }

        sort($paths);

        return array_values(array_unique($paths));
    }

    /**
     * @return array<string,mixed>
     */
    private function missingToolResult(string $tool, string $code, string $message, string $suggestedFix): array
    {
        return [
            'tool' => $tool,
            'available' => false,
            'ok' => false,
            'status' => 'error',
            'exit_code' => null,
            'command' => [],
            'summary' => [
                'total' => 1,
            ],
            'issues' => [],
            'diagnostics' => [[
                'id' => $tool . ':missing',
                'code' => $code,
                'severity' => 'error',
                'category' => 'quality',
                'message' => $message,
                'node_id' => null,
                'source_path' => null,
                'source_line' => null,
                'related_nodes' => [],
                'suggested_fix' => $suggestedFix,
                'pass' => 'doctor.' . $tool,
                'why_it_matters' => 'Missing quality tooling leaves Foundry without deterministic enforcement signals.',
                'details' => [],
            ]],
        ];
    }

    private function resolveExecutable(string $tool): ?string
    {
        $candidates = [
            $this->paths->join('vendor/bin/' . $tool),
        ];

        if ($this->paths->root() !== $this->paths->frameworkRoot()) {
            $candidates[] = $this->paths->frameworkJoin('vendor/bin/' . $tool);
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function extractTrailingJson(string $output): ?string
    {
        $lines = preg_split('/\R+/', trim($output)) ?: [];
        $lines = array_reverse(array_values(array_filter(
            array_map('trim', $lines),
            static fn(string $line): bool => $line !== '',
        )));

        foreach ($lines as $line) {
            if (!str_starts_with($line, '{')) {
                continue;
            }

            return $line;
        }

        return null;
    }
}
