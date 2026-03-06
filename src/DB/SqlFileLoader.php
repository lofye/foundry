<?php
declare(strict_types=1);

namespace Forge\DB;

use Forge\Support\ForgeError;

final class SqlFileLoader
{
    /**
     * @return array<int,QueryDefinition>
     */
    public function load(string $feature, string $path): array
    {
        if (!is_file($path)) {
            throw new ForgeError('SQL_FILE_NOT_FOUND', 'not_found', ['path' => $path], 'SQL file not found.');
        }

        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new ForgeError('SQL_FILE_READ_ERROR', 'io', ['path' => $path], 'Failed to read SQL file.');
        }

        return $this->parse($feature, $sql);
    }

    /**
     * @return array<int,QueryDefinition>
     */
    public function parse(string $feature, string $sql): array
    {
        $pattern = '/--\s*name:\s*([a-z0-9_]+)\s*\n(.*?)(?=(?:\n--\s*name:)|\z)/si';
        preg_match_all($pattern, $sql, $matches, PREG_SET_ORDER);

        $queries = [];
        $seen = [];
        foreach ($matches as $match) {
            $name = trim((string) $match[1]);
            $body = trim((string) $match[2]);
            if ($name === '' || $body === '') {
                continue;
            }

            if (isset($seen[$name])) {
                throw new ForgeError('DUPLICATE_QUERY_NAME', 'validation', ['feature' => $feature, 'query' => $name], 'Duplicate query name.');
            }

            $seen[$name] = true;
            preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)/', $body, $placeholderMatches);
            $placeholders = array_values(array_unique($placeholderMatches[1] ?? []));

            $queries[] = new QueryDefinition($feature, $name, $body, $placeholders);
        }

        return $queries;
    }
}
