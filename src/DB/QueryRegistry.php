<?php
declare(strict_types=1);

namespace Foundry\DB;

use Foundry\Support\FoundryError;

final class QueryRegistry
{
    /**
     * @var array<string,QueryDefinition>
     */
    private array $queries = [];

    public function register(QueryDefinition $query): void
    {
        $key = $this->key($query->feature, $query->name);
        $this->queries[$key] = $query;
    }

    public function has(string $feature, string $queryName): bool
    {
        return isset($this->queries[$this->key($feature, $queryName)]);
    }

    public function get(string $feature, string $queryName): QueryDefinition
    {
        $key = $this->key($feature, $queryName);
        if (!isset($this->queries[$key])) {
            throw new FoundryError('QUERY_NOT_FOUND', 'not_found', ['feature' => $feature, 'query' => $queryName], 'Query not found.');
        }

        return $this->queries[$key];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function signatures(): array
    {
        $rows = [];
        foreach ($this->queries as $query) {
            $rows[] = $query->signature();
        }

        usort(
            $rows,
            static fn (array $a, array $b): int => ($a['feature'] <=> $b['feature']) ?: ($a['name'] <=> $b['name'])
        );

        return $rows;
    }

    private function key(string $feature, string $queryName): string
    {
        return $feature . '::' . $queryName;
    }
}
