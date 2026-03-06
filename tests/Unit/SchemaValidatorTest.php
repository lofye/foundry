<?php
declare(strict_types=1);

namespace Forge\Tests\Unit;

use Forge\Schema\JsonSchemaValidator;
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
    }
}
