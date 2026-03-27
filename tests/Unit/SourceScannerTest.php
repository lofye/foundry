<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Compiler\SourceScanner;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class SourceScannerTest extends TestCase
{
    private TempProject $project;
    private SourceScanner $scanner;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->scanner = new SourceScanner(Paths::fromCwd($this->project->root));
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function test_source_files_hashes_feature_detection_and_changed_files_are_deterministic(): void
    {
        $featureBase = $this->project->root . '/app/features/publish_post';
        mkdir($featureBase . '/tests', 0777, true);
        mkdir($this->project->root . '/config', 0777, true);
        mkdir($this->project->root . '/bootstrap', 0777, true);
        mkdir($this->project->root . '/config/foundry', 0777, true);
        mkdir($this->project->root . '/app/definitions/http', 0777, true);

        file_put_contents($featureBase . '/feature.yaml', "version: 1\nfeature: publish_post\n");
        file_put_contents($featureBase . '/input.schema.json', '{"type":"object"}');
        file_put_contents($featureBase . '/output.schema.json', '{"type":"object"}');
        file_put_contents($featureBase . '/action.php', '<?php declare(strict_types=1);');
        file_put_contents($featureBase . '/tests/publish_post_feature_test.php', '<?php declare(strict_types=1);');
        file_put_contents($featureBase . '/context.manifest.json', '{"version":1,"feature":"publish_post","kind":"http"}');
        file_put_contents($this->project->root . '/config/cache.yaml', "version: 1\n");
        file_put_contents($this->project->root . '/config/runtime.php', '<?php return [];');
        file_put_contents($this->project->root . '/bootstrap/app.php', '<?php declare(strict_types=1);');
        file_put_contents($this->project->root . '/foundry.extensions.php', '<?php return [];');
        file_put_contents($this->project->root . '/config/foundry/extensions.php', '<?php return [];');
        file_put_contents($this->project->root . '/app/definitions/http/feature_manifest.yaml', "version: 1\n");

        $files = $this->scanner->sourceFiles();

        $this->assertSame([
            'app/definitions/http/feature_manifest.yaml',
            'app/features/publish_post/action.php',
            'app/features/publish_post/context.manifest.json',
            'app/features/publish_post/feature.yaml',
            'app/features/publish_post/input.schema.json',
            'app/features/publish_post/output.schema.json',
            'app/features/publish_post/tests/publish_post_feature_test.php',
            'bootstrap/app.php',
            'config/cache.yaml',
            'config/foundry/extensions.php',
            'config/runtime.php',
            'foundry.extensions.php',
        ], $files);

        $hashes = $this->scanner->hashFiles(array_merge($files, ['app/features/publish_post/missing.php']));
        $this->assertArrayNotHasKey('app/features/publish_post/missing.php', $hashes);
        $this->assertSame(array_keys($hashes), $files);
        $this->assertSame(
            $this->scanner->aggregateHash($hashes),
            $this->scanner->aggregateHash(array_reverse($hashes, true)),
        );

        $this->assertSame('publish_post', $this->scanner->featureFromPath('app/features/publish_post/action.php'));
        $this->assertNull($this->scanner->featureFromPath('app/features/'));
        $this->assertNull($this->scanner->featureFromPath('app/generated/routes.php'));

        $this->assertSame(
            ['app/features/publish_post/action.php', 'app/features/publish_post/new.php', 'app/features/publish_post/removed.php'],
            $this->scanner->changedFiles(
                [
                    'app/features/publish_post/action.php' => 'old',
                    'app/features/publish_post/removed.php' => 'gone',
                ],
                [
                    'app/features/publish_post/action.php' => 'new',
                    'app/features/publish_post/new.php' => 'added',
                ],
            ),
        );
    }
}
