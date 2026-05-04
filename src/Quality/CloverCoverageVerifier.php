<?php

declare(strict_types=1);

namespace Foundry\Quality;

use Foundry\Support\Paths;

final class CloverCoverageVerifier
{
    public function __construct(
        private readonly Paths $paths,
    ) {}

    /**
     * @return array{
     *     status:string,
     *     min_required:float,
     *     line_coverage_percent:float,
     *     covered_lines:int,
     *     total_lines:int,
     *     clover_path:string
     * }
     */
    public function verify(string $cloverPath, float $minRequired): array
    {
        $normalizedPath = $this->normalizePath($cloverPath);
        $minimum = round($minRequired, 2);
        $summary = $this->summarize($normalizedPath);

        if ($summary === null) {
            return [
                'status' => 'fail',
                'min_required' => $minimum,
                'line_coverage_percent' => 0.0,
                'covered_lines' => 0,
                'total_lines' => 0,
                'clover_path' => $normalizedPath,
            ];
        }

        return [
            'status' => $summary['line_coverage_percent'] >= $minimum ? 'pass' : 'fail',
            'min_required' => $minimum,
            'line_coverage_percent' => $summary['line_coverage_percent'],
            'covered_lines' => $summary['covered_lines'],
            'total_lines' => $summary['total_lines'],
            'clover_path' => $normalizedPath,
        ];
    }

    /**
     * @return array{line_coverage_percent:float,covered_lines:int,total_lines:int}|null
     */
    public function summarize(string $cloverPath): ?array
    {
        $absolutePath = $this->absolutePath($cloverPath);
        if (!is_file($absolutePath)) {
            return null;
        }

        $xml = file_get_contents($absolutePath);
        if (!is_string($xml) || trim($xml) === '') {
            return null;
        }

        $totals = $this->aggregateFileMetrics($xml);
        if ($totals === null) {
            $totals = $this->aggregateGlobalMetrics($xml);
        }

        if ($totals === null) {
            return null;
        }

        $totalLines = $totals['total_lines'];
        $coveredLines = $totals['covered_lines'];
        $lineCoverage = $totalLines === 0
            ? 100.0
            : round(($coveredLines / $totalLines) * 100, 2);

        return [
            'line_coverage_percent' => $lineCoverage,
            'covered_lines' => $coveredLines,
            'total_lines' => $totalLines,
        ];
    }

    private function normalizePath(string $path): string
    {
        $normalized = str_replace('\\', '/', trim($path));
        if ($normalized === '') {
            return 'build/coverage/clover.xml';
        }

        return ltrim($normalized, './');
    }

    private function absolutePath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        if ($normalized !== '' && $normalized[0] === '/') {
            return $normalized;
        }

        return $this->paths->join($normalized);
    }

    /**
     * @return array{covered_lines:int,total_lines:int}|null
     */
    private function aggregateFileMetrics(string $xml): ?array
    {
        if (preg_match_all('/<file\s+name="[^"]*"[^>]*>(.*?)<\/file>/s', $xml, $fileMatches, \PREG_SET_ORDER) === false) {
            return null;
        }

        $totalLines = 0;
        $coveredLines = 0;
        $found = false;

        foreach ($fileMatches as $match) {
            if (preg_match('/<metrics\b([^>]*)\/>/s', (string) $match[1], $metricsMatch) !== 1) {
                continue;
            }

            $attributes = $this->parseXmlAttributes((string) $metricsMatch[1]);
            if (!isset($attributes['statements'], $attributes['coveredstatements'])) {
                continue;
            }

            $totalLines += (int) $attributes['statements'];
            $coveredLines += (int) $attributes['coveredstatements'];
            $found = true;
        }

        if (!$found) {
            return null;
        }

        return [
            'covered_lines' => $coveredLines,
            'total_lines' => $totalLines,
        ];
    }

    /**
     * @return array{covered_lines:int,total_lines:int}|null
     */
    private function aggregateGlobalMetrics(string $xml): ?array
    {
        if (preg_match_all('/<metrics\b([^>]*)\/>/s', $xml, $metricMatches, \PREG_SET_ORDER) === false) {
            return null;
        }

        $selected = null;

        foreach ($metricMatches as $match) {
            $attributes = $this->parseXmlAttributes((string) $match[1]);
            if (!isset($attributes['statements'], $attributes['coveredstatements'])) {
                continue;
            }

            $candidate = [
                'covered_lines' => (int) $attributes['coveredstatements'],
                'total_lines' => (int) $attributes['statements'],
                'has_files' => array_key_exists('files', $attributes),
            ];

            if ($selected === null) {
                $selected = $candidate;
                continue;
            }

            if ($candidate['has_files'] && !$selected['has_files']) {
                $selected = $candidate;
                continue;
            }

            if ($candidate['has_files'] === $selected['has_files'] && $candidate['total_lines'] > $selected['total_lines']) {
                $selected = $candidate;
            }
        }

        if ($selected === null) {
            return null;
        }

        return [
            'covered_lines' => (int) $selected['covered_lines'],
            'total_lines' => (int) $selected['total_lines'],
        ];
    }

    /**
     * @return array<string,string>
     */
    private function parseXmlAttributes(string $attributes): array
    {
        if (preg_match_all('/([A-Za-z0-9_:-]+)="([^"]*)"/', $attributes, $matches, \PREG_SET_ORDER) === false) {
            return [];
        }

        $result = [];
        foreach ($matches as $match) {
            $result[(string) $match[1]] = (string) $match[2];
        }

        return $result;
    }
}
