<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\DB\SqlFileLoader;
use Foundry\Support\FoundryError;
use Foundry\Support\Yaml;
use PHPUnit\Framework\TestCase;

final class SupportIoEdgeCasesTest extends TestCase
{
    public function test_yaml_parse_file_throws_for_missing_and_non_map_roots(): void
    {
        try {
            Yaml::parseFile('/tmp/missing-yaml-' . bin2hex(random_bytes(4)) . '.yaml');
            $this->fail('Expected missing YAML file exception');
        } catch (FoundryError $e) {
            $this->assertSame('YAML_FILE_NOT_FOUND', $e->errorCode);
        }

        $path = sys_get_temp_dir() . '/yaml-edge-' . bin2hex(random_bytes(4)) . '.yaml';
        file_put_contents($path, "just-a-scalar\n");

        try {
            Yaml::parseFile($path);
            $this->fail('Expected YAML object required exception');
        } catch (FoundryError $e) {
            $this->assertSame('YAML_OBJECT_REQUIRED', $e->errorCode);
        } finally {
            @unlink($path);
        }
    }

    public function test_sql_file_loader_loads_from_disk_and_missing_file_errors(): void
    {
        $path = sys_get_temp_dir() . '/sql-edge-' . bin2hex(random_bytes(4)) . '.sql';
        file_put_contents($path, <<<'SQL'
-- name: list_posts
SELECT * FROM posts;
SQL);

        $loader = new SqlFileLoader();
        $queries = $loader->load('publish_post', $path);
        @unlink($path);

        $this->assertCount(1, $queries);
        $this->assertSame('list_posts', $queries[0]->name);

        $this->expectException(FoundryError::class);
        $this->expectExceptionMessage('SQL file not found.');
        $loader->load('publish_post', '/tmp/missing-sql-' . bin2hex(random_bytes(4)) . '.sql');
    }
}
