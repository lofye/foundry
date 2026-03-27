<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Support\FoundryError;
use Foundry\Support\Json;
use PHPUnit\Framework\TestCase;

final class JsonSupportTest extends TestCase
{
    public function test_decode_assoc_rejects_invalid_json_and_non_object_roots(): void
    {
        $this->expectException(FoundryError::class);
        Json::decodeAssoc('{');
    }

    public function test_decode_assoc_requires_object_root_and_encode_reports_json_errors(): void
    {
        try {
            Json::decodeAssoc('"scalar"');
            self::fail('Expected scalar JSON root to throw.');
        } catch (FoundryError $error) {
            self::assertSame('JSON_OBJECT_REQUIRED', $error->errorCode);
        }

        $resource = fopen('php://memory', 'rb');

        try {
            $this->expectException(FoundryError::class);
            Json::encode(['stream' => $resource]);
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }
        }
    }
}
