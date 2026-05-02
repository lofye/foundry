<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Context\ExecutionSpecIdContinuity;
use PHPUnit\Framework\TestCase;

final class ExecutionSpecIdContinuityTest extends TestCase
{
    public function test_gaps_returns_empty_for_contiguous_top_level_and_nested_sequences(): void
    {
        $gaps = (new ExecutionSpecIdContinuity())->gaps([
            ['id' => '001', 'segments' => [1], 'path' => 'a/001.md'],
            ['id' => '002', 'segments' => [2], 'path' => 'a/002.md'],
            ['id' => '003', 'segments' => [3], 'path' => 'a/003.md'],
            ['id' => '003.001', 'segments' => [3, 1], 'path' => 'a/003.001.md'],
            ['id' => '003.002', 'segments' => [3, 2], 'path' => 'a/003.002.md'],
            ['id' => '003.001.001', 'segments' => [3, 1, 1], 'path' => 'a/003.001.001.md'],
            ['id' => '003.001.002', 'segments' => [3, 1, 2], 'path' => 'a/003.001.002.md'],
        ]);

        $this->assertSame([], $gaps);
    }

    public function test_gaps_reports_missing_top_level_id_with_top_level_parent_label(): void
    {
        $gaps = (new ExecutionSpecIdContinuity())->gaps([
            ['id' => '001', 'segments' => [1], 'path' => 'a/001.md'],
            ['id' => '003', 'segments' => [3], 'path' => 'a/003.md'],
        ]);

        $this->assertSame(
            [[
                'missing_id' => '002',
                'next_observed_id' => '003',
                'path' => 'a/003.md',
                'parent_id' => 'top-level',
            ]],
            $gaps,
        );
    }

    public function test_gaps_reports_missing_parent_before_child_group(): void
    {
        $gaps = (new ExecutionSpecIdContinuity())->gaps([
            ['id' => '009.001', 'segments' => [9, 1], 'path' => 'a/009.001.md'],
        ]);

        $this->assertSame(
            [[
                'missing_id' => '009',
                'next_observed_id' => '009.001',
                'path' => 'a/009.001.md',
                'parent_id' => '009',
            ]],
            $gaps,
        );
    }

    public function test_gaps_reports_nested_sibling_gap_and_ignores_duplicate_and_invalid_entries(): void
    {
        $gaps = (new ExecutionSpecIdContinuity())->gaps([
            ['id' => '007', 'segments' => [7], 'path' => 'a/007.md'],
            ['id' => '007.001', 'segments' => [7, 1], 'path' => 'a/007.001.md'],
            ['id' => '007.003', 'segments' => [7, 3], 'path' => 'a/007.003.md'],
            ['id' => '007.003', 'segments' => [7, 3], 'path' => 'a/dup.md'],
            ['id' => '', 'segments' => [7, 4], 'path' => 'a/empty.md'],
            ['id' => 'bad', 'segments' => [], 'path' => 'a/invalid.md'],
        ]);

        $this->assertSame(
            [[
                'missing_id' => '007.002',
                'next_observed_id' => '007.003',
                'path' => 'a/007.003.md',
                'parent_id' => '007',
            ]],
            $gaps,
        );
    }

    public function test_gaps_ignores_duplicate_ordinals_after_the_expected_slot_moves_forward(): void
    {
        $gaps = (new ExecutionSpecIdContinuity())->gaps([
            ['id' => '001', 'segments' => [1], 'path' => 'a/001.md'],
            ['id' => '001-shadow', 'segments' => [1], 'path' => 'a/001-shadow.md'],
            ['id' => '002', 'segments' => [2], 'path' => 'a/002.md'],
        ]);

        $this->assertSame([], $gaps);
    }
}
