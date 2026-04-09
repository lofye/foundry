<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Context\AlignmentChecker;
use PHPUnit\Framework\TestCase;

final class AlignmentCheckerTest extends TestCase
{
    public function test_repeated_untracked_requirement_issues_are_deduplicated(): void
    {
        $result = $this->checker()->check(
            $this->spec(
                expectedBehavior: ['Events are replayable.'],
                acceptanceCriteria: ['Events are replayable.', 'Events are replayable.'],
            ),
            $this->state(currentState: ['Replay support is pending.']),
            '',
        );

        $codes = array_values(array_filter(array_map(
            static fn($issue): ?string => $issue->code === 'untracked_spec_requirement' ? $issue->code : null,
            $result->issues,
        )));

        $this->assertSame(['untracked_spec_requirement'], $codes);
    }

    public function test_spec_requirement_missing_from_state_is_reported(): void
    {
        $result = $this->checker()->check(
            $this->spec(acceptanceCriteria: ['Events are replayable.']),
            $this->state(currentState: ['Replay support is pending.']),
            '',
        );

        $codes = array_values(array_map(
            static fn($issue): string => $issue->code,
            $result->issues,
        ));

        $this->assertSame('mismatch', $result->status);
        $this->assertContains('untracked_spec_requirement', $codes);
    }

    public function test_repeated_unsupported_state_claims_are_grouped_cleanly(): void
    {
        $result = $this->checker()->check(
            $this->spec(expectedBehavior: ['Publishes posts.']),
            $this->state(currentState: ['Comments are enabled.', 'Comments are enabled.']),
            '',
        );

        $codes = array_values(array_map(
            static fn($issue): string => $issue->code,
            $result->issues,
        ));

        $this->assertSame('mismatch', $result->status);
        $this->assertSame(['unsupported_state_claim'], array_values(array_filter(
            $codes,
            static fn(string $code): bool => $code === 'unsupported_state_claim',
        )));
    }

    public function test_divergence_with_decision_reference_is_treated_as_warning(): void
    {
        $result = $this->checker()->check(
            $this->spec(expectedBehavior: ['Publishes posts.']),
            $this->state(currentState: ['Publishes posts.', 'Comments are enabled.']),
            $this->decisions('Comments are enabled temporarily.'),
        );

        $this->assertSame('warning', $result->status);
        $this->assertSame('possible_mismatch', $result->issues[0]->code);
        $this->assertTrue($result->issues[0]->decision_reference_found);
    }

    public function test_obvious_normalized_phrasing_matches_are_treated_as_grounded(): void
    {
        $result = $this->checker()->check(
            $this->spec(acceptanceCriteria: ['CLI can initialize missing context files deterministically.']),
            $this->state(currentState: [
                'Context init command implemented.',
                'Canonical feature context can be initialized deterministically.',
            ]),
            '',
        );

        $this->assertSame('ok', $result->status);
        $this->assertSame([], $result->issues);
    }

    public function test_weak_state_sections_produce_warning_consistently(): void
    {
        $result = $this->checker()->check(
            $this->spec(),
            $this->state(),
            '',
        );

        $this->assertSame('warning', $result->status);
        $this->assertSame('possible_mismatch', $result->issues[0]->code);
    }

    public function test_real_mismatches_still_produce_mismatch(): void
    {
        $result = $this->checker()->check(
            $this->spec(expectedBehavior: ['Publishes posts.']),
            $this->state(currentState: ['Comments are enabled.']),
            '',
        );

        $codes = array_values(array_map(
            static fn($issue): string => $issue->code,
            $result->issues,
        ));

        $this->assertSame('mismatch', $result->status);
        $this->assertContains('unsupported_state_claim', $codes);
    }

    public function test_output_shape_is_stable(): void
    {
        $payload = $this->checker()->check(
            $this->spec(expectedBehavior: ['Publishes posts.']),
            $this->state(currentState: ['Publishes posts.']),
            '',
        )->toArray('event-bus');

        $this->assertSame(['status', 'feature', 'can_proceed', 'requires_repair', 'issues', 'required_actions'], array_keys($payload));
        $this->assertSame('ok', $payload['status']);
        $this->assertSame('event-bus', $payload['feature']);
        $this->assertTrue($payload['can_proceed']);
        $this->assertFalse($payload['requires_repair']);
        $this->assertSame([], $payload['issues']);
        $this->assertSame([], $payload['required_actions']);
    }

    private function checker(): AlignmentChecker
    {
        return new AlignmentChecker();
    }

    /**
     * @param array<int,string> $expectedBehavior
     * @param array<int,string> $acceptanceCriteria
     */
    private function spec(array $expectedBehavior = ['TBD.'], array $acceptanceCriteria = ['TBD.']): string
    {
        return <<<MD
        # Feature Spec: event-bus

        ## Purpose

        TBD.

        ## Goals

        - TBD.

        ## Non-Goals

        - TBD.

        ## Constraints

        - TBD.

        ## Expected Behavior

        {$this->paragraphOrList($expectedBehavior)}

        ## Acceptance Criteria

        {$this->bulletList($acceptanceCriteria)}

        ## Assumptions

        - TBD.
        MD;
    }

    /**
     * @param array<int,string> $currentState
     * @param array<int,string> $openQuestions
     * @param array<int,string> $nextSteps
     */
    private function state(
        array $currentState = ['TBD.'],
        array $openQuestions = ['TBD.'],
        array $nextSteps = ['TBD.'],
    ): string {
        return <<<MD
        # Feature: event-bus

        ## Purpose

        TBD.

        ## Current State

        {$this->paragraphOrList($currentState)}

        ## Open Questions

        {$this->bulletList($openQuestions)}

        ## Next Steps

        {$this->bulletList($nextSteps)}
        MD;
    }

    private function decisions(string $entryText): string
    {
        return <<<MD
        ### Decision: Temporary divergence

        Timestamp: 2026-04-09T10:00:00Z

        **Context**

        {$entryText}

        **Decision**

        {$entryText}

        **Reasoning**

        {$entryText}

        **Alternatives Considered**

        - Keep the spec unchanged.

        **Impact**

        {$entryText}

        **Spec Reference**

        Expected Behavior
        MD;
    }

    /**
     * @param array<int,string> $items
     */
    private function paragraphOrList(array $items): string
    {
        if (count($items) === 1) {
            return $items[0];
        }

        return implode(PHP_EOL, array_map(
            static fn(string $item): string => '- ' . $item,
            $items,
        ));
    }

    /**
     * @param array<int,string> $items
     */
    private function bulletList(array $items): string
    {
        return implode(PHP_EOL, array_map(
            static fn(string $item): string => '- ' . $item,
            $items,
        ));
    }
}
