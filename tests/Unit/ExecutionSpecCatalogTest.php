<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Context\ExecutionSpecCatalog;
use Foundry\Support\FoundryError;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class ExecutionSpecCatalogTest extends TestCase
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

    public function test_assert_contiguous_passes_when_active_and_draft_sequences_are_independently_gapless(): void
    {
        $entries = [
            $this->entry('feature-a', 'active', '001', [1], 'docs/features/feature-a/specs/001-a.md'),
            $this->entry('feature-a', 'active', '002', [2], 'docs/features/feature-a/specs/002-b.md'),
            $this->entry('feature-a', 'draft', '001', [1], 'docs/features/feature-a/specs/drafts/001-a.md'),
            $this->entry('feature-a', 'draft', '002', [2], 'docs/features/feature-a/specs/drafts/002-b.md'),
            $this->entry('feature-a', 'draft', '002.001', [2, 1], 'docs/features/feature-a/specs/drafts/002.001-c.md'),
        ];

        (new ExecutionSpecCatalog(new Paths($this->project->root)))->assertContiguous('feature-a', $entries);
        $this->assertTrue(true);
    }

    public function test_assert_contiguous_reports_active_location_gap_with_expected_details(): void
    {
        $catalog = new ExecutionSpecCatalog(new Paths($this->project->root));

        $error = $this->expectFoundryError(function () use ($catalog): void {
            $catalog->assertContiguous('feature-a', [
                $this->entry('feature-a', 'active', '001', [1], 'docs/features/feature-a/specs/001-a.md'),
                $this->entry('feature-a', 'active', '003', [3], 'docs/features/feature-a/specs/003-c.md'),
                $this->entry('feature-a', 'draft', '001', [1], 'docs/features/feature-a/specs/drafts/001-a.md'),
            ]);
        });

        $this->assertSame('EXECUTION_SPEC_ID_SEQUENCE_INVALID', $error->errorCode);
        $this->assertSame('active', $error->details['location']);
        $this->assertSame('top-level', $error->details['parent_id']);
        $this->assertSame('002', $error->details['missing_id']);
        $this->assertSame('003', $error->details['next_observed_id']);
    }

    public function test_next_root_id_blocks_when_draft_location_has_gap(): void
    {
        $this->writeSpec('feature-a', '001-first');
        $this->writeSpec('feature-a', '002-second');
        $this->writeSpec('feature-a', '004-draft-first', 'drafts');
        $this->writeSpec('feature-a', '006-draft-third', 'drafts');

        $error = $this->expectFoundryError(function (): void {
            (new ExecutionSpecCatalog(new Paths($this->project->root)))->nextRootId('feature-a');
        });

        $this->assertSame('EXECUTION_SPEC_ID_SEQUENCE_INVALID', $error->errorCode);
        $this->assertSame('drafts', $error->details['location']);
        $this->assertSame('005', $error->details['missing_id']);
    }

    public function test_entries_fails_when_active_specs_path_is_blocked_file(): void
    {
        $blocked = $this->project->root . '/docs/features/feature-a/specs';
        if (!is_dir(dirname($blocked))) {
            mkdir(dirname($blocked), 0777, true);
        }
        file_put_contents($blocked, 'blocked');

        $error = $this->expectFoundryError(function (): void {
            (new ExecutionSpecCatalog(new Paths($this->project->root)))->entries('feature-a');
        });

        $this->assertSame('EXECUTION_SPEC_ID_ALLOCATION_FAILED', $error->errorCode);
        $this->assertSame('docs/features/feature-a/specs', $error->details['blocked_path']);
    }

    public function test_entries_fails_when_invalid_filename_exists_in_catalog_scope(): void
    {
        $this->writeRawFile('docs/features/feature-a/specs/not-a-spec.md', '# Execution Spec: not-a-spec' . "\n");

        $error = $this->expectFoundryError(function (): void {
            (new ExecutionSpecCatalog(new Paths($this->project->root)))->entries('feature-a');
        });

        $this->assertSame('EXECUTION_SPEC_ID_ALLOCATION_FAILED', $error->errorCode);
        $this->assertSame(['docs/features/feature-a/specs/not-a-spec.md'], $error->details['invalid_paths']);
    }

    public function test_entries_fails_when_duplicate_ids_exist_across_active_and_drafts(): void
    {
        $this->writeSpec('feature-a', '001-first');
        $this->writeSpec('feature-a', '001-second', 'drafts');

        $error = $this->expectFoundryError(function (): void {
            (new ExecutionSpecCatalog(new Paths($this->project->root)))->entries('feature-a');
        });

        $this->assertSame('EXECUTION_SPEC_ID_ALLOCATION_FAILED', $error->errorCode);
        $this->assertArrayHasKey('001', $error->details['duplicate_ids']);
    }

    public function test_next_root_id_returns_first_missing_top_level_slot_for_valid_sequences(): void
    {
        $this->writeSpec('feature-a', '001-first');
        $this->writeSpec('feature-a', '002-second');
        $this->writeSpec('feature-a', '004-fourth');
        $this->writeSpec('feature-a', '003-third');
        $this->writeSpec('feature-a', '005-draft-first', 'drafts');
        $this->writeSpec('feature-a', '006-draft-second', 'drafts');

        $next = (new ExecutionSpecCatalog(new Paths($this->project->root)))->nextRootId('feature-a');
        $this->assertSame('007', $next);
    }

    public function test_entries_returns_sorted_relative_paths_with_statuses(): void
    {
        $this->writeSpec('feature-a', '002-second');
        $this->writeSpec('feature-a', '001-first');
        $this->writeSpec('feature-a', '003-draft-first', 'drafts');

        $entries = (new ExecutionSpecCatalog(new Paths($this->project->root)))->entries('feature-a');

        $this->assertSame(
            [
                'docs/features/feature-a/specs/001-first.md',
                'docs/features/feature-a/specs/002-second.md',
                'docs/features/feature-a/specs/drafts/003-draft-first.md',
            ],
            array_map(static fn(array $entry): string => (string) $entry['path'], $entries),
        );
        $this->assertSame(['active', 'active', 'draft'], array_map(static fn(array $entry): string => (string) $entry['status'], $entries));
    }

    private function writeSpec(string $feature, string $name, string $subdirectory = ''): void
    {
        $directory = $this->project->root . '/docs/features/' . $feature . '/specs' . ($subdirectory !== '' ? '/' . $subdirectory : '');
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($directory . '/' . $name . '.md', '# Execution Spec: ' . $name . "\n");
    }

    private function writeRawFile(string $relativePath, string $contents): void
    {
        $absolutePath = $this->project->root . '/' . $relativePath;
        $directory = dirname($absolutePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($absolutePath, $contents);
    }

    /**
     * @return array{
     *   feature:string,status:string,path:string,name:string,id:string,slug:string,segments:list<int>,parent_id:?string
     * }
     */
    private function entry(string $feature, string $status, string $id, array $segments, string $path): array
    {
        return [
            'feature' => $feature,
            'status' => $status,
            'path' => $path,
            'name' => $id . '-x',
            'id' => $id,
            'slug' => 'x',
            'segments' => $segments,
            'parent_id' => count($segments) > 1 ? implode('.', array_map(static fn(int $s): string => sprintf('%03d', $s), array_slice($segments, 0, -1))) : null,
        ];
    }

    private function expectFoundryError(callable $fn): FoundryError
    {
        try {
            $fn();
        } catch (FoundryError $error) {
            return $error;
        }

        self::fail('Expected FoundryError was not thrown.');
    }
}
