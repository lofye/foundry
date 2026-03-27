<?php
declare(strict_types=1);

namespace Foundry\Upgrade;

final class VersionComparator
{
    public static function isValid(string $version): bool
    {
        return $version === 'dev-main' || preg_match('/^\d+(?:\.\d+){0,2}$/', $version) === 1;
    }

    public static function compare(string $left, string $right): int
    {
        if ($left === $right) {
            return 0;
        }

        if ($left === 'dev-main') {
            return 1;
        }

        if ($right === 'dev-main') {
            return -1;
        }

        $leftParts = self::parts($left);
        $rightParts = self::parts($right);

        if ($leftParts === null || $rightParts === null) {
            return strcmp($left, $right);
        }

        return ($leftParts[0] <=> $rightParts[0])
            ?: (($leftParts[1] <=> $rightParts[1]) ?: ($leftParts[2] <=> $rightParts[2]));
    }

    /**
     * @return array{0:int,1:int,2:int}|null
     */
    private static function parts(string $version): ?array
    {
        if (preg_match('/^(\d+)(?:\.(\d+))?(?:\.(\d+))?$/', $version, $matches) !== 1) {
            return null;
        }

        return [
            (int) ($matches[1] ?? 0),
            (int) ($matches[2] ?? 0),
            (int) ($matches[3] ?? 0),
        ];
    }
}
