<?php
declare(strict_types=1);

namespace Foundry\Compiler\Migration;

use Foundry\Compiler\Diagnostics\DiagnosticBag;
use Foundry\Support\Paths;
use Foundry\Support\Yaml;

final class SpecMigrator
{
    /**
     * @param array<int,MigrationRule> $rules
     * @param array<int,SpecFormat> $formats
     */
    public function __construct(
        private readonly Paths $paths,
        private readonly ManifestVersionResolver $resolver,
        private readonly array $rules,
        private readonly array $formats = [],
    ) {
    }

    public function migrate(bool $write = false, ?string $path = null, ?DiagnosticBag $diagnostics = null): SpecMigrationResult
    {
        $changes = [];
        $plans = [];
        $inlineDiagnostics = [];

        foreach ($this->featureManifestPaths($path) as $manifestPath) {
            $relativePath = $this->relativePath($manifestPath);
            if (!is_file($manifestPath)) {
                $inlineDiagnostics[] = [
                    'code' => 'FDY7004_NO_MIGRATION_PATH',
                    'severity' => 'error',
                    'category' => 'migrations',
                    'message' => 'Target path does not exist or is not a feature manifest.',
                    'source_path' => $relativePath,
                ];
                if ($diagnostics !== null) {
                    $diagnostics->error('FDY7004_NO_MIGRATION_PATH', 'migrations', 'Target path does not exist or is not a feature manifest.', sourcePath: $relativePath, pass: 'migrate_specs');
                }
                continue;
            }

            try {
                $document = Yaml::parseFile($manifestPath);
            } catch (\Throwable $error) {
                $inlineDiagnostics[] = [
                    'code' => 'FDY3002_MANIFEST_PARSE_FAILED',
                    'severity' => 'error',
                    'category' => 'migrations',
                    'message' => $error->getMessage(),
                    'source_path' => $relativePath,
                ];
                if ($diagnostics !== null) {
                    $diagnostics->error('FDY3002_MANIFEST_PARSE_FAILED', 'migrations', $error->getMessage(), sourcePath: $relativePath, pass: 'migrate_specs');
                }
                continue;
            }

            $formatName = 'feature_manifest';
            $currentVersion = $this->resolver->resolveFeatureVersion($document);
            $targetVersion = $this->resolver->currentFeatureVersion();

            if ($currentVersion > $targetVersion) {
                $message = sprintf(
                    'Unsupported spec version %d for %s; current supported version is %d.',
                    $currentVersion,
                    $formatName,
                    $targetVersion,
                );
                $inlineDiagnostics[] = [
                    'code' => 'FDY7003_UNSUPPORTED_SPEC_VERSION',
                    'severity' => 'error',
                    'category' => 'migrations',
                    'message' => $message,
                    'source_path' => $relativePath,
                ];
                if ($diagnostics !== null) {
                    $diagnostics->error('FDY7003_UNSUPPORTED_SPEC_VERSION', 'migrations', $message, sourcePath: $relativePath, pass: 'migrate_specs');
                }
                continue;
            }

            if ($this->resolver->isFeatureOutdated($document)) {
                $message = sprintf(
                    'Outdated feature manifest version %d detected; expected %d.',
                    $currentVersion,
                    $this->resolver->currentFeatureVersion(),
                );
                $inlineDiagnostics[] = [
                    'code' => 'FDY3001_OUTDATED_FEATURE_MANIFEST',
                    'severity' => 'warning',
                    'category' => 'migrations',
                    'message' => $message,
                    'source_path' => $relativePath,
                ];

                if ($diagnostics !== null) {
                    $diagnostics->warning('FDY3001_OUTDATED_FEATURE_MANIFEST', 'migrations', $message, sourcePath: $relativePath, pass: 'migrate_specs');
                }
            }

            $migrated = $document;
            $migrationPath = $this->migrationPath($relativePath, $migrated, $currentVersion, $targetVersion);
            $plans[] = [
                'path' => $relativePath,
                'format' => $formatName,
                'from_version' => $currentVersion,
                'to_version' => $targetVersion,
                'rules' => $migrationPath['rules'],
                'status' => $migrationPath['status'],
                'reason' => $migrationPath['reason'],
            ];

            if ($migrationPath['status'] === 'missing_path') {
                $inlineDiagnostics[] = [
                    'code' => 'FDY7004_NO_MIGRATION_PATH',
                    'severity' => 'error',
                    'category' => 'migrations',
                    'message' => (string) $migrationPath['reason'],
                    'source_path' => $relativePath,
                ];
                if ($diagnostics !== null) {
                    $diagnostics->error('FDY7004_NO_MIGRATION_PATH', 'migrations', (string) $migrationPath['reason'], sourcePath: $relativePath, pass: 'migrate_specs');
                }
                continue;
            }

            $appliedRuleIds = array_values(array_map('strval', (array) ($migrationPath['rules'] ?? [])));
            foreach ($this->rules as $rule) {
                if (!in_array($rule->id(), $appliedRuleIds, true)) {
                    continue;
                }
                $migrated = $rule->migrate($relativePath, $migrated);
            }

            if ($appliedRuleIds === []) {
                continue;
            }

            $changes[] = [
                'path' => $relativePath,
                'rules' => $appliedRuleIds,
                'from_version' => $currentVersion,
                'to_version' => $this->resolver->resolveFeatureVersion($migrated),
                'format' => $formatName,
                'write' => $write,
            ];

            if ($write) {
                file_put_contents($manifestPath, Yaml::dump($migrated));
            }
        }

        return new SpecMigrationResult(
            written: $write,
            changes: $changes,
            diagnostics: $inlineDiagnostics,
            plans: $plans,
            pathFilter: $path,
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function inspect(): array
    {
        $rows = [];
        foreach ($this->rules as $rule) {
            $rows[] = [
                'id' => $rule->id(),
                'description' => $rule->description(),
                'source_type' => $rule->sourceType(),
                'from_version' => $rule->fromVersion(),
                'to_version' => $rule->toVersion(),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id']));

        return $rows;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function specFormats(): array
    {
        $formats = $this->formats;
        if ($formats === []) {
            $formats = [
                new SpecFormat(
                    name: 'feature_manifest',
                    description: 'Feature manifest files under app/features/<feature>/feature.yaml',
                    currentVersion: $this->resolver->currentFeatureVersion(),
                    supportedVersions: [1, $this->resolver->currentFeatureVersion()],
                ),
            ];
        }

        usort($formats, static fn (SpecFormat $a, SpecFormat $b): int => strcmp($a->name, $b->name));

        return array_values(array_map(
            static fn (SpecFormat $format): array => $format->toArray(),
            $formats,
        ));
    }

    /**
     * @return array<string,mixed>|null
     */
    public function specFormat(string $name): ?array
    {
        foreach ($this->specFormats() as $format) {
            if ((string) ($format['name'] ?? '') === $name) {
                return $format;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $document
     * @return array{status:string,reason:string,rules:array<int,string>}
     */
    private function migrationPath(string $path, array $document, int $fromVersion, int $toVersion): array
    {
        if ($fromVersion >= $toVersion) {
            return [
                'status' => 'up_to_date',
                'reason' => 'spec is already current',
                'rules' => [],
            ];
        }

        $rules = [];
        $currentVersion = $fromVersion;
        $working = $document;

        while ($currentVersion < $toVersion) {
            $nextRule = null;
            foreach ($this->rules as $rule) {
                if ($rule->sourceType() !== 'feature_manifest') {
                    continue;
                }
                if ($rule->fromVersion() !== $currentVersion) {
                    continue;
                }
                if (!$rule->applies($path, $working)) {
                    continue;
                }
                $nextRule = $rule;
                break;
            }

            if ($nextRule === null) {
                return [
                    'status' => 'missing_path',
                    'reason' => sprintf(
                        'No migration rule available from version %d to %d for feature_manifest.',
                        $currentVersion,
                        $toVersion,
                    ),
                    'rules' => $rules,
                ];
            }

            $rules[] = $nextRule->id();
            $working = $nextRule->migrate($path, $working);
            $currentVersion = $nextRule->toVersion();
        }

        return [
            'status' => 'migratable',
            'reason' => 'migration path available',
            'rules' => $rules,
        ];
    }

    /**
     * @return array<int,string>
     */
    private function featureManifestPaths(?string $path = null): array
    {
        if ($path !== null && $path !== '') {
            $absolute = $this->absolutePath($path);

            return [$absolute];
        }

        $paths = [];
        $featureDirs = glob($this->paths->features() . '/*', GLOB_ONLYDIR) ?: [];
        sort($featureDirs);
        foreach ($featureDirs as $dir) {
            $paths[] = $dir . '/feature.yaml';
        }

        return $paths;
    }

    private function absolutePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return $this->paths->join($path);
    }

    private function relativePath(string $absolutePath): string
    {
        $root = rtrim($this->paths->root(), '/') . '/';

        return str_starts_with($absolutePath, $root)
            ? substr($absolutePath, strlen($root))
            : $absolutePath;
    }
}
