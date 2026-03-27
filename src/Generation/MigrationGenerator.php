<?php

declare(strict_types=1);

namespace Foundry\Generation;

use Foundry\Support\Yaml;

final class MigrationGenerator
{
    public function generate(string $definitionPath, string $outputDir): string
    {
        $definition = Yaml::parseFile($definitionPath);
        $name = (string) ($definition['name'] ?? 'migration');
        $table = (string) ($definition['table'] ?? 'table_name');

        $timestamp = gmdate('YmdHis');
        $filename = $timestamp . '_' . $name . '.sql';
        $fullPath = rtrim($outputDir, '/') . '/' . $filename;

        $content = "-- GENERATED FILE - DO NOT EDIT DIRECTLY\n";
        $content .= "-- Source: {$definitionPath}\n";
        $content .= "-- Regenerate with: foundry generate migration {$definitionPath}\n\n";
        $content .= "CREATE TABLE IF NOT EXISTS {$table} (\n    id TEXT PRIMARY KEY\n);\n";

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        file_put_contents($fullPath, $content);

        return $fullPath;
    }
}
