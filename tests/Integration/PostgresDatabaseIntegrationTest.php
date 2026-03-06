<?php
declare(strict_types=1);

namespace Foundry\Tests\Integration;

use Foundry\DB\Connection;
use Foundry\DB\PdoQueryExecutor;
use Foundry\DB\QueryDefinition;
use Foundry\DB\QueryRegistry;
use Foundry\DB\TransactionManager;
use PHPUnit\Framework\TestCase;

final class PostgresDatabaseIntegrationTest extends TestCase
{
    public function test_query_executor_select_and_execute_against_postgres(): void
    {
        $pdo = $this->postgresConnection();
        $table = 'foundry_posts_' . bin2hex(random_bytes(4));
        $pdo->exec("CREATE TEMP TABLE {$table} (id TEXT PRIMARY KEY, title TEXT NOT NULL)");

        $registry = new QueryRegistry();
        $registry->register(new QueryDefinition(
            'publish_post',
            'insert_post',
            "INSERT INTO {$table} (id, title) VALUES (:id, :title)",
            ['id', 'title'],
        ));
        $registry->register(new QueryDefinition(
            'publish_post',
            'select_post',
            "SELECT id, title FROM {$table} WHERE id = :id",
            ['id'],
        ));

        $executor = new PdoQueryExecutor(new Connection($pdo), $registry);
        $count = $executor->execute('publish_post', 'insert_post', ['id' => 'p1', 'title' => 'Hello PG']);
        $rows = $executor->select('publish_post', 'select_post', ['id' => 'p1']);

        $this->assertSame(1, $count);
        $this->assertCount(1, $rows);
        $this->assertSame('Hello PG', $rows[0]['title']);
    }

    public function test_transaction_manager_rolls_back_against_postgres(): void
    {
        $pdo = $this->postgresConnection();
        $table = 'foundry_tx_' . bin2hex(random_bytes(4));
        $pdo->exec("CREATE TEMP TABLE {$table} (id INTEGER PRIMARY KEY)");

        $tx = new TransactionManager(new Connection($pdo));
        $tx->begin();
        $pdo->exec("INSERT INTO {$table} (id) VALUES (1)");
        $this->assertTrue($tx->inTransaction());

        $tx->rollBack();
        $this->assertFalse($tx->inTransaction());

        $count = (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
        $this->assertSame(0, $count);
    }

    private function postgresConnection(): \PDO
    {
        if (!in_array('pgsql', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_pgsql driver is not available.');
            throw new \RuntimeException('Unreachable');
        }

        $dsn = (string) (getenv('FOUNDRY_TEST_PG_DSN') ?: 'pgsql:host=127.0.0.1;port=5432;dbname=postgres');
        $user = getenv('FOUNDRY_TEST_PG_USER');
        $password = getenv('FOUNDRY_TEST_PG_PASS');

        try {
            return new \PDO(
                $dsn,
                $user !== false ? $user : null,
                $password !== false ? $password : null,
            );
        } catch (\PDOException $e) {
            self::markTestSkipped('PostgreSQL is not reachable: ' . $e->getMessage());
            throw new \RuntimeException('Unreachable');
        }
    }
}
