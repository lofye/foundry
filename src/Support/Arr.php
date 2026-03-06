<?php
declare(strict_types=1);

namespace Forge\Support;

final class Arr
{
    /**
     * @param array<string,mixed> $array
     */
    public static function get(array $array, string $key, mixed $default = null): mixed
    {
        if ($key === '') {
            return $default;
        }

        $segments = explode('.', $key);
        $current = $array;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * @param array<string,mixed> $array
     * @return array<string,mixed>
     */
    public static function only(array $array, array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $array)) {
                $result[$key] = $array[$key];
            }
        }

        return $result;
    }

    /**
     * @param array<int,mixed> $items
     * @return array<int,mixed>
     */
    public static function unique(array $items): array
    {
        return array_values(array_unique($items, SORT_REGULAR));
    }
}
