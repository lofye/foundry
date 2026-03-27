<?php
declare(strict_types=1);

namespace Foundry\Doctor\Checks;

use Foundry\Compiler\Diagnostics\DiagnosticBag;
use Foundry\Doctor\DoctorCheck;
use Foundry\Doctor\DoctorContext;
use Foundry\Doctor\DoctorSummary;

final class InstallCompletenessCheck implements DoctorCheck
{
    public function id(): string
    {
        return 'install_completeness';
    }

    public function description(): string
    {
        return 'Checks that core install files and entrypoints are present and readable.';
    }

    public function check(DoctorContext $context, DiagnosticBag $diagnostics): array
    {
        if ($context->composerError !== null) {
            $diagnostics->error(
                code: 'FDY9105_COMPOSER_CONFIG_INVALID',
                category: 'install',
                message: 'composer.json is present but invalid: ' . $context->composerError,
                sourcePath: $context->relativePath($context->composerPath),
                suggestedFix: 'Repair composer.json so Foundry can resolve runtime and extension requirements.',
                pass: 'doctor.install_completeness',
                whyItMatters: 'Foundry uses Composer metadata to validate the runtime contract and expected install layout.',
            );
        }

        $requiredPaths = [
            'composer.json',
            'vendor/autoload.php',
            $context->projectType() === 'framework_repository' ? 'bin/foundry' : 'vendor/bin/foundry',
            'app/features',
        ];

        $missingPaths = [];
        foreach ($requiredPaths as $relativePath) {
            $absolutePath = $context->paths->join($relativePath);
            if (!file_exists($absolutePath)) {
                $missingPaths[] = $relativePath;
                $diagnostics->error(
                    code: 'FDY9103_INSTALL_PATH_MISSING',
                    category: 'install',
                    message: 'Required install path is missing: ' . $relativePath . '.',
                    sourcePath: $relativePath,
                    suggestedFix: 'Restore the missing scaffold or reinstall dependencies for this project.',
                    pass: 'doctor.install_completeness',
                    whyItMatters: 'Foundry expects these paths to exist so it can boot the framework, load source definitions, and expose the CLI entrypoint.',
                    details: ['path' => $relativePath],
                );
                continue;
            }

            if (is_readable($absolutePath)) {
                continue;
            }

            $diagnostics->error(
                code: 'FDY9104_INSTALL_PATH_UNREADABLE',
                category: 'install',
                message: 'Required install path is not readable: ' . $relativePath . '.',
                sourcePath: $relativePath,
                suggestedFix: 'Repair file permissions for the install path and rerun doctor.',
                pass: 'doctor.install_completeness',
                whyItMatters: 'Unreadable install paths prevent Foundry from loading code, source files, or the CLI bootstrap correctly.',
                details: ['path' => $relativePath],
            );
        }

        $summary = $diagnostics->summary();

        return [
            'status' => DoctorSummary::status($summary),
            'diagnostics_summary' => $summary,
            'project_type' => $context->projectType(),
            'required_paths' => $requiredPaths,
            'missing_paths' => $missingPaths,
        ];
    }
}
