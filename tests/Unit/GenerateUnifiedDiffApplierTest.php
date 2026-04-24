<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Generate\GenerateUnifiedDiffApplier;
use Foundry\Generate\GenerateUnifiedDiffRenderer;
use Foundry\Support\FoundryError;
use PHPUnit\Framework\TestCase;

final class GenerateUnifiedDiffApplierTest extends TestCase
{
    public function test_reverse_returns_current_content_for_empty_patch(): void
    {
        $applier = new GenerateUnifiedDiffApplier();

        $this->assertSame("after\n", $applier->reverse("after\n", '', true));
    }

    public function test_reverse_returns_current_content_for_no_textual_changes_patch(): void
    {
        $renderer = new GenerateUnifiedDiffRenderer();
        $patch = $renderer->render('notes.txt', "same\n", "same\n");
        $applier = new GenerateUnifiedDiffApplier();

        $this->assertSame("same\n", $applier->reverse("same\n", $patch, true));
    }

    public function test_reverse_returns_empty_string_when_file_previously_existed_but_was_empty(): void
    {
        $renderer = new GenerateUnifiedDiffRenderer();
        $patch = $renderer->render('notes.txt', '', "created\n");
        $applier = new GenerateUnifiedDiffApplier();

        $this->assertSame('', $applier->reverse("created\n", $patch, true));
    }

    public function test_reverse_returns_null_when_patch_reverts_file_creation(): void
    {
        $renderer = new GenerateUnifiedDiffRenderer();
        $patch = $renderer->render('notes.txt', null, "created\n");
        $applier = new GenerateUnifiedDiffApplier();

        $this->assertNull($applier->reverse("created\n", $patch, false));
    }

    public function test_reverse_throws_when_patch_context_no_longer_matches_current_content(): void
    {
        $renderer = new GenerateUnifiedDiffRenderer();
        $patch = $renderer->render('notes.txt', "before\n", "after\n");
        $applier = new GenerateUnifiedDiffApplier();

        $this->expectException(FoundryError::class);
        $this->expectExceptionMessage('Persisted rollback patch no longer matches the current file contents.');

        $applier->reverse("drifted\n", $patch, true);
    }
}
