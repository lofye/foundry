<?php

declare(strict_types=1);

namespace Foundry\Context;

final class ExecutionSpecFilename
{
    public const ID_PATTERN = '\d{3}(?:\.\d{3})*';
    public const SLUG_PATTERN = '[a-z0-9]+(?:-[a-z0-9]+)*';
    public const NAME_PATTERN = '(?<name>(?<id>' . self::ID_PATTERN . ')-(?<slug>' . self::SLUG_PATTERN . '))';
    public const ACTIVE_PATH_PATTERN = '#^docs/features/(?<feature>[a-z0-9]+(?:-[a-z0-9]+)*)/specs/' . self::NAME_PATTERN . '\.md$#';
    public const DRAFT_PATH_PATTERN = '#^docs/features/(?<feature>[a-z0-9]+(?:-[a-z0-9]+)*)/specs/drafts/' . self::NAME_PATTERN . '\.md$#';
    public const ACTIVE_CANONICAL_PATH_PATTERN = '#^Features/(?<feature_dir>[A-Z][A-Za-z0-9]*)/specs/' . self::NAME_PATTERN . '\.md$#';
    public const DRAFT_CANONICAL_PATH_PATTERN = '#^Features/(?<feature_dir>[A-Z][A-Za-z0-9]*)/specs/drafts/' . self::NAME_PATTERN . '\.md$#';

    /**
     * @return array{
     *     name:string,
     *     id:string,
     *     slug:string,
     *     segments:list<int>,
     *     parent_id:?string
     * }|null
     */
    public static function parseName(string $name): ?array
    {
        if (preg_match('/^' . self::NAME_PATTERN . '$/', $name, $matches) !== 1) {
            return null;
        }

        $segments = array_values(array_map(
            static fn(string $segment): int => (int) $segment,
            explode('.', (string) $matches['id']),
        ));

        return [
            'name' => (string) $matches['name'],
            'id' => (string) $matches['id'],
            'slug' => (string) $matches['slug'],
            'segments' => $segments,
            'parent_id' => count($segments) > 1
                ? implode('.', array_map(
                    static fn(int $segment): string => sprintf('%03d', $segment),
                    array_slice($segments, 0, -1),
                ))
                : null,
        ];
    }

    public static function isCanonicalName(string $name): bool
    {
        return self::parseName($name) !== null;
    }

    public static function heading(string $name): string
    {
        return '# Execution Spec: ' . $name;
    }

    /**
     * @return array{
     *     feature:string,
     *     name:string,
     *     id:string,
     *     slug:string,
     *     segments:list<int>,
     *     parent_id:?string
     * }|null
     */
    public static function parseActivePath(string $relativePath): ?array
    {
        $legacy = self::parsePath($relativePath, self::ACTIVE_PATH_PATTERN);
        if ($legacy !== null) {
            return $legacy;
        }

        return self::parseCanonicalPath($relativePath, self::ACTIVE_CANONICAL_PATH_PATTERN);
    }

    /**
     * @return array{
     *     feature:string,
     *     name:string,
     *     id:string,
     *     slug:string,
     *     segments:list<int>,
     *     parent_id:?string
     * }|null
     */
    public static function parseDraftPath(string $relativePath): ?array
    {
        $legacy = self::parsePath($relativePath, self::DRAFT_PATH_PATTERN);
        if ($legacy !== null) {
            return $legacy;
        }

        return self::parseCanonicalPath($relativePath, self::DRAFT_CANONICAL_PATH_PATTERN);
    }

    /**
     * @return array{
     *     feature:string,
     *     name:string,
     *     id:string,
     *     slug:string,
     *     segments:list<int>,
     *     parent_id:?string
     * }|null
     */
    private static function parsePath(string $relativePath, string $pattern): ?array
    {
        if (preg_match($pattern, $relativePath, $matches) !== 1) {
            return null;
        }

        $name = self::parseName((string) $matches['name']);
        if ($name === null) {
            return null;
        }

        return [
            'feature' => (string) $matches['feature'],
            'name' => $name['name'],
            'id' => $name['id'],
            'slug' => $name['slug'],
            'segments' => $name['segments'],
            'parent_id' => $name['parent_id'],
        ];
    }

    /**
     * @return array{
     *     feature:string,
     *     name:string,
     *     id:string,
     *     slug:string,
     *     segments:list<int>,
     *     parent_id:?string
     * }|null
     */
    private static function parseCanonicalPath(string $relativePath, string $pattern): ?array
    {
        if (preg_match($pattern, $relativePath, $matches) !== 1) {
            return null;
        }

        $name = self::parseName((string) $matches['name']);
        if ($name === null) {
            return null;
        }

        return [
            'feature' => self::slugFromPascal((string) $matches['feature_dir']),
            'name' => $name['name'],
            'id' => $name['id'],
            'slug' => $name['slug'],
            'segments' => $name['segments'],
            'parent_id' => $name['parent_id'],
        ];
    }

    private static function slugFromPascal(string $value): string
    {
        $hyphenated = (string) preg_replace('/(?<!^)[A-Z]/', '-$0', $value);

        return strtolower($hyphenated);
    }
}
