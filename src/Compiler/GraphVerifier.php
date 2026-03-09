<?php
declare(strict_types=1);

namespace Foundry\Compiler;

use Foundry\Support\Json;
use Foundry\Support\Paths;
use Foundry\Verification\VerificationResult;

final class GraphVerifier
{
    public function __construct(
        private readonly Paths $paths,
        private readonly BuildLayout $layout,
    ) {
    }

    public function verify(): VerificationResult
    {
        $errors = [];
        $warnings = [];

        $requiredFiles = [
            $this->layout->graphJsonPath(),
            $this->layout->graphPhpPath(),
            $this->layout->compileManifestPath(),
            $this->layout->integrityHashesPath(),
            $this->layout->diagnosticsPath(),
            $this->layout->projectionPath('routes_index.php'),
            $this->layout->projectionPath('feature_index.php'),
            $this->layout->projectionPath('schema_index.php'),
            $this->layout->projectionPath('permission_index.php'),
            $this->layout->projectionPath('event_index.php'),
            $this->layout->projectionPath('job_index.php'),
            $this->layout->projectionPath('cache_index.php'),
            $this->layout->projectionPath('scheduler_index.php'),
            $this->layout->projectionPath('webhook_index.php'),
            $this->layout->projectionPath('query_index.php'),
            $this->layout->projectionPath('pipeline_index.php'),
            $this->layout->projectionPath('guard_index.php'),
            $this->layout->projectionPath('execution_plan_index.php'),
            $this->layout->projectionPath('interceptor_index.php'),
            $this->layout->projectionPath('starter_index.php'),
            $this->layout->projectionPath('resource_index.php'),
            $this->layout->projectionPath('admin_resource_index.php'),
            $this->layout->projectionPath('upload_profile_index.php'),
            $this->layout->projectionPath('listing_index.php'),
            $this->layout->projectionPath('form_index.php'),
            $this->layout->projectionPath('notification_index.php'),
            $this->layout->projectionPath('api_resource_index.php'),
        ];

        foreach ($requiredFiles as $file) {
            if (!is_file($file)) {
                $errors[] = 'Missing required build artifact: ' . $this->relativePath($file);
            }
        }

        $manifest = $this->readJsonFile($this->layout->compileManifestPath());
        if ($manifest === null) {
            $errors[] = 'compile_manifest.json is missing or invalid JSON.';
        }

        $diagnostics = $this->readJsonFile($this->layout->diagnosticsPath());
        if ($diagnostics === null) {
            $errors[] = 'diagnostics/latest.json is missing or invalid JSON.';
        } else {
            $summary = is_array($diagnostics['summary'] ?? null) ? $diagnostics['summary'] : [];
            if ((int) ($summary['error'] ?? 0) > 0) {
                $errors[] = 'Compiled graph contains error diagnostics.';
            }
        }

        $integrity = $this->readJsonFile($this->layout->integrityHashesPath());
        if ($integrity === null) {
            $errors[] = 'integrity_hashes.json is missing or invalid JSON.';
        } else {
            foreach ($integrity as $relative => $expectedHash) {
                if (!is_string($relative) || !is_string($expectedHash)) {
                    continue;
                }

                $absolute = $this->paths->join($relative);
                if (!is_file($absolute)) {
                    $warnings[] = 'Integrity file references missing artifact: ' . $relative;
                    continue;
                }

                $actualHash = hash_file('sha256', $absolute);
                if ($actualHash === false) {
                    $warnings[] = 'Failed to compute integrity hash for: ' . $relative;
                    continue;
                }

                if ($actualHash !== $expectedHash) {
                    $warnings[] = 'Integrity mismatch detected (possible manual edit): ' . $relative;
                }
            }
        }

        return new VerificationResult($errors === [], $errors, $warnings);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readJsonFile(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return null;
        }

        try {
            return Json::decodeAssoc($content);
        } catch (\Throwable) {
            return null;
        }
    }

    private function relativePath(string $absolutePath): string
    {
        $root = rtrim($this->paths->root(), '/') . '/';

        return str_starts_with($absolutePath, $root)
            ? substr($absolutePath, strlen($root))
            : $absolutePath;
    }
}
