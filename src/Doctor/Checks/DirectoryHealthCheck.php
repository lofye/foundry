<?php
declare(strict_types=1);

namespace Foundry\Doctor\Checks;

use Foundry\Compiler\Diagnostics\DiagnosticBag;
use Foundry\Doctor\DoctorCheck;
use Foundry\Doctor\DoctorContext;
use Foundry\Doctor\DoctorSummary;

final class DirectoryHealthCheck implements DoctorCheck
{
    public function id(): string
    {
        return 'directory_health';
    }

    public function description(): string
    {
        return 'Ensures build, generated, log, and temp directories exist and are writable.';
    }

    public function check(DoctorContext $context, DiagnosticBag $diagnostics): array
    {
        $directories = [
            ['path' => 'app/.foundry/build', 'required' => true],
            ['path' => 'app/.foundry/build/graph', 'required' => true],
            ['path' => 'app/.foundry/build/projections', 'required' => true],
            ['path' => 'app/.foundry/build/manifests', 'required' => true],
            ['path' => 'app/.foundry/build/diagnostics', 'required' => true],
            ['path' => 'app/generated', 'required' => true],
            ['path' => 'database/migrations', 'required' => false],
            ['path' => 'storage/files', 'required' => false],
            ['path' => 'storage/logs', 'required' => false],
            ['path' => 'storage/tmp', 'required' => false],
        ];

        $rows = [];
        foreach ($directories as $row) {
            $relativePath = (string) $row['path'];
            $absolutePath = $context->paths->join($relativePath);
            $exists = is_dir($absolutePath);
            $writable = $exists && is_writable($absolutePath);
            $parent = dirname($absolutePath);
            $parentWritable = is_dir($parent) && is_writable($parent);

            $rows[] = [
                'path' => $relativePath,
                'exists' => $exists,
                'writable' => $writable,
                'parent_writable' => $parentWritable,
                'required' => (bool) ($row['required'] ?? false),
            ];

            if (!$exists) {
                $message = 'Required directory is missing: ' . $relativePath . '.';
                $severity = (bool) ($row['required'] ?? false) ? 'error' : 'warning';
                $code = !$parentWritable
                    ? 'FDY9108_DIRECTORY_PARENT_NOT_WRITABLE'
                    : 'FDY9106_DIRECTORY_MISSING';
                $message = !$parentWritable
                    ? 'Required directory is missing and its parent is not writable: ' . $relativePath . '.'
                    : $message;
                $suggestedFix = !$parentWritable
                    ? 'Repair parent-directory permissions so Foundry can create the missing directory.'
                    : 'Create the missing directory or restore the expected app scaffold.';
                $whyItMatters = (bool) ($row['required'] ?? false)
                    ? 'Foundry needs this directory to emit build artifacts or runtime projections during compile and execution.'
                    : 'Foundry expects this directory for logs or temporary runtime state during local execution.';

                if ($severity === 'error') {
                    $diagnostics->error(
                        code: $code,
                        category: 'filesystem',
                        message: $message,
                        sourcePath: $relativePath,
                        suggestedFix: $suggestedFix,
                        pass: 'doctor.directory_health',
                        whyItMatters: $whyItMatters,
                        details: ['path' => $relativePath, 'parent_writable' => $parentWritable],
                    );
                } else {
                    $diagnostics->warning(
                        code: $code,
                        category: 'filesystem',
                        message: $message,
                        sourcePath: $relativePath,
                        suggestedFix: $suggestedFix,
                        pass: 'doctor.directory_health',
                        whyItMatters: $whyItMatters,
                        details: ['path' => $relativePath, 'parent_writable' => $parentWritable],
                    );
                }

                continue;
            }

            if ($writable) {
                continue;
            }

            $diagnostics->error(
                code: 'FDY9107_DIRECTORY_NOT_WRITABLE',
                category: 'filesystem',
                message: 'Required directory is not writable: ' . $relativePath . '.',
                sourcePath: $relativePath,
                suggestedFix: 'Repair write permissions for the directory before compiling or running Foundry.',
                pass: 'doctor.directory_health',
                whyItMatters: 'Foundry must be able to write build outputs and runtime state into its managed directories.',
                details: ['path' => $relativePath],
            );
        }

        $summary = $diagnostics->summary();

        return [
            'status' => DoctorSummary::status($summary),
            'diagnostics_summary' => $summary,
            'directories' => $rows,
        ];
    }
}
