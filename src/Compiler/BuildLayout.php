<?php

declare(strict_types=1);

namespace Foundry\Compiler;

use Foundry\Support\Paths;

final readonly class BuildLayout
{
    public function __construct(private Paths $paths) {}

    public function buildRoot(): string
    {
        return $this->paths->join('app/.foundry/build');
    }

    public function graphDir(): string
    {
        return $this->buildRoot() . '/graph';
    }

    public function projectionDir(): string
    {
        return $this->buildRoot() . '/projections';
    }

    public function manifestsDir(): string
    {
        return $this->buildRoot() . '/manifests';
    }

    public function diagnosticsDir(): string
    {
        return $this->buildRoot() . '/diagnostics';
    }

    public function qualityDir(): string
    {
        return $this->buildRoot() . '/quality';
    }

    public function observabilityDir(): string
    {
        return $this->buildRoot() . '/observability';
    }

    public function historyDir(): string
    {
        return $this->buildRoot() . '/history';
    }

    public function graphJsonPath(): string
    {
        return $this->graphDir() . '/app_graph.json';
    }

    public function graphPhpPath(): string
    {
        return $this->graphDir() . '/app_graph.php';
    }

    public function diagnosticsPath(): string
    {
        return $this->diagnosticsDir() . '/latest.json';
    }

    public function configValidationPath(): string
    {
        return $this->diagnosticsDir() . '/config_validation.json';
    }

    public function compileManifestPath(): string
    {
        return $this->manifestsDir() . '/compile_manifest.json';
    }

    public function compileCachePath(): string
    {
        return $this->manifestsDir() . '/compile_cache.json';
    }

    public function configSchemasPath(): string
    {
        return $this->manifestsDir() . '/config_schemas.json';
    }

    public function integrityHashesPath(): string
    {
        return $this->manifestsDir() . '/integrity_hashes.json';
    }

    public function qualitySummaryPath(): string
    {
        return $this->qualityDir() . '/summary.json';
    }

    public function qualityStaticAnalysisPath(): string
    {
        return $this->qualityDir() . '/phpstan.json';
    }

    public function qualityStylePath(): string
    {
        return $this->qualityDir() . '/pint.json';
    }

    public function qualityTestSummaryPath(): string
    {
        return $this->qualityDir() . '/phpunit.json';
    }

    public function observeTracePath(): string
    {
        return $this->observabilityDir() . '/trace.json';
    }

    public function observeProfilePath(): string
    {
        return $this->observabilityDir() . '/profile.json';
    }

    public function observeComparePath(): string
    {
        return $this->observabilityDir() . '/compare.json';
    }

    public function historyIndexPath(): string
    {
        return $this->historyDir() . '/index.json';
    }

    public function historyRecordPath(string $id): string
    {
        return $this->historyDir() . '/' . $id . '.json';
    }

    public function projectionPath(string $file): string
    {
        return $this->projectionDir() . '/' . $file;
    }

    public function legacyProjectionPath(string $file): string
    {
        return $this->paths->generated() . '/' . $file;
    }

    public function ensureDirectories(): void
    {
        foreach ([
            $this->buildRoot(),
            $this->graphDir(),
            $this->projectionDir(),
            $this->manifestsDir(),
            $this->diagnosticsDir(),
            $this->qualityDir(),
            $this->observabilityDir(),
            $this->historyDir(),
            $this->paths->generated(),
        ] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
        }
    }
}
