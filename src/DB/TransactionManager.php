<?php

declare(strict_types=1);

namespace Foundry\DB;

final class TransactionManager
{
    private int $depth = 0;

    public function __construct(private readonly Connection $connection) {}

    public function begin(): void
    {
        if ($this->depth === 0) {
            $this->connection->pdo()->beginTransaction();
        }

        $this->depth++;
    }

    public function commit(): void
    {
        if ($this->depth <= 0) {
            return;
        }

        $this->depth--;
        if ($this->depth === 0 && $this->connection->pdo()->inTransaction()) {
            $this->connection->pdo()->commit();
        }
    }

    public function rollBack(): void
    {
        if ($this->connection->pdo()->inTransaction()) {
            $this->connection->pdo()->rollBack();
        }

        $this->depth = 0;
    }

    public function inTransaction(): bool
    {
        return $this->connection->pdo()->inTransaction();
    }
}
