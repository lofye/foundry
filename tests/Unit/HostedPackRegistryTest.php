<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Packs\HostedPackRegistry;
use Foundry\Support\FoundryError;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class HostedPackRegistryTest extends TestCase
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

    public function test_search_filters_sorted_entries_resolves_latest_version_and_writes_cache(): void
    {
        $registry = $this->registry([
            [
                'name' => 'foundry/blog',
                'version' => '1.1.0',
                'description' => 'Blog workflow tools',
                'download_url' => 'https://downloads.example/foundry-blog-1.1.0.zip',
            ],
            [
                'name' => 'acme/blog-widgets',
                'version' => '0.9.0',
                'description' => 'Widgets for blog dashboards',
                'download_url' => 'https://downloads.example/acme-blog-widgets-0.9.0.zip',
            ],
            [
                'name' => 'foundry/blog',
                'version' => '1.0.0',
                'description' => 'Blog workflow tools',
                'download_url' => 'https://downloads.example/foundry-blog-1.0.0.zip',
            ],
        ]);

        $matches = $registry->search('blog');

        $this->assertSame(
            ['acme/blog-widgets@0.9.0', 'foundry/blog@1.0.0', 'foundry/blog@1.1.0'],
            array_values(array_map(
                static fn(array $row): string => $row['name'] . '@' . $row['version'],
                $matches,
            )),
        );

        $latest = $registry->resolveLatest('foundry/blog');
        $this->assertSame('1.1.0', $latest['version']);
        $this->assertFileExists($this->project->root . '/.foundry/cache/registry.json');
    }

    public function test_registry_rejects_invalid_json(): void
    {
        $registry = $this->registryFetcher(static fn(string $url): string => '{');

        $this->expectExceptionObject(new FoundryError(
            'PACK_REGISTRY_INVALID_JSON',
            'parsing',
            ['registry_url' => 'https://registry.example/registry.json'],
            'Hosted pack registry must return valid JSON.',
        ));

        $registry->search('blog');
    }

    public function test_registry_rejects_duplicate_name_and_version_entries(): void
    {
        $registry = $this->registry([
            [
                'name' => 'foundry/blog',
                'version' => '1.0.0',
                'description' => 'Blog workflow tools',
                'download_url' => 'https://downloads.example/foundry-blog-1.0.0.zip',
            ],
            [
                'name' => 'foundry/blog',
                'version' => '1.0.0',
                'description' => 'Blog workflow tools',
                'download_url' => 'https://downloads.example/foundry-blog-1.0.0.zip',
            ],
        ]);

        $this->expectExceptionObject(new FoundryError(
            'PACK_REGISTRY_DUPLICATE_ENTRY',
            'validation',
            [
                'registry_url' => 'https://registry.example/registry.json',
                'pack' => 'foundry/blog',
                'version' => '1.0.0',
            ],
            'Hosted pack registry contains duplicate name/version entries.',
        ));

        $registry->entries();
    }

    public function test_registry_rejects_invalid_entry_download_urls(): void
    {
        $registry = $this->registry([
            [
                'name' => 'foundry/blog',
                'version' => '1.0.0',
                'description' => 'Blog workflow tools',
                'download_url' => 'http://downloads.example/foundry-blog-1.0.0.zip',
            ],
        ]);

        $this->expectException(FoundryError::class);
        $this->expectExceptionCode(0);

        try {
            $registry->entries();
        } catch (FoundryError $error) {
            $this->assertSame('PACK_REGISTRY_ENTRY_INVALID', $error->errorCode);
            throw $error;
        }
    }

    public function test_registry_throws_structured_error_when_pack_is_missing(): void
    {
        $registry = $this->registry([
            [
                'name' => 'foundry/blog',
                'version' => '1.0.0',
                'description' => 'Blog workflow tools',
                'download_url' => 'https://downloads.example/foundry-blog-1.0.0.zip',
            ],
        ]);

        $this->expectExceptionObject(new FoundryError(
            'PACK_REGISTRY_PACK_NOT_FOUND',
            'not_found',
            [
                'pack' => 'foundry/missing-pack',
                'registry_url' => 'https://registry.example/registry.json',
            ],
            'Pack was not found in the hosted registry.',
        ));

        $registry->resolveLatest('foundry/missing-pack');
    }

    public function test_registry_unavailability_is_reported_structurally(): void
    {
        $registry = $this->registryFetcher(static fn(string $url): string => throw new \RuntimeException('offline'));

        $this->expectException(FoundryError::class);

        try {
            $registry->search('blog');
        } catch (FoundryError $error) {
            $this->assertSame('PACK_REGISTRY_UNAVAILABLE', $error->errorCode);
            throw $error;
        }
    }

    /**
     * @param array<int,array<string,string>> $entries
     */
    private function registry(array $entries): HostedPackRegistry
    {
        return $this->registryFetcher(
            static fn(string $url): string => json_encode($entries, JSON_THROW_ON_ERROR),
        );
    }

    private function registryFetcher(callable $fetcher): HostedPackRegistry
    {
        return new HostedPackRegistry(
            Paths::fromCwd($this->project->root),
            $fetcher,
            'https://registry.example/registry.json',
        );
    }
}
