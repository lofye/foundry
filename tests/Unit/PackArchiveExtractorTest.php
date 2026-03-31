<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Packs\PackArchiveExtractor;
use Foundry\Support\FoundryError;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class PackArchiveExtractorTest extends TestCase
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

    public function test_extracts_valid_zip_archives_with_root_manifest(): void
    {
        $archive = $this->createArchive([
            'foundry.json' => json_encode([
                'name' => 'foundry/blog',
                'version' => '1.0.0',
                'description' => 'Blog workflow tools',
                'entry' => 'Vendor\\Blog\\PackServiceProvider',
                'capabilities' => ['blog.notes'],
            ], JSON_THROW_ON_ERROR),
            'src/PackServiceProvider.php' => "<?php\n",
        ]);

        $target = $this->project->root . '/extracted-pack';
        (new PackArchiveExtractor())->extract($archive, $target);

        $this->assertFileExists($target . '/foundry.json');
        $this->assertFileExists($target . '/src/PackServiceProvider.php');
    }

    public function test_rejects_archives_without_root_manifest(): void
    {
        $archive = $this->createArchive([
            'package/foundry.json' => json_encode([
                'name' => 'foundry/blog',
                'version' => '1.0.0',
                'description' => 'Blog workflow tools',
                'entry' => 'Vendor\\Blog\\PackServiceProvider',
                'capabilities' => ['blog.notes'],
            ], JSON_THROW_ON_ERROR),
        ]);

        $this->expectException(FoundryError::class);

        try {
            (new PackArchiveExtractor())->extract($archive, $this->project->root . '/missing-root-manifest');
        } catch (FoundryError $error) {
            $this->assertSame('PACK_ARCHIVE_INVALID', $error->errorCode);
            throw $error;
        }
    }

    public function test_rejects_archives_with_directory_traversal_entries(): void
    {
        $archive = $this->createArchive([
            'foundry.json' => json_encode([
                'name' => 'foundry/blog',
                'version' => '1.0.0',
                'description' => 'Blog workflow tools',
                'entry' => 'Vendor\\Blog\\PackServiceProvider',
                'capabilities' => ['blog.notes'],
            ], JSON_THROW_ON_ERROR),
            '../escape.txt' => 'bad',
        ]);

        $this->expectException(FoundryError::class);

        try {
            (new PackArchiveExtractor())->extract($archive, $this->project->root . '/unsafe-archive');
        } catch (FoundryError $error) {
            $this->assertSame('PACK_ARCHIVE_INVALID', $error->errorCode);
            throw $error;
        }
    }

    /**
     * @param array<string,string> $entries
     */
    private function createArchive(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'foundry-pack-archive-test-');
        assert(is_string($path));

        $zip = new \ZipArchive();
        $opened = $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $this->assertSame(true, $opened);

        foreach ($entries as $entry => $contents) {
            $zip->addFromString($entry, $contents);
        }

        $zip->close();

        return $path;
    }
}
