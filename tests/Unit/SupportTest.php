<?php
declare(strict_types=1);

namespace Forge\Tests\Unit;

use Forge\Support\Arr;
use Forge\Support\Json;
use Forge\Support\Str;
use Forge\Support\Uuid;
use Forge\Support\ForgeError;
use PHPUnit\Framework\TestCase;

final class SupportTest extends TestCase
{
    public function test_arr_get_reads_nested_keys(): void
    {
        $this->assertSame('b', Arr::get(['a' => ['x' => 'b']], 'a.x'));
        $this->assertNull(Arr::get(['a' => 1], 'a.b'));
    }

    public function test_arr_only_and_unique(): void
    {
        $this->assertSame(['a' => 1], Arr::only(['a' => 1, 'b' => 2], ['a']));
        $this->assertSame(['a', 'b'], Arr::unique(['a', 'a', 'b']));
    }

    public function test_string_helpers(): void
    {
        $this->assertSame('publish_post', Str::toSnakeCase('PublishPost'));
        $this->assertTrue(Str::isSnakeCase('publish_post'));
        $this->assertSame('PublishPost', Str::studly('publish_post'));
    }

    public function test_json_round_trip(): void
    {
        $json = Json::encode(['a' => 1]);
        $this->assertSame(['a' => 1], Json::decodeAssoc($json));
    }

    public function test_json_decode_rejects_invalid_document(): void
    {
        $this->expectException(ForgeError::class);
        Json::decodeAssoc('{');
    }

    public function test_uuid_v4_shape(): void
    {
        $uuid = Uuid::v4();
        $this->assertMatchesRegularExpression('/^[a-f0-9-]{36}$/', $uuid);
    }
}
