<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Context\AlignmentChecker;
use PHPUnit\Framework\TestCase;

final class AlignmentCheckerTest extends TestCase
{
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

    public function test_state_claim_unsupported_by_spec_is_reported(): void
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
        $this->assertContains('missing_decision_reference', $codes);
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

    public function test_output_shape_is_stable(): void
    {
        $payload = $this->checker()->check(
            $this->spec(expectedBehavior: ['Publishes posts.']),
            $this->state(currentState: ['Publishes posts.']),
            '',
        )->toArray('event-bus');

        $this->assertSame(['status', 'feature', 'issues', 'required_actions'], array_keys($payload));
        $this->assertSame('ok', $payload['status']);
        $this->assertSame('event-bus', $payload['feature']);
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
