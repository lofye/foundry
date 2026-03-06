<?php
declare(strict_types=1);

namespace Forge\DB;

final class Connection
{
    public function __construct(private readonly \PDO $pdo)
    {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }
}
