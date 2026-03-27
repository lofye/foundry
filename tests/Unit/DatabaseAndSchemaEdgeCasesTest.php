<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\DB\Connection;
use Foundry\DB\PdoQueryExecutor;
use Foundry\DB\QueryDefinition;
use Foundry\DB\QueryRegistry;
use Foundry\Schema\JsonSchemaValidator;
use Foundry\Support\FoundryError;
use PHPUnit\Framework\TestCase;

final class DatabaseAndSchemaEdgeCasesTest extends TestCase
{
    public function test_query_executor_throws_when_required_param_is_missing(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $registry = new QueryRegistry();
        $registry->register(new QueryDefinition(
            'publish_post',
            'insert_post',
            'INSERT INTO missing_table (id) VALUES (:id)',
            ['id'],
        ));

        $executor = new PdoQueryExecutor(new Connection($pdo), $registry);

        $this->expectException(FoundryError::class);
        $this->expectExceptionMessage('Query parameter missing.');
        $executor->execute('publish_post', 'insert_post', []);
    }

    public function test_json_schema_validator_exercises_additional_constraints(): void
    {
        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['status', 'timestamp', 'tag'],
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['ok', 'fail']],
                'timestamp' => ['type' => 'string', 'format' => 'date-time'],
                'tag' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 4, 'pattern' => '^[a-z]+$'],
            ],
        ];

        $path = sys_get_temp_dir() . '/schema-edge-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($path, json_encode($schema, JSON_UNESCAPED_SLASHES));

        $validator = new JsonSchemaValidator();
        $result = $validator->validate([
            'status' => 'unknown',
            'timestamp' => 'not-a-date',
            'tag' => 'A',
            'extra' => true,
        ], $path);

        @unlink($path);

        $this->assertFalse($result->isValid);
        $this->assertNotEmpty($result->errors);
    }

    public function test_json_schema_validator_throws_when_schema_file_is_missing(): void
    {
        $validator = new JsonSchemaValidator();

        $this->expectException(FoundryError::class);
        $this->expectExceptionMessage('Schema file not found.');
        $validator->validate(['x' => 1], '/tmp/schema-does-not-exist-' . bin2hex(random_bytes(4)) . '.json');
    }
}
