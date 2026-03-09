<?php
declare(strict_types=1);

namespace Foundry\Compiler;

use Foundry\Support\Paths;

final readonly class SourceScanner
{
    public function __construct(private Paths $paths)
    {
    }

    /**
     * @return array<int,string>
     */
    public function sourceFiles(): array
    {
        $files = [];
        $featureDirs = glob($this->paths->features() . '/*', GLOB_ONLYDIR) ?: [];
        sort($featureDirs);

        foreach ($featureDirs as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $fileInfo) {
                if (!$fileInfo instanceof \SplFileInfo) {
                    continue;
                }

                if (!$fileInfo->isFile()) {
                    continue;
                }

                $relative = $this->relativePath($fileInfo->getPathname());
                if ($relative !== '') {
                    $files[] = $relative;
                }
            }
        }

        $projectSpecFiles = glob($this->paths->join('app/platform/config/*.yaml')) ?: [];
        sort($projectSpecFiles);
        foreach ($projectSpecFiles as $file) {
            $relative = $this->relativePath($file);
            if ($relative !== '') {
                $files[] = $relative;
            }
        }

        $phaseOneSpecFiles = glob($this->paths->join('app/specs/*/*.yaml')) ?: [];
        sort($phaseOneSpecFiles);
        foreach ($phaseOneSpecFiles as $file) {
            $relative = $this->relativePath($file);
            if ($relative !== '') {
                $files[] = $relative;
            }
        }

        sort($files);

        return array_values(array_unique($files));
    }

    /**
     * @param array<int,string> $files
     * @return array<string,string>
     */
    public function hashFiles(array $files): array
    {
        $hashes = [];
        foreach ($files as $relative) {
            $path = $this->paths->join($relative);
            if (!is_file($path)) {
                continue;
            }

            $hash = hash_file('sha256', $path);
            if ($hash === false) {
                continue;
            }

            $hashes[$relative] = $hash;
        }

        ksort($hashes);

        return $hashes;
    }

    /**
     * @param array<string,string> $hashes
     */
    public function aggregateHash(array $hashes): string
    {
        ksort($hashes);

        $buffer = '';
        foreach ($hashes as $path => $hash) {
            $buffer .= $path . ':' . $hash . "\n";
        }

        return hash('sha256', $buffer);
    }

    public function featureFromPath(string $relativePath): ?string
    {
        if (!str_starts_with($relativePath, 'app/features/')) {
            return null;
        }

        $parts = explode('/', $relativePath);
        if (count($parts) < 3) {
            return null;
        }

        $feature = (string) ($parts[2] ?? '');

        return $feature !== '' ? $feature : null;
    }

    /**
     * @param array<string,string> $previous
     * @param array<string,string> $current
     * @return array<int,string>
     */
    public function changedFiles(array $previous, array $current): array
    {
        $changed = [];

        foreach ($current as $path => $hash) {
            if (!isset($previous[$path]) || $previous[$path] !== $hash) {
                $changed[] = $path;
            }
        }

        foreach ($previous as $path => $hash) {
            if (!isset($current[$path])) {
                $changed[] = $path;
            }
        }

        sort($changed);

        return array_values(array_unique($changed));
    }

    private function relativePath(string $absolutePath): string
    {
        $root = rtrim($this->paths->root(), '/') . '/';
        if (!str_starts_with($absolutePath, $root)) {
            return '';
        }

        return ltrim(substr($absolutePath, strlen($root)), '/');
    }
}
