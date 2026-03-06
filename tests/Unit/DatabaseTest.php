<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\DB\Connection;
use Foundry\DB\PdoQueryExecutor;
use Foundry\DB\QueryDefinition;
use Foundry\DB\QueryRegistry;
use Foundry\DB\TransactionManager;
use PHPUnit\Framework\TestCase;

final class DatabaseTest extends TestCase
{
    public function test_query_executor_select_and_execute(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE posts (id TEXT, title TEXT)');

        $registry = new QueryRegistry();
        $registry->register(new QueryDefinition('publish_post', 'insert_post', 'INSERT INTO posts (id, title) VALUES (:id, :title)', ['id', 'title']));
        $registry->register(new QueryDefinition('publish_post', 'list_posts', 'SELECT * FROM posts WHERE id = :id', ['id']));

        $executor = new PdoQueryExecutor(new Connection($pdo), $registry);

        $count = $executor->execute('publish_post', 'insert_post', ['id' => 'p1', 'title' => 'Hello']);
        $rows = $executor->select('publish_post', 'list_posts', ['id' => 'p1']);

        $this->assertSame(1, $count);
        $this->assertCount(1, $rows);
        $this->assertSame('Hello', $rows[0]['title']);
    }

    public function test_transaction_manager_begin_and_commit(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $tx = new TransactionManager(new Connection($pdo));

        $tx->begin();
        $this->assertTrue($tx->inTransaction());
        $tx->commit();
        $this->assertFalse($tx->inTransaction());
    }
}
