<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Context\FeatureSpecDocumentNormalizer;
use PHPUnit\Framework\TestCase;

final class FeatureSpecDocumentNormalizerTest extends TestCase
{
    public function test_duplicate_bullets_are_removed_deterministically_within_a_section(): void
    {
        $normalized = $this->normalizer()->normalize(<<<'MD'
# Feature Spec: payments

## Purpose

Track payment state.

## Goals

- Add retry coverage.
- Add retry coverage.
-   Add retry coverage.

## Non-Goals

- Do not change gateway selection.

## Constraints

- Keep output deterministic.

## Expected Behavior

- Payments retry automatically.

## Acceptance Criteria

- Retries are visible in logs.

## Assumptions

- Retry support remains synchronous.
MD);

        $this->assertStringContainsString(<<<'MD'
## Goals

- Add retry coverage.
MD, $normalized);
        $this->assertSame(1, substr_count($normalized, "- Add retry coverage.\n"));
    }

    public function test_canonical_section_order_is_enforced_when_sections_are_present(): void
    {
        $normalized = $this->normalizer()->normalize(<<<'MD'
# Feature Spec: payments

## Constraints

- Keep output deterministic.

## Purpose

Track payment state.

## Acceptance Criteria

- Retries are visible in logs.

## Goals

- Add retry coverage.

## Expected Behavior

- Payments retry automatically.

## Non-Goals

- Do not change gateway selection.

## Assumptions

- Retry support remains synchronous.
MD);

        $this->assertStringContainsString(<<<'MD'
## Purpose

Track payment state.

## Goals

- Add retry coverage.

## Non-Goals

- Do not change gateway selection.

## Constraints

- Keep output deterministic.

## Expected Behavior

- Payments retry automatically.

## Acceptance Criteria

- Retries are visible in logs.

## Assumptions

- Retry support remains synchronous.
MD, $normalized);
    }

    public function test_already_normalized_feature_specs_remain_stable_after_rerun(): void
    {
        $contents = <<<'MD'
# Feature Spec: payments

## Purpose

Track payment state.

## Goals

- Add retry coverage.

## Non-Goals

- Do not change gateway selection.

## Constraints

- Keep output deterministic.

## Expected Behavior

- Payments retry automatically.

## Acceptance Criteria

- Retries are visible in logs.

## Assumptions

- Retry support remains synchronous.
MD;

        $first = $this->normalizer()->normalize($contents);
        $second = $this->normalizer()->normalize($first);

        $this->assertSame($first, $second);
    }

    public function test_similar_but_distinct_bullets_are_preserved(): void
    {
        $normalized = $this->normalizer()->normalize(<<<'MD'
# Feature Spec: payments

## Purpose

Track payment state.

## Goals

- Add retry coverage.
- Add retry coverage for failed webhooks.

## Non-Goals

- Do not change gateway selection.

## Constraints

- Keep output deterministic.

## Expected Behavior

- Payments retry automatically.

## Acceptance Criteria

- Retries are visible in logs.

## Assumptions

- Retry support remains synchronous.
MD);

        $this->assertStringContainsString("- Add retry coverage.\n- Add retry coverage for failed webhooks.\n", $normalized);
    }

    public function test_normalization_does_not_invent_missing_sections_or_content(): void
    {
        $normalized = $this->normalizer()->normalize(<<<'MD'
# Feature Spec: payments

## Purpose

Track payment state.

## Goals

- Add retry coverage.
MD);

        $this->assertStringContainsString("## Purpose\n\nTrack payment state.\n", $normalized);
        $this->assertStringContainsString("## Goals\n\n- Add retry coverage.\n", $normalized);
        $this->assertStringNotContainsString('## Constraints', $normalized);
        $this->assertStringNotContainsString('## Acceptance Criteria', $normalized);
    }

    private function normalizer(): FeatureSpecDocumentNormalizer
    {
        return new FeatureSpecDocumentNormalizer();
    }
}
