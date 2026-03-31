<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Explain\Diff\ExplainDiffService;
use Foundry\Explain\Snapshot\ExplainSnapshotService;
use Foundry\Support\ApiSurfaceRegistry;
use Foundry\Support\FoundryError;
use Foundry\Support\Json;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class ExplainDiffServiceTest extends TestCase
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

    public function test_compare_detects_changes_and_sorts_deterministically(): void
    {
        $service = $this->service();

        $before = [
            'schema_version' => 1,
            'metadata' => ['explain_schema_version' => 2],
            'categories' => [
                'packs' => [
                    [
                        'id' => 'foundry/blog',
                        'type' => 'pack',
                        'label' => 'foundry/blog',
                        'origin' => 'extension',
                        'extension' => 'foundry/blog',
                        'version' => '1.0.0',
                    ],
                ],
                'routes' => [
                    [
                        'id' => 'GET /blog',
                        'type' => 'route',
                        'label' => 'GET /blog',
                        'origin' => 'extension',
                        'extension' => 'foundry/blog',
                    ],
                ],
            ],
        ];
        $after = [
            'schema_version' => 1,
            'metadata' => ['explain_schema_version' => 2],
            'categories' => [
                'packs' => [
                    [
                        'id' => 'foundry/blog',
                        'type' => 'pack',
                        'label' => 'foundry/blog',
                        'origin' => 'extension',
                        'extension' => 'foundry/blog',
                        'version' => '1.1.0',
                    ],
                ],
                'routes' => [
                    [
                        'id' => 'POST /comments',
                        'type' => 'route',
                        'label' => 'POST /comments',
                        'origin' => 'core',
                        'extension' => null,
                    ],
                ],
            ],
        ];

        $diff = $service->compare($before, $after);

        $this->assertSame(['added' => 1, 'removed' => 1, 'modified' => 1], $diff['summary']);
        $this->assertArrayHasKey('confidence', $diff);
        $this->assertSame('route', $diff['added'][0]['type']);
        $this->assertSame('POST /comments', $diff['added'][0]['id']);
        $this->assertSame('GET /blog', $diff['removed'][0]['id']);
        $this->assertSame('pack', $diff['modified'][0]['type']);
        $this->assertSame('1.0.0', $diff['modified'][0]['before']['version']);
        $this->assertSame('1.1.0', $diff['modified'][0]['after']['version']);
    }

    public function test_compare_rejects_incompatible_snapshot_versions(): void
    {
        $service = $this->service();

        $this->expectException(FoundryError::class);
        $this->expectExceptionMessage('snapshot versions are incompatible');

        $service->compare(
            [
                'schema_version' => 1,
                'metadata' => ['explain_schema_version' => 2],
                'categories' => [],
            ],
            [
                'schema_version' => 1,
                'metadata' => ['explain_schema_version' => 3],
                'categories' => [],
            ],
        );
    }

    public function test_load_last_validates_snapshot_compatibility(): void
    {
        $service = $this->service();
        mkdir($this->project->root . '/.foundry/snapshots', 0777, true);
        mkdir($this->project->root . '/.foundry/diffs', 0777, true);

        file_put_contents($this->project->root . '/.foundry/snapshots/pre-generate.json', Json::encode([
            'schema_version' => 1,
            'label' => 'pre-generate',
            'metadata' => ['explain_schema_version' => 2],
            'categories' => [],
        ], true));
        file_put_contents($this->project->root . '/.foundry/snapshots/post-generate.json', Json::encode([
            'schema_version' => 1,
            'label' => 'post-generate',
            'metadata' => ['explain_schema_version' => 9],
            'categories' => [],
        ], true));
        file_put_contents($this->project->root . '/.foundry/diffs/last.json', Json::encode([
            'schema_version' => 1,
            'summary' => ['added' => 0, 'removed' => 0, 'modified' => 0],
            'added' => [],
            'removed' => [],
            'modified' => [],
            'confidence' => ['score' => 0.5, 'band' => 'medium', 'factors' => [], 'warnings' => [], 'metadata' => ['schema_version' => 1]],
        ], true));

        $this->expectException(FoundryError::class);
        $this->expectExceptionMessage('snapshot versions are incompatible');

        $service->loadLast();
    }

    private function service(): ExplainDiffService
    {
        $paths = Paths::fromCwd($this->project->root);
        $snapshots = new ExplainSnapshotService($paths, new ApiSurfaceRegistry());

        return new ExplainDiffService($paths, $snapshots);
    }
}
