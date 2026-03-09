<?php
declare(strict_types=1);

namespace Foundry\Compiler;

use Foundry\Support\Paths;

final readonly class BuildLayout
{
    public function __construct(private Paths $paths)
    {
    }

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

    public function compileManifestPath(): string
    {
        return $this->manifestsDir() . '/compile_manifest.json';
    }

    public function integrityHashesPath(): string
    {
        return $this->manifestsDir() . '/integrity_hashes.json';
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
            $this->paths->generated(),
        ] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
        }
    }
}
