<?php
declare(strict_types=1);

namespace Foundry\DB;

interface QueryExecutor
{
    /**
     * @param array<string,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    public function select(string $feature, string $queryName, array $params = []): array;

    /**
     * @param array<string,mixed> $params
     */
    public function execute(string $feature, string $queryName, array $params = []): int;
}
