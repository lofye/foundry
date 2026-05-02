<?php

declare(strict_types=1);

namespace Foundry\Context;

use Foundry\Support\FoundryError;
use Foundry\Support\Paths;

final class ExecutionSpecCatalog
{
    public function __construct(
        private readonly Paths $paths,
    ) {}

    /**
     * @return list<array{
     *     feature:string,
     *     status:string,
     *     path:string,
     *     name:string,
     *     id:string,
     *     slug:string,
     *     segments:list<int>,
     *     parent_id:?string
     * }>
     */
    public function entries(string $featureName): array
    {
        $entries = [];
        $ids = [];
        $invalidPaths = [];

        foreach ($this->featureDirectories($featureName) as $status => $relativeDirectory) {
            $absoluteDirectory = $this->paths->join($relativeDirectory);
            if (!file_exists($absoluteDirectory)) {
                continue;
            }

            if (!is_dir($absoluteDirectory)) {
                throw new FoundryError(
                    'EXECUTION_SPEC_ID_ALLOCATION_FAILED',
                    'validation',
                    ['feature' => $featureName, 'blocked_path' => $relativeDirectory],
                    'Execution spec allocation cannot proceed deterministically.',
                );
            }

            $matches = glob($absoluteDirectory . '/*.md') ?: [];
            sort($matches);

            foreach ($matches as $match) {
                if (!is_file($match)) {
                    continue;
                }

                $relativePath = $this->relativePath($match);
                if ($relativePath === null) {
                    continue;
                }

                $parsedName = ExecutionSpecFilename::parseName(basename($relativePath, '.md'));
                if ($parsedName === null) {
                    $invalidPaths[] = $relativePath;

                    continue;
                }

                $entries[] = [
                    'feature' => $featureName,
                    'status' => $status,
                    'path' => $relativePath,
                    'name' => $parsedName['name'],
                    'id' => $parsedName['id'],
                    'slug' => $parsedName['slug'],
                    'segments' => $parsedName['segments'],
                    'parent_id' => $parsedName['parent_id'],
                ];

                $ids[$parsedName['id']][] = $relativePath;
            }
        }

        if ($invalidPaths !== []) {
            sort($invalidPaths);

            throw new FoundryError(
                'EXECUTION_SPEC_ID_ALLOCATION_FAILED',
                'validation',
                ['feature' => $featureName, 'invalid_paths' => $invalidPaths],
                'Execution spec allocation cannot proceed deterministically.',
            );
        }

        $duplicateIds = [];
        foreach ($ids as $id => $paths) {
            if (count($paths) < 2) {
                continue;
            }

            sort($paths);
            $duplicateIds[$id] = $paths;
        }

        if ($duplicateIds !== []) {
            ksort($duplicateIds);

            throw new FoundryError(
                'EXECUTION_SPEC_ID_ALLOCATION_FAILED',
                'validation',
                ['feature' => $featureName, 'duplicate_ids' => $duplicateIds],
                'Execution spec allocation cannot proceed deterministically.',
            );
        }

        usort($entries, static function (array $left, array $right): int {
            return strcmp((string) $left['path'], (string) $right['path']);
        });

        return $entries;
    }

    public function nextRootId(string $featureName): string
    {
        $entries = $this->entries($featureName);
        $this->assertContiguous($featureName, $entries);

        $topLevels = [];
        foreach ($entries as $entry) {
            $topLevels[(int) ($entry['segments'][0] ?? 0)] = true;
        }

        $next = 1;
        while (isset($topLevels[$next])) {
            $next++;
        }

        return sprintf('%03d', $next);
    }

    /**
     * @param list<array{
     *     feature:string,
     *     status:string,
     *     path:string,
     *     name:string,
     *     id:string,
     *     slug:string,
     *     segments:list<int>,
     *     parent_id:?string
     * }> $entries
     */
    public function assertContiguous(string $featureName, array $entries): void
    {
        $continuity = new ExecutionSpecIdContinuity();
        $byLocation = ['active' => [], 'drafts' => []];
        foreach ($entries as $entry) {
            $status = (string) ($entry['status'] ?? '');
            $location = $status === 'draft' ? 'drafts' : 'active';
            $byLocation[$location][] = [
                'id' => (string) $entry['id'],
                'segments' => (array) $entry['segments'],
                'path' => (string) $entry['path'],
            ];
        }

        foreach ($byLocation as $location => $locationEntries) {
            $gaps = $continuity->gaps($locationEntries);
            if ($gaps === []) {
                continue;
            }

            $firstGap = $gaps[0];
            throw new FoundryError(
                'EXECUTION_SPEC_ID_SEQUENCE_INVALID',
                'validation',
                [
                    'feature' => $featureName,
                    'location' => $location,
                    'parent_id' => (string) ($firstGap['parent_id'] ?? 'top-level'),
                    'missing_id' => (string) $firstGap['missing_id'],
                    'expected_missing_id' => (string) $firstGap['missing_id'],
                    'next_observed_id' => (string) $firstGap['next_observed_id'],
                    'path' => (string) $firstGap['path'],
                    'gaps' => $gaps,
                ],
                'Execution spec IDs must be contiguous. Skipping numbers violates execution-spec-system rules.',
            );
        }
    }

    /**
     * @return array<string,string>
     */
    private function featureDirectories(string $featureName): array
    {
        return [
            'active' => 'docs/features/' . $featureName . '/specs',
            'draft' => 'docs/features/' . $featureName . '/specs/drafts',
        ];
    }

    private function relativePath(string $absolutePath): ?string
    {
        $root = rtrim($this->paths->root(), '/');
        if (!str_starts_with($absolutePath, $root . '/')) {
            return null;
        }

        return substr($absolutePath, strlen($root) + 1);
    }
}
