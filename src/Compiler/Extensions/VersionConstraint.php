<?php
declare(strict_types=1);

namespace Foundry\Compiler\Extensions;

final class VersionConstraint
{
    public static function matches(string $version, string $constraint): bool
    {
        $constraint = trim($constraint);
        if ($constraint === '' || $constraint === '*') {
            return true;
        }

        if ($version === '' || $version === 'dev-main') {
            return $constraint === 'dev-main' || $constraint === '*';
        }

        if (str_starts_with($constraint, '^')) {
            $base = substr($constraint, 1);
            $baseParts = self::parts($base);
            $versionParts = self::parts($version);

            if ($baseParts === null || $versionParts === null) {
                return false;
            }

            [$baseMajor, $baseMinor, $basePatch] = $baseParts;
            [$major, $minor, $patch] = $versionParts;

            if ($major !== $baseMajor) {
                return false;
            }

            if ($baseMajor === 0 && $minor !== $baseMinor) {
                return false;
            }

            return self::compare([$major, $minor, $patch], [$baseMajor, $baseMinor, $basePatch]) >= 0;
        }

        $constraintParts = self::parts($constraint);
        $versionParts = self::parts($version);
        if ($constraintParts === null || $versionParts === null) {
            return $version === $constraint;
        }

        return self::compare($versionParts, $constraintParts) === 0;
    }

    /**
     * @return array{0:int,1:int,2:int}|null
     */
    private static function parts(string $version): ?array
    {
        if (!preg_match('/^(\d+)(?:\.(\d+))?(?:\.(\d+))?/', $version, $matches)) {
            return null;
        }

        return [
            (int) ($matches[1] ?? 0),
            (int) ($matches[2] ?? 0),
            (int) ($matches[3] ?? 0),
        ];
    }

    /**
     * @param array{0:int,1:int,2:int} $a
     * @param array{0:int,1:int,2:int} $b
     */
    private static function compare(array $a, array $b): int
    {
        return ($a[0] <=> $b[0]) ?: (($a[1] <=> $b[1]) ?: ($a[2] <=> $b[2]));
    }
}
