<?php

declare(strict_types=1);

namespace Foundry\Context;

final class ExecutionSpecIdContinuity
{
    /**
     * @param list<array{
     *     id:string,
     *     segments:list<int>,
     *     path:string
     * }> $entries
     * @return list<array{
     *     missing_id:string,
     *     next_observed_id:string,
     *     path:string,
     *     parent_id:string
     * }>
     */
    public function gaps(array $entries): array
    {
        $groups = [];
        $idSeen = [];
        $pathsById = [];

        foreach ($entries as $entry) {
            $id = (string) ($entry['id'] ?? '');
            $segments = $entry['segments'] ?? [];
            $path = (string) ($entry['path'] ?? '');

            if ($id === '' || !is_array($segments) || $segments === []) {
                continue;
            }

            if (isset($idSeen[$id])) {
                continue;
            }

            $idSeen[$id] = true;
            $pathsById[$id] = $path;
            $parent = count($segments) > 1
                ? implode('.', array_map(static fn(int $segment): string => sprintf('%03d', $segment), array_slice($segments, 0, -1)))
                : '';

            $groups[$parent][] = [
                'id' => $id,
                'ordinal' => (int) $segments[count($segments) - 1],
            ];
        }

        $gaps = [];
        ksort($groups);

        foreach ($groups as $parentId => $siblings) {
            usort($siblings, static fn(array $left, array $right): int => $left['ordinal'] <=> $right['ordinal']);

            if ($parentId !== '' && !isset($idSeen[$parentId])) {
                $next = (string) $siblings[0]['id'];
                $gaps[] = [
                    'missing_id' => $parentId,
                    'next_observed_id' => $next,
                    'path' => (string) ($pathsById[$next] ?? ''),
                    'parent_id' => $parentId === '' ? 'top-level' : $parentId,
                ];
                continue;
            }

            $expected = (int) ($siblings[0]['ordinal'] ?? 1);
            foreach ($siblings as $sibling) {
                $observed = (int) $sibling['ordinal'];
                if ($observed < $expected) {
                    continue;
                }

                if ($observed > $expected) {
                    $missingId = $parentId === ''
                        ? sprintf('%03d', $expected)
                        : $parentId . '.' . sprintf('%03d', $expected);
                    $next = (string) $sibling['id'];
                    $gaps[] = [
                        'missing_id' => $missingId,
                        'next_observed_id' => $next,
                        'path' => (string) ($pathsById[$next] ?? ''),
                        'parent_id' => $parentId === '' ? 'top-level' : $parentId,
                    ];
                }

                $expected = $observed + 1;
            }
        }

        usort($gaps, static function (array $left, array $right): int {
            return strcmp(
                (string) (($left['path'] ?? '') . "\n" . ($left['missing_id'] ?? '')),
                (string) (($right['path'] ?? '') . "\n" . ($right['missing_id'] ?? '')),
            );
        });

        return $gaps;
    }
}
