<?php
declare(strict_types=1);

namespace Foundry\Doctor\Checks;

use Foundry\Compiler\Diagnostics\DiagnosticBag;
use Foundry\Compiler\Extensions\VersionConstraint;
use Foundry\Doctor\DoctorCheck;
use Foundry\Doctor\DoctorContext;
use Foundry\Doctor\DoctorSummary;

final class RuntimeCompatibilityCheck implements DoctorCheck
{
    private readonly ?\Closure $phpVersionResolver;
    private readonly ?\Closure $extensionLoadedResolver;

    public function __construct(?callable $phpVersionResolver = null, ?callable $extensionLoadedResolver = null)
    {
        $this->phpVersionResolver = $phpVersionResolver !== null ? \Closure::fromCallable($phpVersionResolver) : null;
        $this->extensionLoadedResolver = $extensionLoadedResolver !== null ? \Closure::fromCallable($extensionLoadedResolver) : null;
    }

    public function id(): string
    {
        return 'runtime_compatibility';
    }

    public function description(): string
    {
        return 'Validates PHP version compatibility and required PHP extensions.';
    }

    public function check(DoctorContext $context, DiagnosticBag $diagnostics): array
    {
        $require = is_array($context->composerConfig['require'] ?? null)
            ? $context->composerConfig['require']
            : [];

        $phpConstraint = trim((string) ($require['php'] ?? ''));
        $phpVersion = $this->phpVersion();
        if ($phpConstraint !== '' && !VersionConstraint::matches($phpVersion, $phpConstraint)) {
            $diagnostics->error(
                code: 'FDY9101_PHP_VERSION_UNSUPPORTED',
                category: 'environment',
                message: sprintf('PHP %s does not satisfy the required version constraint %s.', $phpVersion, $phpConstraint),
                suggestedFix: 'Install a PHP version that satisfies the project requirement and rerun doctor.',
                pass: 'doctor.runtime_compatibility',
                whyItMatters: 'Foundry and its Composer dependencies are authored against a specific PHP runtime range; a mismatch can fail during boot, compile, or request execution.',
                details: [
                    'current_version' => $phpVersion,
                    'required_constraint' => $phpConstraint,
                ],
            );
        }

        $requiredExtensions = [];
        foreach ($require as $package => $constraint) {
            if (!is_string($package) || !str_starts_with($package, 'ext-')) {
                continue;
            }

            $requiredExtensions[] = substr($package, strlen('ext-'));
        }
        $requiredExtensions = array_values(array_unique(array_filter(array_map('strval', $requiredExtensions))));
        sort($requiredExtensions);

        $missingExtensions = [];
        foreach ($requiredExtensions as $extension) {
            if ($this->extensionLoaded($extension)) {
                continue;
            }

            $missingExtensions[] = $extension;
            $diagnostics->error(
                code: 'FDY9102_REQUIRED_EXTENSION_MISSING',
                category: 'environment',
                message: 'Required PHP extension is not loaded: ' . $extension . '.',
                suggestedFix: 'Enable or install the missing PHP extension and restart the CLI runtime.',
                pass: 'doctor.runtime_compatibility',
                whyItMatters: 'Missing PHP extensions prevent Composer dependencies and Foundry runtime features from loading consistently.',
                details: ['extension' => $extension],
            );
        }

        $summary = $diagnostics->summary();

        return [
            'status' => DoctorSummary::status($summary),
            'diagnostics_summary' => $summary,
            'php_version' => $phpVersion,
            'required_php_constraint' => $phpConstraint !== '' ? $phpConstraint : null,
            'required_extensions' => $requiredExtensions,
            'missing_extensions' => $missingExtensions,
        ];
    }

    private function phpVersion(): string
    {
        if ($this->phpVersionResolver instanceof \Closure) {
            return (string) ($this->phpVersionResolver)();
        }

        return PHP_VERSION;
    }

    private function extensionLoaded(string $extension): bool
    {
        if ($this->extensionLoadedResolver instanceof \Closure) {
            return (bool) ($this->extensionLoadedResolver)($extension);
        }

        return extension_loaded($extension);
    }
}
