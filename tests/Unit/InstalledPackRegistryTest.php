<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Packs\InstalledPackRegistry;
use Foundry\Packs\PackManifest;
use Foundry\Support\FoundryError;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class InstalledPackRegistryTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function test_activate_read_and_deactivate_normalize_versions_and_sources(): void
    {
        $registry = new InstalledPackRegistry(Paths::fromCwd($this->project->root));

        $manifestV1 = new PackManifest(
            name: 'foundry/blog',
            version: '1.0.0',
            description: 'Blog tools',
            entry: 'Vendor\\Blog\\PackServiceProvider',
            capabilities: ['blog.notes'],
            checksum: str_repeat('a', 64),
            signature: null,
        );
        $manifestV11 = new PackManifest(
            name: 'foundry/blog',
            version: '1.1.0',
            description: 'Blog tools',
            entry: 'Vendor\\Blog\\PackServiceProvider',
            capabilities: ['blog.notes'],
            checksum: str_repeat('b', 64),
            signature: null,
        );

        $registry->activate($manifestV11, ['type' => 'registry', 'download_url' => 'https://downloads.example/blog.zip']);
        $registry->activate($manifestV1, ['type' => 'local', 'path' => '/packs/blog']);

        $entry = $registry->entry('foundry/blog');

        $this->assertSame('1.0.0', $entry['active_version']);
        $this->assertSame(['1.0.0', '1.1.0'], $entry['installed_versions']);
        $this->assertSame('local', $entry['sources']['1.0.0']['type']);
        $this->assertSame('registry', $entry['sources']['1.1.0']['type']);
        $this->assertTrue($registry->isInstalled('foundry/blog'));

        $registry->deactivate('foundry/blog');

        $this->assertNull($registry->entry('foundry/blog')['active_version']);
    }

    public function test_read_reports_corrupt_registry_and_validation_errors(): void
    {
        $registry = new InstalledPackRegistry(Paths::fromCwd($this->project->root));
        $path = $registry->registryPath();
        mkdir(dirname($path), 0777, true);

        file_put_contents($path, '{');

        try {
            $registry->read();
            self::fail('Expected corrupt registry failure.');
        } catch (FoundryError $error) {
            $this->assertSame('PACK_REGISTRY_CORRUPT', $error->errorCode);
        }

        file_put_contents($path, json_encode([
            'invalid' => ['active_version' => '1.0.0', 'installed_versions' => ['1.0.0']],
            'foundry/blog' => ['active_version' => '2.0.0', 'installed_versions' => ['1.0.0']],
            'foundry/tools' => ['active_version' => '1.0.0', 'installed_versions' => ['1.0.0'], 'sources' => ['2.0.0' => ['type' => 'ftp']]],
        ], JSON_THROW_ON_ERROR));

        try {
            $registry->read();
            self::fail('Expected invalid registry failure.');
        } catch (FoundryError $error) {
            $this->assertSame('PACK_REGISTRY_INVALID', $error->errorCode);
            $errors = $error->details['errors'] ?? [];
            $this->assertArrayHasKey('invalid', $errors);
            $this->assertArrayHasKey('foundry/blog.active_version', $errors);
            $this->assertArrayHasKey('foundry/tools.sources.2.0.0', $errors);
        }
    }

    public function test_deactivate_and_install_path_validate_pack_name(): void
    {
        $registry = new InstalledPackRegistry(Paths::fromCwd($this->project->root));

        try {
            $registry->deactivate('foundry/missing');
            self::fail('Expected missing pack failure.');
        } catch (FoundryError $error) {
            $this->assertSame('PACK_NOT_INSTALLED', $error->errorCode);
        }

        try {
            $registry->installPath('invalid', '1.0.0');
            self::fail('Expected invalid pack name failure.');
        } catch (FoundryError $error) {
            $this->assertSame('PACK_NAME_INVALID', $error->errorCode);
        }
    }
}
