<?php

declare(strict_types=1);

namespace Foundry\Search;

final class MeilisearchAdapter implements SearchAdapter
{
    public function id(): string
    {
        return 'meilisearch';
    }

    public function search(array $rows, string $query, array $fields, array $filters = []): array
    {
        // Use SQL-like fallback semantics for deterministic local tests.
        return (new SqlSearchAdapter())->search($rows, $query, $fields, $filters);
    }
}
