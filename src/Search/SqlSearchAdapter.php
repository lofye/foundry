<?php
declare(strict_types=1);

namespace Foundry\Search;

final class SqlSearchAdapter implements SearchAdapter
{
    public function id(): string
    {
        return 'sql';
    }

    public function search(array $rows, string $query, array $fields, array $filters = []): array
    {
        $query = strtolower(trim($query));

        $matches = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            foreach ($filters as $field => $expected) {
                if ((string) ($row[$field] ?? '') !== (string) $expected) {
                    continue 2;
                }
            }

            if ($query === '') {
                $matches[] = $row;
                continue;
            }

            foreach ($fields as $field) {
                $value = strtolower((string) ($row[$field] ?? ''));
                if ($value !== '' && str_contains($value, $query)) {
                    $matches[] = $row;
                    break;
                }
            }
        }

        return $matches;
    }
}
