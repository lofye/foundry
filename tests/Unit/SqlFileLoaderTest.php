<?php
declare(strict_types=1);

namespace Forge\Tests\Unit;

use Forge\DB\SqlFileLoader;
use Forge\Support\ForgeError;
use PHPUnit\Framework\TestCase;

final class SqlFileLoaderTest extends TestCase
{
    public function test_parses_named_queries_and_placeholders(): void
    {
        $sql = <<<'SQL'
-- name: find_user_by_id
SELECT * FROM users WHERE id = :id;

-- name: insert_post
INSERT INTO posts (id, title) VALUES (:id, :title);
SQL;

        $loader = new SqlFileLoader();
        $queries = $loader->parse('publish_post', $sql);

        $this->assertCount(2, $queries);
        $this->assertSame('find_user_by_id', $queries[0]->name);
        $this->assertSame(['id'], $queries[0]->placeholders);
        $this->assertSame(['id', 'title'], $queries[1]->placeholders);
    }

    public function test_duplicate_query_name_throws(): void
    {
        $sql = <<<'SQL'
-- name: x
SELECT 1;
-- name: x
SELECT 2;
SQL;

        $this->expectException(ForgeError::class);
        (new SqlFileLoader())->parse('feature', $sql);
    }
}
