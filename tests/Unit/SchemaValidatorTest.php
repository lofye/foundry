<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Schema\JsonSchemaValidator;
use PHPUnit\Framework\TestCase;

final class SchemaValidatorTest extends TestCase
{
    private string $schemaPath;

    protected function setUp(): void
    {
        $this->schemaPath = sys_get_temp_dir() . '/schema-' . bin2hex(random_bytes(4)) . '.json';

        file_put_contents($this->schemaPath, json_encode([
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['title', 'slug'],
            'properties' => [
                'title' => ['type' => 'string', 'minLength' => 1],
                'slug' => ['type' => 'string', 'pattern' => '^[a-z0-9-]+$'],
                'published_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
            ],
        ], JSON_UNESCAPED_SLASHES));
    }

    protected function tearDown(): void
    {
        @unlink($this->schemaPath);
    }

    public function test_valid_data_passes(): void
    {
        $validator = new JsonSchemaValidator();
        $result = $validator->validate([
            'title' => 'Hello',
            'slug' => 'hello-world',
            'published_at' => null,
        ], $this->schemaPath);

        $this->assertTrue($result->isValid);
        $this->assertSame([], $result->errors);
    }

    public function test_invalid_data_returns_errors(): void
    {
        $validator = new JsonSchemaValidator();
        $result = $validator->validate([
            'title' => '',
            'slug' => 'Bad Slug',
            'extra' => 'x',
        ], $this->schemaPath);

        $this->assertFalse($result->isValid);
        $this->assertNotEmpty($result->errors);
        $this->assertSame('$.title', $result->errors[0]->path);
        $this->assertNotNull($result->errors[0]->expected);
        $this->assertNotNull($result->errors[0]->actual);
        $this->assertNotNull($result->errors[0]->suggestedFix);
    }

    public function test_empty_php_array_is_accepted_for_object_schema_without_required_fields(): void
    {
        $path = sys_get_temp_dir() . '/schema-empty-object-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($path, json_encode([
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [],
        ], JSON_UNESCAPED_SLASHES));

        $validator = new JsonSchemaValidator();
        $result = $validator->validate([], $path);

        @unlink($path);

        $this->assertTrue($result->isValid);
        $this->assertSame([], $result->errors);
    }

    public function test_validate_data_supports_array_items_and_uniqueness_constraints(): void
    {
        $validator = new JsonSchemaValidator();
        $result = $validator->validateData(['a', 'a'], [
            'type' => 'array',
            'uniqueItems' => true,
            'items' => [
                'type' => 'string',
                'minLength' => 1,
            ],
        ]);

        $this->assertFalse($result->isValid);
        $this->assertSame('$', $result->errors[0]->path);
        $this->assertSame('unique items', $result->errors[0]->expected);
    }
}
