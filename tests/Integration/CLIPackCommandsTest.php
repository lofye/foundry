<?php

declare(strict_types=1);

namespace Foundry\Tests\Integration;

use Foundry\CLI\Application;
use Foundry\CLI\Commands\PackCommand;
use Foundry\Packs\HostedPackRegistry;
use Foundry\Packs\PackManager;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class CLIPackCommandsTest extends TestCase
{
    private TempProject $project;
    private string $cwd;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->cwd = getcwd() ?: '.';
        chdir($this->project->root);
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        $this->project->cleanup();
    }

    public function test_pack_install_list_info_and_remove_flow_works_offline(): void
    {
        $app = new Application();

        $install = $this->runCommand($app, ['foundry', 'pack', 'install', $this->fixturePath('foundry-blog'), '--json']);
        $this->assertSame(0, $install['status']);
        $this->assertSame('foundry/blog', $install['payload']['pack']['pack']);
        $this->assertFileExists($this->project->root . '/.foundry/packs/foundry/blog/1.0.0/foundry.json');

        $installedRegistry = json_decode((string) file_get_contents($this->project->root . '/.foundry/packs/installed.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('1.0.0', $installedRegistry['foundry/blog']['active_version']);

        $list = $this->runCommand($app, ['foundry', 'pack', 'list', '--json']);
        $this->assertSame(0, $list['status']);
        $this->assertSame('foundry/blog', $list['payload']['packs'][0]['name']);
        $this->assertSame('1.0.0', $list['payload']['packs'][0]['active_version']);

        $info = $this->runCommand($app, ['foundry', 'pack', 'info', 'foundry/blog', '--json']);
        $this->assertSame(0, $info['status']);
        $this->assertTrue($info['payload']['pack']['active']);
        $this->assertSame('.foundry/packs/foundry/blog/1.0.0', $info['payload']['pack']['install_path']);
        $this->assertSame(['blog.notes'], $info['payload']['pack']['capabilities']);

        $inspectPacks = $this->runCommand($app, ['foundry', 'inspect', 'packs', '--json']);
        $this->assertSame(0, $inspectPacks['status']);
        $loadedPack = array_find(
            $inspectPacks['payload']['packs'],
            static fn(array $row): bool => (string) ($row['name'] ?? '') === 'foundry/blog',
        );
        $this->assertIsArray($loadedPack);

        $extensions = $this->runCommand($app, ['foundry', 'inspect', 'extensions', '--json']);
        $this->assertSame(0, $extensions['status']);
        $packExtension = array_find(
            $extensions['payload']['extensions'],
            static fn(array $row): bool => (string) ($row['name'] ?? '') === 'pack.foundry.blog',
        );
        $this->assertIsArray($packExtension);

        $explain = $this->runCommand($app, ['foundry', 'explain', 'pack:foundry/blog', '--json']);
        $this->assertSame(0, $explain['status']);
        $this->assertSame('pack', $explain['payload']['subject']['kind']);
        $this->assertSame('foundry/blog', $explain['payload']['subject']['extension']);
        $packEntry = array_find(
            $explain['payload']['extensions'],
            static fn(array $row): bool => (string) ($row['name'] ?? '') === 'foundry/blog',
        );
        $this->assertIsArray($packEntry);
        $this->assertSame('local', $packEntry['source']);

        $remove = $this->runCommand($app, ['foundry', 'pack', 'remove', 'foundry/blog', '--json']);
        $this->assertSame(0, $remove['status']);
        $this->assertFileExists($this->project->root . '/.foundry/packs/foundry/blog/1.0.0/foundry.json');

        $infoAfterRemove = $this->runCommand($app, ['foundry', 'pack', 'info', 'foundry/blog', '--json']);
        $this->assertSame(0, $infoAfterRemove['status']);
        $this->assertFalse($infoAfterRemove['payload']['pack']['active']);
        $this->assertNull($infoAfterRemove['payload']['pack']['active_version']);

        $inspectAfterRemove = $this->runCommand($app, ['foundry', 'inspect', 'packs', '--json']);
        $this->assertSame(0, $inspectAfterRemove['status']);
        $this->assertNull(array_find(
            $inspectAfterRemove['payload']['packs'],
            static fn(array $row): bool => (string) ($row['name'] ?? '') === 'foundry/blog',
        ));
    }

    public function test_pack_install_rejects_invalid_manifests_with_structured_errors(): void
    {
        $invalidSource = $this->project->root . '/invalid-pack';
        mkdir($invalidSource, 0777, true);
        file_put_contents($invalidSource . '/foundry.json', json_encode([
            'name' => 'invalid',
            'version' => 'dev-main',
            'description' => '',
            'entry' => 'bad entry',
            'capabilities' => [],
        ], JSON_THROW_ON_ERROR));

        $app = new Application();
        $result = $this->runCommand($app, ['foundry', 'pack', 'install', $invalidSource, '--json']);

        $this->assertSame(1, $result['status']);
        $this->assertSame('PACK_MANIFEST_INVALID', $result['payload']['error']['code']);
    }

    public function test_pack_install_fails_explicitly_on_declared_command_conflicts(): void
    {
        $app = new Application();

        $first = $this->runCommand($app, ['foundry', 'pack', 'install', $this->fixturePath('foundry-blog'), '--json']);
        $this->assertSame(0, $first['status']);

        $conflict = $this->runCommand($app, ['foundry', 'pack', 'install', $this->fixturePath('foundry-blog-tools'), '--json']);
        $this->assertSame(1, $conflict['status']);
        $this->assertSame('PACK_ACTIVATION_FAILED', $conflict['payload']['error']['code']);

        $diagnosticCodes = array_values(array_map(
            static fn(array $row): string => (string) ($row['code'] ?? ''),
            (array) ($conflict['payload']['error']['details']['graph_refresh']['diagnostics']['items'] ?? []),
        ));
        $this->assertContains('PACK_COMMAND_CONFLICT', $diagnosticCodes);
    }

    public function test_pack_install_fails_when_pack_extension_adds_duplicate_graph_node_ids(): void
    {
        $app = new Application();

        $first = $this->runCommand($app, ['foundry', 'pack', 'install', $this->fixturePath('foundry-blog'), '--json']);
        $this->assertSame(0, $first['status']);

        $duplicate = $this->runCommand($app, ['foundry', 'pack', 'install', $this->fixturePath('foundry-blog-duplicate'), '--json']);
        $this->assertSame(1, $duplicate['status']);
        $this->assertSame('PACK_ACTIVATION_FAILED', $duplicate['payload']['error']['code']);

        $messages = array_values(array_map(
            static fn(array $row): string => (string) ($row['message'] ?? ''),
            (array) ($duplicate['payload']['error']['details']['graph_refresh']['diagnostics']['items'] ?? []),
        ));
        $this->assertContains(
            'Extension pack.foundry.blog-duplicate failed during link with Vendor\\BlogDuplicate\\FoundryBlogDuplicateInterceptorPass: Duplicate graph node id interceptor:pack.foundry.blog cannot be inserted.',
            $messages,
        );
    }

    public function test_pack_search_queries_hosted_registry_and_writes_cache(): void
    {
        $app = $this->hostedPackApplication(
            [
                [
                    'name' => 'foundry/blog',
                    'version' => '1.1.0',
                    'description' => 'Blog workflow tools',
                    'download_url' => 'https://downloads.example/foundry-blog-1.1.0.zip',
                ],
                [
                    'name' => 'foundry/blog-tools',
                    'version' => '1.0.0',
                    'description' => 'More blog tools',
                    'download_url' => 'https://downloads.example/foundry-blog-tools-1.0.0.zip',
                ],
            ],
        );

        $result = $this->runCommand($app, ['foundry', 'pack', 'search', 'blog', '--json']);

        $this->assertSame(0, $result['status']);
        $this->assertSame('blog', $result['payload']['query']);
        $this->assertCount(2, $result['payload']['packs']);
        $this->assertSame('foundry/blog', $result['payload']['packs'][0]['name']);
        $this->assertFileExists($this->project->root . '/.foundry/cache/registry.json');
    }

    public function test_pack_install_can_download_and_install_from_hosted_registry(): void
    {
        $downloadUrl = 'https://downloads.example/foundry-blog-1.0.0.zip';
        $app = $this->hostedPackApplication(
            [[
                'name' => 'foundry/blog',
                'version' => '1.0.0',
                'description' => 'Blog workflow tools',
                'download_url' => $downloadUrl,
            ]],
            [$downloadUrl => $this->fixtureArchive('foundry-blog')],
        );

        $result = $this->runCommand($app, ['foundry', 'pack', 'install', 'foundry/blog', '--json']);

        $this->assertSame(0, $result['status']);
        $this->assertSame('foundry/blog', $result['payload']['pack']['pack']);
        $this->assertSame('registry', $result['payload']['pack']['source']['type']);
        $this->assertSame($downloadUrl, $result['payload']['pack']['source']['download_url']);
        $this->assertFileExists($this->project->root . '/.foundry/packs/foundry/blog/1.0.0/foundry.json');

        $info = $this->runCommand($app, ['foundry', 'pack', 'info', 'foundry/blog', '--json']);
        $this->assertSame('registry', $info['payload']['pack']['source']['type']);
    }

    public function test_pack_install_prefers_existing_local_directory_over_hosted_pack_name(): void
    {
        $localSource = $this->project->root . '/packages/blog';
        $this->copyDirectory($this->fixturePath('foundry-blog'), $localSource);
        $manifest = json_decode((string) file_get_contents($localSource . '/foundry.json'), true, 512, JSON_THROW_ON_ERROR);
        $manifest['name'] = 'packages/blog';
        file_put_contents($localSource . '/foundry.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        $calls = 0;
        $app = $this->hostedPackApplication(
            [],
            [],
            static function (string $url) use (&$calls): string {
                $calls++;
                throw new \RuntimeException('Hosted registry should not be called.');
            },
        );

        $result = $this->runCommand($app, ['foundry', 'pack', 'install', 'packages/blog', '--json']);

        $this->assertSame(0, $result['status']);
        $this->assertSame('local', $result['payload']['pack']['source']['type']);
        $this->assertSame(0, $calls);
    }

    public function test_pack_search_fails_gracefully_when_registry_is_unavailable(): void
    {
        $app = $this->hostedPackApplication(
            [],
            [],
            static fn(string $url): string => throw new \RuntimeException('offline'),
        );

        $result = $this->runCommand($app, ['foundry', 'pack', 'search', 'blog', '--json']);

        $this->assertSame(1, $result['status']);
        $this->assertSame('PACK_REGISTRY_UNAVAILABLE', $result['payload']['error']['code']);
    }

    /**
     * @param array<int,string> $argv
     * @return array{status:int,payload:array<string,mixed>}
     */
    private function runCommand(Application $app, array $argv): array
    {
        ob_start();
        $status = $app->run($argv);
        $output = trim((string) ob_get_clean());

        /** @var array<string,mixed> $payload */
        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        return ['status' => $status, 'payload' => $payload];
    }

    private function fixturePath(string $name): string
    {
        return dirname(__DIR__) . '/Fixtures/Packs/' . $name;
    }

    /**
     * @param array<int,array<string,string>> $registryEntries
     * @param array<string,string> $downloads
     */
    private function hostedPackApplication(array $registryEntries, array $downloads = [], ?callable $fetcher = null): Application
    {
        $registryUrl = 'https://registry.example/registry.json';
        $responses = $downloads + [
            $registryUrl => json_encode($registryEntries, JSON_THROW_ON_ERROR),
        ];

        $fetcher ??= static function (string $url) use ($responses): string {
            if (!array_key_exists($url, $responses)) {
                throw new \RuntimeException('Unexpected URL: ' . $url);
            }

            return $responses[$url];
        };

        $paths = Paths::fromCwd($this->project->root);
        $registry = new HostedPackRegistry($paths, $fetcher, $registryUrl);
        $manager = new PackManager($paths, $registry);

        return new Application([new PackCommand($manager)]);
    }

    private function fixtureArchive(string $fixtureName): string
    {
        $archive = tempnam(sys_get_temp_dir(), 'foundry-pack-cli-archive-');
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
            $zip->addFile($fileInfo->getPathname(), $relative);
        }

        $zip->close();
        $contents = file_get_contents($archive);
        @unlink($archive);

        return is_string($contents) ? $contents : '';
    }

    private function copyDirectory(string $source, string $target): void
    {
        mkdir($target, 0777, true);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof \SplFileInfo) {
                continue;
            }

            $relative = substr($fileInfo->getPathname(), strlen(rtrim($source, '/') . '/'));
            $destination = $target . '/' . $relative;

            if ($fileInfo->isDir()) {
                mkdir($destination, 0777, true);
                continue;
            }

            $directory = dirname($destination);
            if (!is_dir($directory)) {
                mkdir($directory, 0777, true);
            }

            copy($fileInfo->getPathname(), $destination);
        }
    }
}
