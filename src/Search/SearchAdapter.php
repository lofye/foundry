<?php

declare(strict_types=1);

namespace Foundry\Search;

interface SearchAdapter
{
    public function id(): string;

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<int,string> $fields
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function search(array $rows, string $query, array $fields, array $filters = []): array;
}
