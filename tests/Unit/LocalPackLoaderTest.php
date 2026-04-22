<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Packs\InstalledPackRegistry;
use Foundry\Packs\LocalPackLoader;
use Foundry\Packs\PackChecksum;
use Foundry\Packs\PackManifest;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class LocalPackLoaderTest extends TestCase
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

    public function test_loader_reports_registry_corruption_without_throwing(): void
    {
        $paths = Paths::fromCwd($this->project->root);
        $registry = new InstalledPackRegistry($paths);
        mkdir(dirname($registry->registryPath()), 0777, true);
        file_put_contents($registry->registryPath(), '{');

        $result = (new LocalPackLoader($paths))->load();

        $this->assertSame(['.foundry/packs/installed.json'], $result['source_paths']);
        $this->assertSame([], $result['entries']);
        $this->assertSame([], $result['active_packs']);
        $this->assertSame('PACK_REGISTRY_CORRUPT', $result['diagnostics'][0]['code']);
    }

    public function test_loader_reports_missing_install_path_manifest_mismatch_and_missing_entry_class(): void
    {
        $paths = Paths::fromCwd($this->project->root);
        $registry = new InstalledPackRegistry($paths);

        $missingManifest = new PackManifest(
            name: 'foundry/missing-pack',
            version: '1.0.0',
            description: 'Missing files',
            entry: 'Vendor\\Missing\\Provider',
            capabilities: ['missing.capability'],
            checksum: str_repeat('a', 64),
            signature: null,
        );
        $registry->activate($missingManifest, ['type' => 'local', 'path' => '/packs/missing']);

        $mismatchDir = $registry->installPath('foundry/mismatch-pack', '1.0.0');
        mkdir($mismatchDir, 0777, true);
        $this->writeManifest($mismatchDir, [
            'name' => 'foundry/other-pack',
            'version' => '1.0.0',
            'description' => 'Mismatch',
            'entry' => 'Vendor\\Mismatch\\Provider',
            'capabilities' => ['mismatch.capability'],
            'signature' => null,
        ]);
        $registry->activate(new PackManifest(
            name: 'foundry/mismatch-pack',
            version: '1.0.0',
            description: 'Mismatch',
            entry: 'Vendor\\Mismatch\\Provider',
            capabilities: ['mismatch.capability'],
            checksum: json_decode((string) file_get_contents($mismatchDir . '/foundry.json'), true, 512, JSON_THROW_ON_ERROR)['checksum'],
            signature: null,
        ));

        $entryMissingDir = $registry->installPath('foundry/entry-missing-pack', '1.0.0');
        mkdir($entryMissingDir, 0777, true);
        file_put_contents($entryMissingDir . '/Provider.php', "<?php\ndeclare(strict_types=1);\n");
        $entryChecksum = $this->writeManifest($entryMissingDir, [
            'name' => 'foundry/entry-missing-pack',
            'version' => '1.0.0',
            'description' => 'Entry missing',
            'entry' => 'Vendor\\EntryMissing\\Provider',
            'capabilities' => ['entry.missing'],
            'signature' => null,
        ]);
        $registry->activate(new PackManifest(
            name: 'foundry/entry-missing-pack',
            version: '1.0.0',
            description: 'Entry missing',
            entry: 'Vendor\\EntryMissing\\Provider',
            capabilities: ['entry.missing'],
            checksum: $entryChecksum,
            signature: null,
        ));

        $result = (new LocalPackLoader($paths))->load();
        $codes = array_values(array_map(
            static fn(array $row): string => (string) ($row['code'] ?? ''),
            $result['diagnostics'],
        ));

        $this->assertContains('PACK_SOURCE_MISSING', $codes);
        $this->assertContains('PACK_MANIFEST_MISMATCH', $codes);
        $this->assertContains('PACK_ENTRY_CLASS_NOT_FOUND', $codes);
        $this->assertSame([], $result['entries']);
    }

    public function test_loader_activates_valid_packs_and_reports_command_and_schema_conflicts(): void
    {
        $paths = Paths::fromCwd($this->project->root);
        $registry = new InstalledPackRegistry($paths);

        $firstDir = $registry->installPath('foundry/alpha-pack', '1.0.0');
        mkdir($firstDir, 0777, true);
        file_put_contents($firstDir . '/Provider.php', <<<'PHP'
<?php
declare(strict_types=1);

namespace Vendor\Alpha;

use Foundry\Packs\PackContext;
use Foundry\Packs\PackServiceProvider;

final class Provider implements PackServiceProvider
{
    public function register(PackContext $context): void
    {
        $context->registerCommand('alpha:sync');
        $context->registerSchema('schemas.alpha');
    }
}
PHP);
        $firstChecksum = $this->writeManifest($firstDir, [
            'name' => 'foundry/alpha-pack',
            'version' => '1.0.0',
            'description' => 'Alpha',
            'entry' => 'Vendor\\Alpha\\Provider',
            'capabilities' => ['alpha.capability'],
            'signature' => null,
        ]);
        $registry->activate(new PackManifest(
            name: 'foundry/alpha-pack',
            version: '1.0.0',
            description: 'Alpha',
            entry: 'Vendor\\Alpha\\Provider',
            capabilities: ['alpha.capability'],
            checksum: $firstChecksum,
            signature: null,
        ));

        $secondDir = $registry->installPath('foundry/beta-pack', '1.0.0');
        mkdir($secondDir, 0777, true);
        file_put_contents($secondDir . '/Provider.php', <<<'PHP'
<?php
declare(strict_types=1);

namespace Vendor\Beta;

use Foundry\Packs\PackContext;
use Foundry\Packs\PackServiceProvider;

final class Provider implements PackServiceProvider
{
    public function register(PackContext $context): void
    {
        $context->registerCommand('alpha:sync');
        $context->registerSchema('schemas.alpha');
    }
}
PHP);
        $secondChecksum = $this->writeManifest($secondDir, [
            'name' => 'foundry/beta-pack',
            'version' => '1.0.0',
            'description' => 'Beta',
            'entry' => 'Vendor\\Beta\\Provider',
            'capabilities' => ['beta.capability'],
            'signature' => null,
        ]);
        $registry->activate(new PackManifest(
            name: 'foundry/beta-pack',
            version: '1.0.0',
            description: 'Beta',
            entry: 'Vendor\\Beta\\Provider',
            capabilities: ['beta.capability'],
            checksum: $secondChecksum,
            signature: null,
        ));

        $result = (new LocalPackLoader($paths))->load();
        $codes = array_values(array_map(
            static fn(array $row): string => (string) ($row['code'] ?? ''),
            $result['diagnostics'],
        ));

        $this->assertCount(2, $result['entries']);
        $this->assertCount(2, $result['active_packs']);
        $this->assertContains('PACK_COMMAND_CONFLICT', $codes);
        $this->assertContains('PACK_SCHEMA_CONFLICT', $codes);
    }

    public function test_loader_reports_checksum_instantiation_and_invalid_provider_failures(): void
    {
        $paths = Paths::fromCwd($this->project->root);
        $registry = new InstalledPackRegistry($paths);

        $checksumDir = $registry->installPath('foundry/checksum-pack', '1.0.0');
        mkdir($checksumDir, 0777, true);
        file_put_contents($checksumDir . '/Provider.php', <<<'PHP'
<?php
declare(strict_types=1);

namespace Vendor\Checksum;

use Foundry\Packs\PackContext;
use Foundry\Packs\PackServiceProvider;

final class Provider implements PackServiceProvider
{
    public function register(PackContext $context): void {}
}
PHP);
        $checksumValue = $this->writeManifest($checksumDir, [
            'name' => 'foundry/checksum-pack',
            'version' => '1.0.0',
            'description' => 'Checksum',
            'entry' => 'Vendor\\Checksum\\Provider',
            'capabilities' => ['checksum.capability'],
            'signature' => null,
        ]);
        $registry->activate(new PackManifest(
            name: 'foundry/checksum-pack',
            version: '1.0.0',
            description: 'Checksum',
            entry: 'Vendor\\Checksum\\Provider',
            capabilities: ['checksum.capability'],
            checksum: $checksumValue,
            signature: null,
        ));
        file_put_contents($checksumDir . '/extra.php', "<?php\ndeclare(strict_types=1);\n");

        $instantiationDir = $registry->installPath('foundry/instantiation-pack', '1.0.0');
        mkdir($instantiationDir, 0777, true);
        file_put_contents($instantiationDir . '/Provider.php', <<<'PHP'
<?php
declare(strict_types=1);

namespace Vendor\Instantiation;

use Foundry\Packs\PackContext;
use Foundry\Packs\PackServiceProvider;

final class Provider implements PackServiceProvider
{
    public function __construct()
    {
        throw new \RuntimeException('boom');
    }

    public function register(PackContext $context): void {}
}
PHP);
        $instantiationChecksum = $this->writeManifest($instantiationDir, [
            'name' => 'foundry/instantiation-pack',
            'version' => '1.0.0',
            'description' => 'Instantiation',
            'entry' => 'Vendor\\Instantiation\\Provider',
            'capabilities' => ['instantiation.capability'],
            'signature' => null,
        ]);
        $registry->activate(new PackManifest(
            name: 'foundry/instantiation-pack',
            version: '1.0.0',
            description: 'Instantiation',
            entry: 'Vendor\\Instantiation\\Provider',
            capabilities: ['instantiation.capability'],
            checksum: $instantiationChecksum,
            signature: null,
        ));

        $invalidDir = $registry->installPath('foundry/invalid-pack', '1.0.0');
        mkdir($invalidDir, 0777, true);
        file_put_contents($invalidDir . '/Provider.php', <<<'PHP'
<?php
declare(strict_types=1);

namespace Vendor\Invalid;

final class Provider
{
}
PHP);
        $invalidChecksum = $this->writeManifest($invalidDir, [
            'name' => 'foundry/invalid-pack',
            'version' => '1.0.0',
            'description' => 'Invalid',
            'entry' => 'Vendor\\Invalid\\Provider',
            'capabilities' => ['invalid.capability'],
            'signature' => null,
        ]);
        $registry->activate(new PackManifest(
            name: 'foundry/invalid-pack',
            version: '1.0.0',
            description: 'Invalid',
            entry: 'Vendor\\Invalid\\Provider',
            capabilities: ['invalid.capability'],
            checksum: $invalidChecksum,
            signature: null,
        ));

        $result = (new LocalPackLoader($paths))->load();
        $codes = array_values(array_map(
            static fn(array $row): string => (string) ($row['code'] ?? ''),
            $result['diagnostics'],
        ));

        $this->assertContains('PACK_CHECKSUM_MISMATCH', $codes);
        $this->assertContains('PACK_ENTRY_INSTANTIATION_FAILED', $codes);
        $this->assertContains('PACK_ENTRY_INVALID', $codes);
    }

    public function test_loader_uses_autoload_files_and_registers_compiler_extension_providers(): void
    {
        $paths = Paths::fromCwd($this->project->root);
        $registry = new InstalledPackRegistry($paths);

        $autoloadDir = $registry->installPath('foundry/autoload-pack', '1.0.0');
        mkdir($autoloadDir, 0777, true);
        file_put_contents($autoloadDir . '/autoload.php', <<<'PHP'
<?php
declare(strict_types=1);

namespace Vendor\AutoloadPack;

use Foundry\Compiler\Extensions\AbstractCompilerExtension;
use Foundry\Compiler\Extensions\ExtensionDescriptor;
use Foundry\Packs\PackContext;
use Foundry\Packs\PackServiceProvider;

final class Provider extends AbstractCompilerExtension implements PackServiceProvider
{
    public function name(): string
    {
        return 'autoload-pack';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function descriptor(): ExtensionDescriptor
    {
        return new ExtensionDescriptor(
            name: $this->name(),
            version: $this->version(),
            providedNodeTypes: ['autoload.node'],
        );
    }

    public function register(PackContext $context): void
    {
        $context->registerCommand('autoload:sync');
    }
}
PHP);
        $autoloadChecksum = $this->writeManifest($autoloadDir, [
            'name' => 'foundry/autoload-pack',
            'version' => '1.0.0',
            'description' => 'Autoload',
            'entry' => 'Vendor\\AutoloadPack\\Provider',
            'capabilities' => ['autoload.capability'],
            'signature' => null,
        ]);
        $registry->activate(new PackManifest(
            name: 'foundry/autoload-pack',
            version: '1.0.0',
            description: 'Autoload',
            entry: 'Vendor\\AutoloadPack\\Provider',
            capabilities: ['autoload.capability'],
            checksum: $autoloadChecksum,
            signature: null,
        ));

        $result = (new LocalPackLoader($paths))->load();

        $this->assertSame([], $result['diagnostics']);
        $this->assertCount(1, $result['entries']);
        $this->assertSame('Vendor\\AutoloadPack\\Provider', $result['active_packs'][0]['manifest']['entry']);
        $this->assertContains('autoload:sync', $result['active_packs'][0]['declared_contributions']['commands']);
        $this->assertSame(
            ['autoload.node'],
            $result['entries'][0]['extension']->descriptor()->toArray()['provides']['node_types'],
        );
    }

    /**
     * @param array<string,mixed> $manifest
     */
    private function writeManifest(string $directory, array $manifest): string
    {
        unset($manifest['checksum']);
        file_put_contents($directory . '/foundry.json', json_encode($manifest + ['checksum' => ''], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        $checksum = PackChecksum::forDirectory($directory);
        file_put_contents($directory . '/foundry.json', json_encode($manifest + ['checksum' => $checksum], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return PackChecksum::forDirectory($directory);
    }
}
