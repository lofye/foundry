<?php

declare(strict_types=1);

namespace Foundry\Support;

final class Str
{
    public static function toSnakeCase(string $value): string
    {
        $value = preg_replace('/([a-z])([A-Z])/', '$1_$2', $value) ?? $value;
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9_]+/', '_', $value) ?? $value;
        $value = trim($value, '_');
        $value = preg_replace('/_+/', '_', $value) ?? $value;

        return $value;
    }

    public static function isSnakeCase(string $value): bool
    {
        return (bool) preg_match('/^[a-z][a-z0-9_]*$/', $value);
    }

    public static function studly(string $value): string
    {
        $value = self::toSnakeCase($value);
        $parts = explode('_', $value);
        $parts = array_map(static fn(string $part): string => ucfirst($part), $parts);

        return implode('', $parts);
    }
}
