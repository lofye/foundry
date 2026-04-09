<?php

declare(strict_types=1);

namespace Foundry\Support;

final class FeatureNaming
{
    public static function canonical(string $feature): string
    {
        return str_replace('_', '-', $feature);
    }

    public static function codeSafe(string $feature): string
    {
        return Str::toSnakeCase(self::canonical($feature));
    }

    public static function directory(string $feature): string
    {
        return 'app/features/' . self::canonical($feature);
    }
}
