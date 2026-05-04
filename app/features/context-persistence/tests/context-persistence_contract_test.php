<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ContextPersistenceContractTest extends TestCase
{
    public function test_input_schema_accepts_valid_payload(): void
    {
        $schema = $this->schema('input.schema.json');

        self::assertSame('object', $schema['type'] ?? null);
        self::assertSame([], $schema['required'] ?? null);
        self::assertFalse((bool) ($schema['additionalProperties'] ?? true));
    }

    public function test_output_schema_matches_action_result_shape(): void
    {
        $schema = $this->schema('output.schema.json');

        self::assertSame('object', $schema['type'] ?? null);
        self::assertSame(['status', 'feature'], $schema['required'] ?? null);
        self::assertSame(['feature', 'status'], array_keys($schema['properties'] ?? []));
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(string $file): array
    {
        $decoded = json_decode((string) file_get_contents(dirname(__DIR__) . '/' . $file), true);

        self::assertIsArray($decoded);

        return $decoded;
    }
}
