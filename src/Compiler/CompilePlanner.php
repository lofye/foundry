<?php
declare(strict_types=1);

namespace Foundry\Compiler;

final class CompilePlanner
{
    /**
     * @param array<string,mixed> $previousManifest
     * @param array<string,string> $currentSourceHashes
     * @param array<int,string> $currentFeatures
     */
    public function plan(
        CompileOptions $options,
        array $previousManifest,
        array $currentSourceHashes,
        array $currentFeatures,
        bool $hasPreviousGraph,
        SourceScanner $scanner,
        string $frameworkVersion,
    ): CompilePlan {
        sort($currentFeatures);
        $mode = $options->mode();

        $previousHashes = is_array($previousManifest['source_files'] ?? null)
            ? array_map('strval', $previousManifest['source_files'])
            : [];
        $changedFiles = $scanner->changedFiles($previousHashes, $currentSourceHashes);

        $changedFeatures = [];
        $hasOutOfFeatureChanges = false;
        foreach ($changedFiles as $path) {
            $feature = $scanner->featureFromPath($path);
            if ($feature === null) {
                $hasOutOfFeatureChanges = true;
                continue;
            }

            $changedFeatures[] = $feature;
        }

        $changedFeatures = array_values(array_unique($changedFeatures));
        sort($changedFeatures);

        $previousFeatures = array_values(array_map('strval', (array) ($previousManifest['features'] ?? [])));
        sort($previousFeatures);

        $removedFeatures = array_values(array_diff($previousFeatures, $currentFeatures));
        sort($removedFeatures);

        $frameworkChanged = ((string) ($previousManifest['framework_version'] ?? '')) !== ''
            && ((string) ($previousManifest['framework_version'] ?? '') !== $frameworkVersion);

        if ($mode === 'full') {
            return new CompilePlan(
                mode: $mode,
                incremental: false,
                noChanges: false,
                fallbackToFull: false,
                selectedFeatures: $currentFeatures,
                changedFeatures: array_values(array_unique(array_merge($changedFeatures, $removedFeatures))),
                changedFiles: $changedFiles,
                reason: 'full compile requested',
            );
        }

        if (!$hasPreviousGraph || $previousManifest === [] || $frameworkChanged) {
            return new CompilePlan(
                mode: $mode,
                incremental: false,
                noChanges: false,
                fallbackToFull: true,
                selectedFeatures: $currentFeatures,
                changedFeatures: array_values(array_unique(array_merge($changedFeatures, $removedFeatures))),
                changedFiles: $changedFiles,
                reason: $frameworkChanged ? 'framework version changed; full compile required' : 'no previous build state; full compile required',
            );
        }

        if ($hasOutOfFeatureChanges) {
            return new CompilePlan(
                mode: $mode,
                incremental: false,
                noChanges: false,
                fallbackToFull: true,
                selectedFeatures: $currentFeatures,
                changedFeatures: array_values(array_unique(array_merge($changedFeatures, $removedFeatures))),
                changedFiles: $changedFiles,
                reason: 'non-feature source changed; full compile required',
            );
        }

        if ($mode === 'changed_only') {
            $selected = array_values(array_unique(array_merge($changedFeatures, $removedFeatures)));
            sort($selected);

            return new CompilePlan(
                mode: $mode,
                incremental: true,
                noChanges: $selected === [],
                fallbackToFull: false,
                selectedFeatures: $selected,
                changedFeatures: $selected,
                changedFiles: $changedFiles,
                reason: $selected === [] ? 'no source changes detected' : 'recompiling changed feature subgraph',
            );
        }

        $requestedFeature = (string) ($options->feature ?? '');
        $selected = [$requestedFeature];
        foreach ($changedFeatures as $changedFeature) {
            if ($changedFeature !== $requestedFeature) {
                $selected[] = $changedFeature;
            }
        }

        foreach ($removedFeatures as $removedFeature) {
            if (!in_array($removedFeature, $selected, true)) {
                $selected[] = $removedFeature;
            }
        }

        $selected = array_values(array_filter(array_unique($selected), static fn (string $feature): bool => $feature !== ''));
        sort($selected);

        return new CompilePlan(
            mode: $mode,
            incremental: true,
            noChanges: false,
            fallbackToFull: false,
            selectedFeatures: $selected,
            changedFeatures: array_values(array_unique(array_merge($changedFeatures, $removedFeatures))),
            changedFiles: $changedFiles,
            reason: 'feature-targeted compile with stale-guard expansion',
        );
    }
}
