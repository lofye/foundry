<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Context\StateDocumentNormalizer;
use PHPUnit\Framework\TestCase;

final class StateDocumentNormalizerTest extends TestCase
{
    public function test_duplicate_bullets_are_removed_deterministically_within_a_section(): void
    {
        $normalized = $this->normalizer()->normalize(<<<'MD'
# Feature: payments

## Purpose

Track payment state.

## Current State

- Payments API is available.
- Payments API is available.
-   Payments API is available.

## Open Questions

- TBD.

## Next Steps

- Add retry coverage.
MD);

        $this->assertSame(<<<'MD'
# Feature: payments

## Purpose

Track payment state.

## Current State

- Payments API is available.

## Open Questions

- TBD.

## Next Steps

- Add retry coverage.
MD . "\n", $normalized);
    }

    public function test_canonical_section_order_is_enforced_when_sections_are_present(): void
    {
        $normalized = $this->normalizer()->normalize(<<<'MD'
# Feature: payments

## Purpose

Track payment state.

## Next Steps

- Add retry coverage.

## Current State

- Payments API is available.

## Open Questions

- Which gateway should become default?
MD);

        $this->assertStringContainsString(<<<'MD'
## Current State

- Payments API is available.

## Open Questions

- Which gateway should become default?

## Next Steps

- Add retry coverage.
MD, $normalized);
    }

    public function test_already_normalized_documents_remain_stable_after_rerun(): void
    {
        $contents = <<<'MD'
# Feature: payments

## Purpose

Track payment state.

## Current State

- Payments API is available.

## Open Questions

- Which gateway should become default?

## Next Steps

- Add retry coverage.
MD;

        $first = $this->normalizer()->normalize($contents);
        $second = $this->normalizer()->normalize($first);

        $this->assertSame($first, $second);
    }

    public function test_current_state_cleanup_is_conservative_and_preserves_legitimate_current_facts(): void
    {
        $normalized = $this->normalizer()->normalize(<<<'MD'
# Feature: payments

## Purpose

Track payment state.

## Current State

- Feature spec created.
- 35D7B implementation completed.
- Payments API is available.

## Open Questions

- TBD.

## Next Steps

- Add retry coverage.
MD);

        $this->assertStringNotContainsString('Feature spec created.', $normalized);
        $this->assertStringNotContainsString('35D7B implementation completed.', $normalized);
        $this->assertStringContainsString('- Payments API is available.', $normalized);
    }

    public function test_next_steps_cleanup_removes_obvious_completed_leftovers(): void
    {
        $normalized = $this->normalizer()->normalize(<<<'MD'
# Feature: payments

## Purpose

Track payment state.

## Current State

- Payments API is available.

## Open Questions

- TBD.

## Next Steps

- Payments API is available.
- Implemented Payments API is available.
- 35D7B implementation completed.
- Add retry coverage.
MD);

        $this->assertStringNotContainsString("\n- Payments API is available.\n\n## Next Steps", $normalized);
        $this->assertStringNotContainsString('Implemented Payments API is available.', $normalized);
        $this->assertStringNotContainsString('35D7B implementation completed.', $normalized);
        $this->assertStringContainsString("- Add retry coverage.\n", $normalized);
    }

    public function test_next_steps_cleanup_uses_current_state_even_when_sections_are_out_of_order(): void
    {
        $normalized = $this->normalizer()->normalize(<<<'MD'
# Feature: payments

## Purpose

Track payment state.

## Next Steps

- Payments API is available.

## Current State

- Payments API is available.

## Open Questions

- TBD.
MD);

        $this->assertStringContainsString(<<<'MD'
## Current State

- Payments API is available.

## Open Questions

- TBD.

## Next Steps
MD, $normalized);
        $this->assertStringNotContainsString("## Next Steps\n\n- Payments API is available.\n", $normalized);
    }

    public function test_normalization_does_not_invent_missing_sections_or_content(): void
    {
        $normalized = $this->normalizer()->normalize(<<<'MD'
# Feature: payments

## Purpose

Track payment state.

## Current State

- Payments API is available.
MD);

        $this->assertStringContainsString("## Current State\n\n- Payments API is available.\n", $normalized);
        $this->assertStringNotContainsString('## Open Questions', $normalized);
        $this->assertStringNotContainsString('## Next Steps', $normalized);
    }

    private function normalizer(): StateDocumentNormalizer
    {
        return new StateDocumentNormalizer();
    }
}
