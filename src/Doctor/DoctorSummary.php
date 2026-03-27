<?php
declare(strict_types=1);

namespace Foundry\Doctor;

final class DoctorSummary
{
    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array{error:int,warning:int,info:int,total:int}
     */
    public static function fromRows(array $rows): array
    {
        $summary = [
            'error' => 0,
            'warning' => 0,
            'info' => 0,
            'total' => 0,
        ];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $severity = strtolower(trim((string) ($row['severity'] ?? '')));
            if (!isset($summary[$severity])) {
                continue;
            }

            $summary[$severity]++;
            $summary['total']++;
        }

        return $summary;
    }

    /**
     * @param array{error:int,warning:int,info:int,total:int} $summary
     */
    public static function status(array $summary): string
    {
        if ((int) ($summary['error'] ?? 0) > 0) {
            return 'error';
        }

        if ((int) ($summary['warning'] ?? 0) > 0) {
            return 'warning';
        }

        return 'passed';
    }

    /**
     * @param array{error:int,warning:int,info:int,total:int} $summary
     */
    public static function risk(array $summary): string
    {
        if ((int) ($summary['error'] ?? 0) > 0) {
            return 'high';
        }

        if ((int) ($summary['warning'] ?? 0) > 0) {
            return 'medium';
        }

        return 'low';
    }
}
