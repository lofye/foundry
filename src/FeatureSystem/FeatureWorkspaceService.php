<?php

declare(strict_types=1);

namespace Foundry\FeatureSystem;

use Foundry\Support\FeatureNaming;
use Foundry\Support\FoundryError;
use Foundry\Support\Paths;

final class FeatureWorkspaceService
{
    public function __construct(private readonly Paths $paths) {}

    /**
     * @return array{features:list<array<string,mixed>>,duplicates:list<array<string,mixed>>}
     */
    public function scan(): array
    {
        $rows = [];
        $duplicates = [];

        foreach ($this->canonicalFeatures() as $feature) {
            $slug = $feature['slug'];
            $rows[$slug] = $feature;
        }

        foreach ($this->legacyFeatures() as $feature) {
            $slug = $feature['slug'];
            if (isset($rows[$slug])) {
                if ((bool) ($rows[$slug]['has_context'] ?? false) && (bool) ($feature['has_context'] ?? false)) {
                    $rows[$slug]['legacy_path'] = $feature['legacy_path'];
                    $duplicates[] = [
                        'code' => 'FEATURE_DUPLICATE_CANONICAL_AND_LEGACY',
                        'feature' => $slug,
                        'canonical_path' => $rows[$slug]['path'],
                        'legacy_path' => $feature['legacy_path'],
                    ];
                }
                continue;
            }

            $rows[$slug] = $feature;
        }

        ksort($rows);

        return [
            'features' => array_values($rows),
            'duplicates' => $duplicates,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function list(): array
    {
        $scan = $this->scan();
        $enforced = $this->boundaryEnforced();

        return [
            'features' => array_values(array_map(
                static fn(array $row): array => [
                    'slug' => $row['slug'],
                    'name' => $row['name'],
                    'path' => $row['path'],
                    'has_context' => $row['has_context'],
                    'has_specs' => $row['has_specs'],
                    'has_src' => $row['has_src'],
                    'has_tests' => $row['has_tests'],
                    'boundary_enforced' => $enforced,
                ],
                $scan['features'],
            )),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function inspect(string $feature): array
    {
        $feature = FeatureNaming::canonical($feature);
        $scan = $this->scan();

        foreach ((array) $scan['features'] as $row) {
            if (!is_array($row) || (string) ($row['slug'] ?? '') !== $feature) {
                continue;
            }

            return [
                'feature' => [
                    'slug' => $row['slug'],
                    'name' => $row['name'],
                    'path' => $row['path'],
                    'context' => $row['context'],
                    'directories' => $row['directories'],
                    'dependencies' => $this->dependencies($row),
                ],
            ];
        }

        throw new FoundryError('FEATURE_UNKNOWN', 'not_found', ['feature' => $feature], 'Feature not found in canonical or legacy workspace.');
    }

    /**
     * @return array<string,mixed>
     */
    public function map(): array
    {
        $scan = $this->scan();

        $features = [];
        foreach ((array) $scan['features'] as $row) {
            if (!is_array($row)) {
                continue;
            }

            $owned = $this->ownedPaths((string) $row['path']);
            $features[] = [
                'slug' => $row['slug'],
                'owned_paths' => $owned,
                'shared_glue_paths' => [],
            ];
        }

        usort($features, static fn(array $a, array $b): int => strcmp((string) $a['slug'], (string) $b['slug']));

        return [
            'features' => $features,
            'unowned_paths' => [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function verify(): array
    {
        $scan = $this->scan();
        $enforced = $this->boundaryEnforced();

        $violations = [];
        foreach ((array) $scan['duplicates'] as $duplicate) {
            if (!is_array($duplicate)) {
                continue;
            }

            $violations[] = [
                'code' => (string) ($duplicate['code'] ?? 'FEATURE_DUPLICATE_CANONICAL_AND_LEGACY'),
                'feature' => (string) ($duplicate['feature'] ?? ''),
                'path' => (string) ($duplicate['canonical_path'] ?? ''),
                'message' => 'Feature exists in both canonical and legacy locations.',
                'details' => [
                    'legacy_path' => (string) ($duplicate['legacy_path'] ?? ''),
                ],
            ];
        }

        if ($enforced) {
            foreach ((array) $scan['features'] as $row) {
                if (!is_array($row)) {
                    continue;
                }

                if ((bool) ($row['has_context'] ?? false)) {
                    continue;
                }

                $violations[] = [
                    'code' => 'FEATURE_MISSING_CONTEXT',
                    'feature' => (string) ($row['slug'] ?? ''),
                    'path' => (string) ($row['path'] ?? ''),
                    'message' => 'Feature is missing canonical context files.',
                ];
            }
        }

        $warnings = [];
        if (!$enforced) {
            $warnings[] = [
                'code' => 'FEATURE_BOUNDARY_ENFORCEMENT_DISABLED',
                'message' => 'Feature boundary enforcement is disabled. This is not recommended.',
            ];
        }

        return [
            'status' => ($violations === []) ? 'ok' : 'failed',
            'enforcement' => $enforced ? 'enabled' : 'disabled',
            'violations' => $violations,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function canonicalFeatures(): array
    {
        $root = $this->paths->join('Features');
        if (!is_dir($root)) {
            return [];
        }

        $items = scandir($root) ?: [];
        $rows = [];

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $root . '/' . $item;
            if (!is_dir($path)) {
                continue;
            }

            $slug = $this->detectSlug($path, $item);
            $rows[] = $this->row(
                slug: $slug,
                name: $item,
                relativePath: 'Features/' . $item,
                isCanonical: true,
            );
        }

        usort($rows, static fn(array $a, array $b): int => strcmp((string) $a['slug'], (string) $b['slug']));

        return $rows;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function legacyFeatures(): array
    {
        $root = $this->paths->join('docs/features');
        if (!is_dir($root)) {
            return [];
        }

        $items = scandir($root) ?: [];
        $rows = [];

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $root . '/' . $item;
            if (!is_dir($path)) {
                continue;
            }

            if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $item)) {
                continue;
            }

            $rows[] = $this->row(
                slug: $item,
                name: $this->pascalFromSlug($item),
                relativePath: 'docs/features/' . $item,
                isCanonical: false,
            );
        }

        usort($rows, static fn(array $a, array $b): int => strcmp((string) $a['slug'], (string) $b['slug']));

        return $rows;
    }

    /**
     * @return array<string,mixed>
     */
    private function row(string $slug, string $name, string $relativePath, bool $isCanonical): array
    {
        $contextBase = $isCanonical
            ? $relativePath . '/' . $slug
            : $relativePath . '/' . $slug;

        $context = [
            'spec' => $contextBase . '.spec.md',
            'state' => $contextBase . '.md',
            'decisions' => $contextBase . '.decisions.md',
        ];

        $directories = [
            'specs' => $relativePath . '/specs',
            'plans' => $relativePath . '/plans',
            'docs' => $relativePath . '/docs',
            'src' => $relativePath . '/src',
            'tests' => $relativePath . '/tests',
        ];

        return [
            'slug' => $slug,
            'name' => $name,
            'path' => $relativePath,
            'legacy_path' => $isCanonical ? null : $relativePath,
            'has_context' => $this->allFilesExist($context),
            'has_specs' => is_dir($this->paths->join($directories['specs'])),
            'has_src' => is_dir($this->paths->join($directories['src'])),
            'has_tests' => is_dir($this->paths->join($directories['tests'])),
            'context' => $context,
            'directories' => $directories,
        ];
    }

    /**
     * @param array<string,string> $paths
     */
    private function allFilesExist(array $paths): bool
    {
        foreach ($paths as $path) {
            if (!is_file($this->paths->join($path))) {
                return false;
            }
        }

        return true;
    }

    private function detectSlug(string $absolutePath, string $name): string
    {
        $pattern = $absolutePath . '/*.spec.md';
        $matches = glob($pattern) ?: [];
        sort($matches);

        if ($matches !== []) {
            $file = basename((string) $matches[0]);

            return substr($file, 0, -strlen('.spec.md'));
        }

        return FeatureNaming::canonical(strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $name) ?? $name));
    }

    /**
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private function dependencies(array $row): array
    {
        $manifestPath = $row['path'] . '/feature.json';
        $absolute = $this->paths->join($manifestPath);
        if (!is_file($absolute)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($absolute), true);
        if (!is_array($decoded)) {
            return [];
        }

        $dependencies = array_values(array_unique(array_map('strval', (array) ($decoded['dependencies'] ?? []))));
        sort($dependencies);

        return $dependencies;
    }

    /**
     * @return list<string>
     */
    private function ownedPaths(string $relativeRoot): array
    {
        $absoluteRoot = $this->paths->join($relativeRoot);
        if (!is_dir($absoluteRoot)) {
            return [];
        }

        $paths = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($absoluteRoot, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof \SplFileInfo || !$fileInfo->isFile()) {
                continue;
            }

            $absolute = $fileInfo->getPathname();
            $root = rtrim($this->paths->root(), '/') . '/';
            if (!str_starts_with($absolute, $root)) {
                continue;
            }

            $paths[] = substr($absolute, strlen($root));
        }

        sort($paths);

        return array_values(array_unique($paths));
    }

    private function boundaryEnforced(): bool
    {
        $configPhp = $this->paths->join('config/foundry/features.php');
        if (is_file($configPhp)) {
            $value = require $configPhp;
            if (is_array($value) && is_array($value['features'] ?? null)) {
                return (bool) (($value['features']['enforce_boundaries'] ?? true));
            }

            if (is_array($value)) {
                return (bool) ($value['enforce_boundaries'] ?? true);
            }
        }

        $configJson = $this->paths->join('.foundry/config/features.json');
        if (is_file($configJson)) {
            $decoded = json_decode((string) file_get_contents($configJson), true);
            if (is_array($decoded)) {
                return (bool) ($decoded['enforce_boundaries'] ?? true);
            }
        }

        return true;
    }

    private function pascalFromSlug(string $slug): string
    {
        $parts = array_filter(explode('-', $slug), static fn(string $part): bool => $part !== '');

        return implode('', array_map(static fn(string $part): string => ucfirst($part), $parts));
    }
}
