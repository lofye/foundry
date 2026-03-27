<?php
declare(strict_types=1);

namespace Foundry\Doctor;

use Foundry\Compiler\BuildLayout;
use Foundry\Compiler\CompileResult;
use Foundry\Compiler\Extensions\CompatibilityReport;
use Foundry\Compiler\Extensions\ExtensionRegistry;
use Foundry\Support\Paths;

final readonly class DoctorContext
{
    /**
     * @param array<string,mixed>|null $composerConfig
     */
    public function __construct(
        public Paths $paths,
        public BuildLayout $layout,
        public CompileResult $compileResult,
        public ExtensionRegistry $extensionRegistry,
        public CompatibilityReport $extensionReport,
        public ?string $featureFilter,
        public string $commandPrefix,
        public string $composerPath,
        public ?array $composerConfig = null,
        public ?string $composerError = null,
    ) {
    }

    public function projectType(): string
    {
        return $this->paths->root() === $this->paths->frameworkRoot()
            ? 'framework_repository'
            : 'application';
    }

    public function relativePath(string $absolutePath): string
    {
        $root = rtrim($this->paths->root(), '/') . '/';

        return str_starts_with($absolutePath, $root)
            ? substr($absolutePath, strlen($root))
            : $absolutePath;
    }
}
