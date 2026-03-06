<?php
declare(strict_types=1);

namespace Forge\DB;

use Forge\Support\ForgeError;

final class DatabaseManager
{
    /**
     * @var array<string,Connection>
     */
    private array $connections = [];

    public function addConnection(string $name, Connection $connection): void
    {
        $this->connections[$name] = $connection;
    }

    public function connection(string $name = 'default'): Connection
    {
        if (!isset($this->connections[$name])) {
            throw new ForgeError('DB_CONNECTION_NOT_FOUND', 'not_found', ['connection' => $name], 'Database connection not found.');
        }

        return $this->connections[$name];
    }
}
