<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Packs\HostedPackRegistry;
use Foundry\Packs\PackChecksum;
use Foundry\Packs\PackManager;
use Foundry\Support\FoundryError;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class PackManagerTest extends TestCase
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

    public function test_install_list_info_and_remove_local_pack(): void
    {
        $manager = $this->manager();

        $installed = $manager->install($this->fixturePath('foundry-blog'));
        $listed = $manager->list();
        $info = $manager->info('foundry/blog');
        $removed = $manager->remove('foundry/blog');
        $inactive = $manager->info('foundry/blog');

        $this->assertSame('foundry/blog', $installed['pack']);
        $this->assertSame('local', $installed['source']['type']);
        $this->assertSame('foundry/blog', $listed[0]['name']);
        $this->assertSame('local', $listed[0]['source_kind']);
        $this->assertTrue($info['active']);
        $this->assertSame('local', $info['source_kind']);
        $this->assertFalse($removed['active']);
        $this->assertNull($removed['active_version']);
        $this->assertFalse($inactive['active']);
    }

    public function test_install_rejects_empty_missing_manifestless_duplicate_and_checksum_mismatch_sources(): void
    {
        $manager = $this->manager();

        try {
            $manager->install('   ');
            self::fail('Expected empty source failure.');
        } catch (FoundryError $error) {
            $this->assertSame('PACK_SOURCE_REQUIRED', $error->errorCode);
        }

        try {
            $manager->install('missing-pack');
            self::fail('Expected missing pack failure.');
        } catch (FoundryError $error) {
            $this->assertSame('PACK_SOURCE_MISSING', $error->errorCode);
        }

        $manifestless = $this->project->root . '/manifestless-pack';
        mkdir($manifestless, 0777, true);

        try {
            $manager->install($manifestless);
            self::fail('Expected missing manifest failure.');
        } catch (FoundryError $error) {
            $this->assertSame('PACK_MANIFEST_MISSING', $error->errorCode);
        }

        $manager->install($this->fixturePath('foundry-blog'));

        try {
            $manager->install($this->fixturePath('foundry-blog'));
            self::fail('Expected duplicate pack version failure.');
        } catch (FoundryError $error) {
            $this->assertSame('PACK_VERSION_ALREADY_INSTALLED', $error->errorCode);
        }

        $source = $this->project->root . '/checksum-mismatch-pack';
        $this->copyDirectory($this->fixturePath('foundry-blog'), $source);
        $manifest = $this->fixtureManifest('foundry-blog');
        $manifest['checksum'] = str_repeat('f', 64);
        file_put_contents($source . '/foundry.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        try {
            $this->manager()->install($source);
            self::fail('Expected checksum mismatch.');
        } catch (FoundryError $error) {
            $this->assertSame('PACK_CHECKSUM_MISMATCH', $error->errorCode);
        }
    }

    public function test_search_and_remote_install_cover_registry_paths(): void
    {
        $downloadUrl = 'https://downloads.example/foundry-blog-1.0.0.zip';
        $registry = $this->registry(
            [[
                'name' => 'foundry/blog',
                'version' => '1.0.0',
                'description' => 'Blog workflow tools',
                'download_url' => $downloadUrl,
                'checksum' => $this->fixtureManifest('foundry-blog')['checksum'],
                'signature' => null,
                'verified' => true,
            ]],
            [$downloadUrl => $this->fixtureArchive('foundry-blog')],
        );
        $manager = $this->manager($registry);

        $search = $manager->search('blog');
        $installed = $manager->install('foundry/blog');
        $info = $manager->info('foundry/blog');

        $this->assertSame('blog', $search['query']);
        $this->assertSame('foundry/blog', $search['packs'][0]['name']);
        $this->assertSame('registry', $installed['source']['type']);
        $this->assertSame($downloadUrl, $installed['source']['download_url']);
        $this->assertSame('remote', $info['source_kind']);
    }

    public function test_remote_install_rejects_manifest_and_checksum_mismatches(): void
    {
        $downloadUrl = 'https://downloads.example/foundry-blog-1.0.0.zip';

        $manifestMismatch = $this->manager($this->registry(
            [[
                'name' => 'foundry/blog',
                'version' => '1.0.0',
                'description' => 'Blog workflow tools',
                'download_url' => $downloadUrl,
                'checksum' => $this->fixtureManifest('foundry-blog')['checksum'],
                'signature' => null,
                'verified' => true,
            ]],
            [$downloadUrl => $this->fixtureArchive('foundry-blog', ['name' => 'foundry/other'])],
        ));

        try {
            $manifestMismatch->install('foundry/blog');
            self::fail('Expected manifest mismatch.');
        } catch (FoundryError $error) {
            $this->assertSame('PACK_DOWNLOAD_MANIFEST_MISMATCH', $error->errorCode);
        }

        $checksumMismatch = $this->manager($this->registry(
            [[
                'name' => 'foundry/blog',
                'version' => '1.0.0',
                'description' => 'Blog workflow tools',
                'download_url' => $downloadUrl,
                'checksum' => str_repeat('f', 64),
                'signature' => null,
                'verified' => true,
            ]],
            [$downloadUrl => $this->fixtureArchive('foundry-blog')],
        ));

        try {
            $checksumMismatch->install('foundry/blog');
            self::fail('Expected checksum mismatch.');
        } catch (FoundryError $error) {
            $this->assertSame('PACK_DOWNLOAD_CHECKSUM_MISMATCH', $error->errorCode);
        }
    }

    public function test_info_and_remove_reject_unknown_packs(): void
    {
        $manager = $this->manager();

        try {
            $manager->info('foundry/missing');
            self::fail('Expected missing pack info failure.');
        } catch (FoundryError $error) {
            $this->assertSame('PACK_NOT_INSTALLED', $error->errorCode);
        }

        try {
            $manager->remove('foundry/missing');
            self::fail('Expected missing pack remove failure.');
        } catch (FoundryError $error) {
            $this->assertSame('PACK_NOT_INSTALLED', $error->errorCode);
        }
    }

    private function manager(?HostedPackRegistry $registry = null): PackManager
    {
        return new PackManager(Paths::fromCwd($this->project->root), $registry);
    }

    /**
     * @param array<int,array<string,mixed>> $registryEntries
     * @param array<string,string> $downloads
     */
    private function registry(array $registryEntries, array $downloads = []): HostedPackRegistry
    {
        $registryUrl = 'https://registry.example/packs';
        $responses = $downloads + [
            $registryUrl => json_encode($registryEntries, JSON_THROW_ON_ERROR),
        ];

        return new HostedPackRegistry(
            Paths::fromCwd($this->project->root),
            static function (string $url) use ($responses): string {
                if (!array_key_exists($url, $responses)) {
                    throw new \RuntimeException('Unexpected URL: ' . $url);
                }

                return $responses[$url];
            },
            $registryUrl,
        );
    }

    private function fixturePath(string $name): string
    {
        return dirname(__DIR__) . '/Fixtures/Packs/' . $name;
    }

    /**
     * @param array<string,mixed> $manifestOverrides
     */
    private function fixtureArchive(string $fixtureName, array $manifestOverrides = []): string
    {
        $archive = tempnam(sys_get_temp_dir(), 'foundry-pack-unit-archive-');
        assert(is_string($archive));

        $zip = new \ZipArchive();
        $opened = $zip->open($archive, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $this->assertSame(true, $opened);

        $source = $this->fixturePath($fixtureName);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof \SplFileInfo || !$fileInfo->isFile()) {
                continue;
            }

            $relative = substr($fileInfo->getPathname(), strlen(rtrim($source, '/') . '/'));
            if ($relative === 'foundry.json' && $manifestOverrides !== []) {
                $manifest = array_replace($this->fixtureManifest($fixtureName), $manifestOverrides);
                unset($manifest['checksum'], $manifest['signature']);
                $manifest['checksum'] = $this->checksumForManifestOverride($fixtureName, $manifest);
                $manifest['signature'] = null;
                $zip->addFromString($relative, json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
                continue;
            }

            $zip->addFile($fileInfo->getPathname(), $relative);
        }

        $zip->close();
        $contents = file_get_contents($archive);
        @unlink($archive);

        return is_string($contents) ? $contents : '';
    }

    /**
     * @return array<string,mixed>
     */
    private function fixtureManifest(string $fixtureName): array
    {
        return json_decode((string) file_get_contents($this->fixturePath($fixtureName) . '/foundry.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string,mixed> $manifest
     */
    private function checksumForManifestOverride(string $fixtureName, array $manifest): string
    {
        $temporary = $this->project->root . '/checksum-fixture-' . md5($fixtureName . json_encode($manifest, JSON_THROW_ON_ERROR));
        $this->copyDirectory($this->fixturePath($fixtureName), $temporary);
        file_put_contents($temporary . '/foundry.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        try {
            return PackChecksum::forDirectory($temporary);
        } finally {
            $this->deleteDirectory($temporary);
        }
    }

    private function copyDirectory(string $source, string $target): void
    {
        @mkdir($target, 0777, true);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof \SplFileInfo) {
                continue;
            }

            $pathname = $fileInfo->getPathname();
            $relative = substr($pathname, strlen(rtrim($source, '/') . '/'));
            $destination = $target . '/' . $relative;

            if ($fileInfo->isDir()) {
                @mkdir($destination, 0777, true);
                continue;
            }

            @mkdir(dirname($destination), 0777, true);
            copy($pathname, $destination);
        }
    }

    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof \SplFileInfo) {
                continue;
            }

            if ($fileInfo->isDir()) {
                @rmdir($fileInfo->getPathname());
                continue;
            }

            @unlink($fileInfo->getPathname());
        }

        @rmdir($path);
    }
}
