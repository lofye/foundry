<?php
declare(strict_types=1);

namespace Forge\Generation;

use Forge\Support\Yaml;

final class MigrationGenerator
{
    public function generate(string $specPath, string $outputDir): string
    {
        $spec = Yaml::parseFile($specPath);
        $name = (string) ($spec['name'] ?? 'migration');
        $table = (string) ($spec['table'] ?? 'table_name');

        $timestamp = gmdate('YmdHis');
        $filename = $timestamp . '_' . $name . '.sql';
        $fullPath = rtrim($outputDir, '/') . '/' . $filename;

        $content = "-- GENERATED FILE - DO NOT EDIT DIRECTLY\n";
        $content .= "-- Source: {$specPath}\n";
        $content .= "-- Regenerate with: forge generate migration {$specPath}\n\n";
        $content .= "CREATE TABLE IF NOT EXISTS {$table} (\n    id TEXT PRIMARY KEY\n);\n";

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        file_put_contents($fullPath, $content);

        return $fullPath;
    }
}
