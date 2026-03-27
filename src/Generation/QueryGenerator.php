<?php

declare(strict_types=1);

namespace Foundry\Generation;

final class QueryGenerator
{
    /**
     * @param array<int,string> $queries
     */
    public function generate(array $queries): string
    {
        $queries = array_values(array_unique(array_map('strval', $queries)));
        sort($queries);

        $chunks = [];
        foreach ($queries as $query) {
            $chunks[] = '-- name: ' . $query;
            $chunks[] = 'SELECT 1;';
            $chunks[] = '';
        }

        return implode("\n", $chunks);
    }
}
