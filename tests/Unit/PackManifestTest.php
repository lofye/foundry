<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Packs\PackManifest;
use Foundry\Support\FoundryError;
use PHPUnit\Framework\TestCase;

final class PackManifestTest extends TestCase
{
    public function test_manifest_accepts_valid_local_pack_contract(): void
    {
        $manifest = PackManifest::fromArray([
            'name' => 'foundry/blog',
            'version' => '1.0.0',
            'description' => 'Blog tools',
            'entry' => 'Vendor\\Blog\\PackServiceProvider',
            'capabilities' => ['blog.notes', 'blog.notes'],
            'checksum' => str_repeat('a', 64),
            'signature' => null,
        ]);

        $this->assertSame('foundry/blog', $manifest->name);
        $this->assertSame('1.0.0', $manifest->version);
        $this->assertSame('Vendor\\Blog\\PackServiceProvider', $manifest->entry);
        $this->assertSame(['blog.notes'], $manifest->capabilities);
        $this->assertSame(str_repeat('a', 64), $manifest->checksum);
        $this->assertNull($manifest->signature);
    }

    public function test_manifest_validation_reports_structured_errors(): void
    {
        try {
            PackManifest::fromArray([
                'name' => 'invalid-pack',
                'version' => 'dev-main',
                'description' => '',
                'entry' => 'not a class',
                'capabilities' => ['ok', ''],
                'checksum' => 'not-a-checksum',
                'signature' => '',
            ], '/tmp/foundry.json');
            self::fail('Expected invalid manifest to throw.');
        } catch (FoundryError $error) {
            $this->assertSame('PACK_MANIFEST_INVALID', $error->errorCode);
            $this->assertSame('/tmp/foundry.json', $error->details['path']);
            $this->assertArrayHasKey('name', $error->details['errors']);
            $this->assertArrayHasKey('version', $error->details['errors']);
            $this->assertArrayHasKey('description', $error->details['errors']);
            $this->assertArrayHasKey('entry', $error->details['errors']);
            $this->assertArrayHasKey('checksum', $error->details['errors']);
            $this->assertArrayHasKey('signature', $error->details['errors']);
        }
    }
}
