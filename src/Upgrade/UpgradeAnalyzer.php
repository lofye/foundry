<?php
declare(strict_types=1);

namespace Foundry\Upgrade;

use Foundry\Compiler\CompileOptions;
use Foundry\Compiler\CompileResult;
use Foundry\Compiler\Extensions\CompatibilityReport;
use Foundry\Compiler\Migration\DefinitionMigrationResult;
use Foundry\Compiler\Migration\DefinitionMigrator;
use Foundry\Compiler\Projection\ProjectionEmitter;
use Foundry\Compiler\Extensions\ExtensionRegistry;
use Foundry\Compiler\GraphCompiler;
use Foundry\Support\Paths;

final class UpgradeAnalyzer
{
    private readonly FrameworkDeprecationRegistry $deprecations;

    public function __construct(
        private readonly Paths $paths,
        private readonly GraphCompiler $compiler,
        private readonly ExtensionRegistry $extensions,
        private readonly DefinitionMigrator $migrator,
        ?FrameworkDeprecationRegistry $deprecations = null,
    ) {
        $this->deprecations = $deprecations ?? new FrameworkDeprecationRegistry();
    }

    public function defaultTargetVersion(): string
    {
        $current = $this->compiler->frameworkVersion();

        if ($current === 'dev-main' || VersionComparator::compare($current, '1.0.0') < 0) {
            return '1.0.0';
        }

        return $current;
    }

    public function isValidTargetVersion(string $version): bool
    {
        return VersionComparator::isValid($version);
    }

    public function analyze(?string $targetVersion = null): UpgradeReport
    {
        $targetVersion = $targetVersion !== null && $targetVersion !== ''
            ? $targetVersion
            : $this->defaultTargetVersion();

        $compile = $this->compiler->compile(new CompileOptions(emit: false));
        $migrationResult = $this->migrator->migrate(write: false);
        $compatibility = $this->extensions->compatibilityReport(
            frameworkVersion: $targetVersion,
            graphVersion: $compile->graph->graphVersion(),
        );

        $cliUsage = $this->scanDeprecatedCliUsage($targetVersion);
        $projectionFallbacks = $this->legacyProjectionFallbackIssues($targetVersion);

        $issues = array_merge(
            $this->deprecatedConfigIssues($compile, $targetVersion),
            $this->manifestVersionIssues($migrationResult, $targetVersion),
            $this->compatibilityIssues($compatibility, $targetVersion),
            $cliUsage['issues'],
            $projectionFallbacks,
        );

        usort(
            $issues,
            static fn (UpgradeIssue $a, UpgradeIssue $b): int => self::severityRank($a->severity) <=> self::severityRank($b->severity)
                ?: strcmp($a->category, $b->category)
                ?: strcmp($a->code, $b->code)
                ?: strcmp((string) ($a->affected['source_path'] ?? ''), (string) ($b->affected['source_path'] ?? '')),
        );

        $summary = [
            'error' => 0,
            'warning' => 0,
            'info' => 0,
            'total' => count($issues),
        ];

        foreach ($issues as $issue) {
            $summary[$issue->severity] = ($summary[$issue->severity] ?? 0) + 1;
        }

        return new UpgradeReport(
            ok: (int) ($summary['error'] ?? 0) === 0,
            currentVersion: $this->compiler->frameworkVersion(),
            targetVersion: $targetVersion,
            graphVersion: $compile->graph->graphVersion(),
            commandPrefix: $this->commandPrefix(),
            summary: $summary,
            issues: $issues,
            checks: [
                'config_validation' => [
                    'summary' => (array) ($compile->configValidation['summary'] ?? []),
                    'validated_sources' => array_values(array_map(
                        'strval',
                        (array) ($compile->configValidation['validated_sources'] ?? []),
                    )),
                ],
                'migrations' => [
                    'plans' => $migrationResult->plans,
                    'diagnostics' => $migrationResult->diagnostics,
                ],
                'compatibility' => [
                    'diagnostics' => $compatibility->diagnostics,
                    'version_matrix' => $compatibility->versionMatrix,
                ],
                'cli_usage' => [
                    'scanned_files' => $cliUsage['scanned_files'],
                ],
            ],
        );
    }

    /**
     * @return array<int,UpgradeIssue>
     */
    private function deprecatedConfigIssues(CompileResult $compile, string $targetVersion): array
    {
        $metadata = $this->deprecations->get('config.compatibility_alias');
        if ($metadata === null || !$metadata->appliesTo($targetVersion)) {
            return [];
        }

        $issues = [];
        foreach ((array) ($compile->configValidation['items'] ?? []) as $item) {
            if (!is_array($item) || (string) ($item['code'] ?? '') !== 'FDY1704_CONFIG_COMPATIBILITY_ALIAS_USED') {
                continue;
            }

            $issues[] = new UpgradeIssue(
                code: (string) ($item['code'] ?? 'FDY1704_CONFIG_COMPATIBILITY_ALIAS_USED'),
                severity: (string) ($item['severity'] ?? $metadata->severity),
                category: (string) ($item['category'] ?? $metadata->category),
                summary: (string) ($item['message'] ?? $metadata->title),
                affected: [
                    'source_path' => (string) ($item['source_path'] ?? ''),
                    'config_path' => (string) ($item['config_path'] ?? ''),
                    'schema_id' => (string) ($item['schema_id'] ?? ''),
                ],
                whyItMatters: $metadata->whyItMatters,
                introducedIn: $metadata->introducedIn,
                targetVersion: $targetVersion,
                migration: trim((string) ($item['suggested_fix'] ?? '')) !== '' ? (string) $item['suggested_fix'] : $metadata->migration,
                reference: $metadata->reference,
                details: ['upstream' => $item],
            );
        }

        return $issues;
    }

    /**
     * @return array<int,UpgradeIssue>
     */
    private function manifestVersionIssues(DefinitionMigrationResult $migrationResult, string $targetVersion): array
    {
        $issues = [];

        foreach ($migrationResult->diagnostics as $diagnostic) {
            if (!is_array($diagnostic)) {
                continue;
            }

            $code = (string) ($diagnostic['code'] ?? '');
            if (!in_array($code, ['FDY7003_UNSUPPORTED_DEFINITION_VERSION', 'FDY7004_NO_MIGRATION_PATH'], true)) {
                continue;
            }

            $issues[] = new UpgradeIssue(
                code: $code,
                severity: (string) ($diagnostic['severity'] ?? 'error'),
                category: (string) ($diagnostic['category'] ?? 'migrations'),
                summary: (string) ($diagnostic['message'] ?? 'Definition format upgrade issue detected.'),
                affected: [
                    'source_path' => (string) ($diagnostic['source_path'] ?? ''),
                    'format' => 'feature_manifest',
                ],
                whyItMatters: 'The framework cannot safely upgrade a feature manifest that is already unsupported or lacks a migration path.',
                introducedIn: $targetVersion,
                targetVersion: $targetVersion,
                migration: sprintf(
                    'Review the feature manifest version and add or apply a migration path before upgrading. Start with `%s inspect migrations --json`.',
                    $this->commandPrefix(),
                ),
                reference: 'docs/upgrade-safety.md#feature-manifest-v1',
                details: ['upstream' => $diagnostic],
            );
        }

        $metadata = $this->deprecations->get('feature_manifest.v1');
        if ($metadata === null || !$metadata->appliesTo($targetVersion)) {
            return $issues;
        }

        foreach ($migrationResult->plans as $plan) {
            if (!is_array($plan) || (string) ($plan['status'] ?? '') !== 'migratable') {
                continue;
            }

            $issues[] = new UpgradeIssue(
                code: 'FDY3001_OUTDATED_FEATURE_MANIFEST',
                severity: $metadata->severity,
                category: $metadata->category,
                summary: sprintf(
                    'Feature manifest %s is still on version %d and should be upgraded before %s.',
                    (string) ($plan['path'] ?? ''),
                    (int) ($plan['from_version'] ?? 0),
                    $targetVersion,
                ),
                affected: [
                    'source_path' => (string) ($plan['path'] ?? ''),
                    'from_version' => (int) ($plan['from_version'] ?? 0),
                    'to_version' => (int) ($plan['to_version'] ?? 0),
                    'rules' => array_values(array_map('strval', (array) ($plan['rules'] ?? []))),
                ],
                whyItMatters: $metadata->whyItMatters,
                introducedIn: $metadata->introducedIn,
                targetVersion: $targetVersion,
                migration: sprintf(
                    'Run `%s migrate definitions --path=%s --write` and review the resulting manifest diff.',
                    $this->commandPrefix(),
                    (string) ($plan['path'] ?? ''),
                ),
                reference: $metadata->reference,
                details: ['plan' => $plan],
            );
        }

        return $issues;
    }

    /**
     * @return array<int,UpgradeIssue>
     */
    private function compatibilityIssues(CompatibilityReport $report, string $targetVersion): array
    {
        $issues = [];

        foreach ($report->diagnostics as $diagnostic) {
            if (!is_array($diagnostic)) {
                continue;
            }

            ['why' => $why, 'migration' => $migration] = $this->compatibilityGuidance($diagnostic, $targetVersion);
            $issues[] = new UpgradeIssue(
                code: (string) ($diagnostic['code'] ?? 'FDY7999_UPGRADE_COMPATIBILITY'),
                severity: (string) ($diagnostic['severity'] ?? 'warning'),
                category: (string) ($diagnostic['category'] ?? 'extensions'),
                summary: (string) ($diagnostic['message'] ?? 'Extension compatibility issue detected.'),
                affected: array_filter([
                    'extension' => (string) ($diagnostic['extension'] ?? ''),
                    'pack' => (string) ($diagnostic['pack'] ?? ''),
                ], static fn (mixed $value): bool => $value !== ''),
                whyItMatters: $why,
                introducedIn: $targetVersion,
                targetVersion: $targetVersion,
                migration: $migration,
                reference: 'docs/upgrade-safety.md#extension-compatibility',
                details: ['upstream' => $diagnostic],
            );
        }

        return $issues;
    }

    /**
     * @return array{issues:array<int,UpgradeIssue>,scanned_files:array<int,string>}
     */
    private function scanDeprecatedCliUsage(string $targetVersion): array
    {
        $metadata = $this->deprecations->get('cli.init_app');
        if ($metadata === null || !$metadata->appliesTo($targetVersion)) {
            return ['issues' => [], 'scanned_files' => []];
        }

        $issues = [];
        $scannedFiles = [];
        $pattern = '/(?:php\s+(?:vendor\/bin|bin)\/foundry|foundry)\s+init\s+app\b/i';

        $composerPath = $this->paths->join('composer.json');
        if (is_file($composerPath)) {
            $scannedFiles[] = 'composer.json';
            $content = file_get_contents($composerPath);
            if ($content !== false) {
                try {
                    /** @var mixed $decoded */
                    $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
                } catch (\Throwable) {
                    $decoded = null;
                }

                if (is_array($decoded)) {
                    foreach ($this->composerScriptStrings((array) ($decoded['scripts'] ?? [])) as $scriptName => $command) {
                        if (preg_match($pattern, $command) !== 1) {
                            continue;
                        }

                        $issues[] = new UpgradeIssue(
                            code: 'FDY1301_DEPRECATED_CLI_USAGE',
                            severity: $metadata->severity,
                            category: $metadata->category,
                            summary: sprintf('Legacy `init app` CLI alias detected in composer script `%s`.', $scriptName),
                            affected: [
                                'source_path' => 'composer.json',
                                'script' => $scriptName,
                                'command' => $command,
                            ],
                            whyItMatters: $metadata->whyItMatters,
                            introducedIn: $metadata->introducedIn,
                            targetVersion: $targetVersion,
                            migration: 'Replace the script command with `' . preg_replace('/\binit\s+app\b/i', 'new', $command, 1) . '`.',
                            reference: $metadata->reference,
                        );
                    }
                }
            }
        }

        foreach (['README.md', 'AGENTS.md'] as $relativePath) {
            $absolutePath = $this->paths->join($relativePath);
            if (!is_file($absolutePath)) {
                continue;
            }

            $scannedFiles[] = $relativePath;
            $content = file_get_contents($absolutePath);
            if ($content === false || preg_match($pattern, $content) !== 1) {
                continue;
            }

            $match = [];
            preg_match($pattern, $content, $match, PREG_OFFSET_CAPTURE);
            $matchedText = (string) (($match[0][0] ?? ''));
            $offset = (int) (($match[0][1] ?? 0));
            $line = substr_count(substr($content, 0, $offset), "\n") + 1;

            $issues[] = new UpgradeIssue(
                code: 'FDY1301_DEPRECATED_CLI_USAGE',
                severity: $metadata->severity,
                category: $metadata->category,
                summary: sprintf('Legacy `init app` CLI alias detected in %s.', $relativePath),
                affected: [
                    'source_path' => $relativePath,
                    'line' => $line,
                    'match' => $matchedText,
                ],
                whyItMatters: $metadata->whyItMatters,
                introducedIn: $metadata->introducedIn,
                targetVersion: $targetVersion,
                migration: 'Replace `init app` with `new` in the documented command examples.',
                reference: $metadata->reference,
            );
        }

        $scannedFiles = array_values(array_unique($scannedFiles));
        sort($scannedFiles);

        return [
            'issues' => $issues,
            'scanned_files' => $scannedFiles,
        ];
    }

    /**
     * @return array<int,UpgradeIssue>
     */
    private function legacyProjectionFallbackIssues(string $targetVersion): array
    {
        $metadata = $this->deprecations->get('compiler.legacy_projection_fallback');
        if ($metadata === null || !$metadata->appliesTo($targetVersion)) {
            return [];
        }

        $issues = [];
        foreach ($this->extensions->projectionEmitters() as $emitter) {
            if (!$emitter instanceof ProjectionEmitter) {
                continue;
            }

            $legacyFile = trim((string) $emitter->legacyFileName());
            if ($legacyFile === '') {
                continue;
            }

            $buildPath = 'app/.foundry/build/projections/' . $emitter->fileName();
            $legacyPath = 'app/generated/' . $legacyFile;

            if (!is_file($this->paths->join($legacyPath)) || is_file($this->paths->join($buildPath))) {
                continue;
            }

            $issues[] = new UpgradeIssue(
                code: 'FDY1302_LEGACY_PROJECTION_FALLBACK',
                severity: $metadata->severity,
                category: $metadata->category,
                summary: sprintf('Legacy projection fallback detected for `%s`.', $legacyFile),
                affected: [
                    'legacy_projection' => $legacyPath,
                    'missing_build_projection' => $buildPath,
                    'emitter' => $emitter->id(),
                ],
                whyItMatters: $metadata->whyItMatters,
                introducedIn: $metadata->introducedIn,
                targetVersion: $targetVersion,
                migration: sprintf(
                    'Run `%s compile graph --json` so the build projection is regenerated before upgrading.',
                    $this->commandPrefix(),
                ),
                reference: $metadata->reference,
            );
        }

        return $issues;
    }

    private function commandPrefix(): string
    {
        return $this->paths->root() === $this->paths->frameworkRoot()
            ? 'php bin/foundry'
            : 'php vendor/bin/foundry';
    }

    /**
     * @param array<string,mixed> $diagnostic
     * @return array{why:string,migration:string}
     */
    private function compatibilityGuidance(array $diagnostic, string $targetVersion): array
    {
        $extension = trim((string) ($diagnostic['extension'] ?? ''));
        $pack = trim((string) ($diagnostic['pack'] ?? ''));
        $subject = $extension !== '' ? 'extension ' . $extension : ($pack !== '' ? 'pack ' . $pack : 'registered compatibility metadata');

        return match ((string) ($diagnostic['code'] ?? '')) {
            'FDY7001_INCOMPATIBLE_EXTENSION_VERSION', 'FDY7008_INCOMPATIBLE_PACK_VERSION' => [
                'why' => sprintf('The declared version constraints for %s do not include the target framework version %s.', $subject, $targetVersion),
                'migration' => sprintf('Upgrade %s to a release that supports Foundry %s, or remove it before the framework upgrade.', $subject, $targetVersion),
            ],
            'FDY7002_INCOMPATIBLE_GRAPH_VERSION' => [
                'why' => sprintf('The registered graph compatibility contract for %s does not match the graph/runtime surface used by the target upgrade.', $subject),
                'migration' => sprintf('Update %s so its graph compatibility metadata matches the target release, then rerun upgrade-check.', $subject),
            ],
            'FDY7009_PACK_CAPABILITY_MISSING' => [
                'why' => sprintf('%s depends on a capability that is not available in the installed extension set.', ucfirst($subject)),
                'migration' => sprintf('Install the required supporting extension/pack for %s, or remove the dependent pack before upgrading.', $subject),
            ],
            'FDY7006_CONFLICTING_NODE_PROVIDER', 'FDY7007_CONFLICTING_PROJECTION_PROVIDER', 'FDY7005_DUPLICATE_EXTENSION_ID' => [
                'why' => 'Conflicting extension registrations create ambiguous compiler behavior and are likely to become upgrade blockers.',
                'migration' => 'Resolve the duplicate or conflicting provider registration so the compiler has a single authoritative owner for each surface.',
            ],
            'FDY7014_EXTENSION_DEPENDENCY_MISSING' => [
                'why' => sprintf('%s depends on another extension that is not currently installed or enabled.', ucfirst($subject)),
                'migration' => sprintf('Install the missing dependency for %s or remove the dependent extension before upgrading.', $subject),
            ],
            default => [
                'why' => sprintf('The upgrade target %s exposes a compatibility issue in %s.', $targetVersion, $subject),
                'migration' => sprintf('Review the registered metadata for %s and update or remove it before upgrading.', $subject),
            ],
        };
    }

    /**
     * @param array<string,mixed> $scripts
     * @return array<string,string>
     */
    private function composerScriptStrings(array $scripts, string $prefix = ''): array
    {
        $rows = [];

        foreach ($scripts as $key => $value) {
            $name = $prefix !== '' ? $prefix . '.' . (string) $key : (string) $key;
            if (is_string($value)) {
                $rows[$name] = $value;
                continue;
            }

            if (is_array($value)) {
                $rows += $this->composerScriptStrings($value, $name);
            }
        }

        ksort($rows);

        return $rows;
    }

    private static function severityRank(string $severity): int
    {
        return match ($severity) {
            'error' => 0,
            'warning' => 1,
            default => 2,
        };
    }
}
