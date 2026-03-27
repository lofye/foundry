<?php
declare(strict_types=1);

namespace Foundry\Doctor\Checks;

use Foundry\Compiler\Diagnostics\DiagnosticBag;
use Foundry\Compiler\SourceScanner;
use Foundry\Doctor\DoctorCheck;
use Foundry\Doctor\DoctorContext;
use Foundry\Doctor\DoctorSummary;

final class MetadataFreshnessCheck implements DoctorCheck
{
    public function id(): string
    {
        return 'metadata_freshness';
    }

    public function description(): string
    {
        return 'Checks that compiled metadata matches current source files and flags stale context manifests.';
    }

    public function check(DoctorContext $context, DiagnosticBag $diagnostics): array
    {
        $scanner = new SourceScanner($context->paths);
        $sourceFiles = $scanner->sourceFiles();
        $currentSourceHashes = $scanner->hashFiles($sourceFiles);
        $currentSourceHash = $scanner->aggregateHash($currentSourceHashes);

        $manifestSourceHashes = [];
        foreach ((array) ($context->compileResult->manifest['source_files'] ?? []) as $path => $hash) {
            if (is_string($path) && is_string($hash)) {
                $manifestSourceHashes[$path] = $hash;
            }
        }
        ksort($manifestSourceHashes);

        if ($manifestSourceHashes !== $currentSourceHashes || $context->compileResult->graph->sourceHash() !== $currentSourceHash) {
            $diagnostics->error(
                code: 'FDY9113_BUILD_METADATA_STALE',
                category: 'metadata',
                message: 'Compiled source metadata is stale relative to current source files.',
                sourcePath: $context->relativePath($context->layout->compileManifestPath()),
                suggestedFix: $context->commandPrefix . ' compile graph --json',
                pass: 'doctor.metadata_freshness',
                whyItMatters: 'Foundry tooling assumes compiled metadata matches the current source-of-truth files; stale metadata can mislead verify, inspect, and runtime flows.',
                details: [
                    'compiled_source_hash' => $context->compileResult->graph->sourceHash(),
                    'current_source_hash' => $currentSourceHash,
                ],
            );
        }

        $staleContextFeatures = [];
        foreach (glob($context->paths->features() . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $feature = basename($dir);
            if ($context->featureFilter !== null && $context->featureFilter !== '' && $feature !== $context->featureFilter) {
                continue;
            }

            $contextManifestPath = $dir . '/context.manifest.json';
            if (!is_file($contextManifestPath)) {
                continue;
            }

            $contextMtime = filemtime($contextManifestPath);
            if (!is_int($contextMtime)) {
                continue;
            }

            $latestSourceMtime = $this->latestFeatureSourceMtime($dir);
            if ($latestSourceMtime <= $contextMtime) {
                continue;
            }

            $staleContextFeatures[] = $feature;
            $diagnostics->warning(
                code: 'FDY9114_CONTEXT_MANIFEST_STALE',
                category: 'metadata',
                message: 'Context manifest is older than the current feature source files for ' . $feature . '.',
                sourcePath: 'app/features/' . $feature . '/context.manifest.json',
                suggestedFix: $context->commandPrefix . ' generate context ' . $feature . ' --json',
                pass: 'doctor.metadata_freshness',
                whyItMatters: 'Stale context manifests can mislead inspect and prompt tooling that relies on generated feature context metadata.',
                details: ['feature' => $feature],
            );
        }

        sort($staleContextFeatures);
        $summary = $diagnostics->summary();

        return [
            'status' => DoctorSummary::status($summary),
            'diagnostics_summary' => $summary,
            'compiled_source_hash' => $context->compileResult->graph->sourceHash(),
            'current_source_hash' => $currentSourceHash,
            'stale_context_features' => $staleContextFeatures,
        ];
    }

    private function latestFeatureSourceMtime(string $featureDir): int
    {
        $latest = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($featureDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof \SplFileInfo || !$fileInfo->isFile()) {
                continue;
            }

            $path = str_replace('\\', '/', $fileInfo->getPathname());
            if (str_ends_with($path, '/context.manifest.json') || str_contains($path, '/tests/')) {
                continue;
            }

            $mtime = $fileInfo->getMTime();
            if ($mtime > $latest) {
                $latest = $mtime;
            }
        }

        return $latest;
    }
}
