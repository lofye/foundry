<?php
declare(strict_types=1);

namespace Forge\Config;

final class EnvLoader
{
    /**
     * @return array<string,string>
     */
    public function load(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $vars = [];
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $key = trim($parts[0]);
            $value = trim($parts[1]);
            $value = trim($value, "\"'");
            $vars[$key] = $value;
        }

        return $vars;
    }
}
