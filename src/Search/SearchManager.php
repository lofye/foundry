<?php

declare(strict_types=1);

namespace Foundry\Search;

use Foundry\Support\FoundryError;

final class SearchManager
{
    /**
     * @var array<string,SearchAdapter>
     */
    private array $adapters = [];

    /**
     * @param array<int,SearchAdapter> $adapters
     */
    public function __construct(array $adapters = [])
    {
        foreach ($adapters as $adapter) {
            $this->adapters[$adapter->id()] = $adapter;
        }

        if ($this->adapters === []) {
            $sql = new SqlSearchAdapter();
            $meili = new MeilisearchAdapter();
            $this->adapters[$sql->id()] = $sql;
            $this->adapters[$meili->id()] = $meili;
        }

        ksort($this->adapters);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<int,string> $fields
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function query(string $adapter, array $rows, string $query, array $fields, array $filters = []): array
    {
        $instance = $this->adapters[strtolower($adapter)] ?? null;
        if (!$instance instanceof SearchAdapter) {
            throw new FoundryError('SEARCH_ADAPTER_UNKNOWN', 'search', ['adapter' => $adapter], 'Unknown search adapter.');
        }

        return $instance->search($rows, $query, $fields, $filters);
    }
}
