<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Context\ExecutionSpecPlanner;
use PHPUnit\Framework\TestCase;

final class ExecutionSpecPlannerTest extends TestCase
{
    public function test_slug_generation_is_deterministic(): void
    {
        $planner = new ExecutionSpecPlanner();
        $input = $this->executionInput(
            currentState: ['Blog feature scaffolding exists in the app.'],
            nextSteps: ['Add RSS feed support for published posts.'],
            specTrackingItems: ['Add RSS feed support for published posts.'],
        );

        $first = $planner->plan('blog', $input);
        $second = $planner->plan('blog', $input);

        $this->assertIsArray($first);
        $this->assertIsArray($second);
        $this->assertSame($first, $second);
        $this->assertSame('rss-feed-published-posts', $first['slug']);
    }

    public function test_bounded_requested_changes_are_derived_from_simple_context_gaps(): void
    {
        $planner = new ExecutionSpecPlanner();
        $input = $this->executionInput(
            currentState: ['Blog feature scaffolding exists in the app.'],
            nextSteps: [
                'Add RSS feed support for published posts.',
                'Add comment submission support.',
            ],
            specTrackingItems: [
                'Blog feature scaffolding exists in the app.',
                'Add RSS feed support for published posts.',
                'Add comment submission support.',
            ],
        );

        $plan = $planner->plan('blog', $input);

        $this->assertIsArray($plan);
        $this->assertSame(
            ['Add RSS feed support for published posts.'],
            $plan['requested_changes'],
        );
        $this->assertSame(
            ['RSS feed support for published posts.'],
            $plan['scope'],
        );
        $this->assertSame(
            'Current State does not yet reflect RSS feed support for published posts, so this is the next bounded step now.',
            $plan['purpose'],
        );
    }

    public function test_reordering_irrelevant_inputs_does_not_change_output(): void
    {
        $planner = new ExecutionSpecPlanner();

        $ordered = $this->executionInput(
            currentState: [
                'Blog feature scaffolding exists in the app.',
                'Contract tests already cover the publish endpoint.',
            ],
            nextSteps: [
                'Add RSS feed support for published posts.',
                'Document the publish endpoint.',
            ],
            specTrackingItems: [
                'Document the publish endpoint.',
                'Add RSS feed support for published posts.',
                'Blog feature scaffolding exists in the app.',
            ],
        );

        $reordered = $this->executionInput(
            currentState: [
                'Contract tests already cover the publish endpoint.',
                'Blog feature scaffolding exists in the app.',
            ],
            nextSteps: [
                'Add RSS feed support for published posts.',
                'Document the publish endpoint.',
            ],
            specTrackingItems: [
                'Document the publish endpoint.',
                'Add RSS feed support for published posts.',
                'Blog feature scaffolding exists in the app.',
            ],
        );

        $this->assertSame(
            $planner->plan('blog', $ordered),
            $planner->plan('blog', $reordered),
        );
    }

    public function test_known_fixture_input_produces_fixed_expected_output(): void
    {
        $planner = new ExecutionSpecPlanner();
        $input = $this->executionInput(
            currentState: ['Event bus feature scaffolding exists in the app.'],
            nextSteps: ['Add contract test coverage for the event bus feature.'],
            specTrackingItems: [
                'Event bus feature scaffolding exists in the app.',
                'Add contract test coverage for the event bus feature.',
            ],
        );

        $this->assertSame([
            'slug' => 'contract-test-coverage',
            'purpose' => 'Current State does not yet reflect contract test coverage for the event bus feature, so this is the next bounded step now.',
            'scope' => ['Event bus contract-test coverage and generated verification.'],
            'constraints' => [
                'Keep canonical feature context authoritative.',
                'Keep generated execution specs secondary to canonical feature truth.',
                'Keep this work deterministic and bounded to one coherent step.',
                'Respect prior decisions recorded in docs/features/event-bus.decisions.md.',
            ],
            'requested_changes' => ['Add contract test coverage for the event bus feature.'],
        ], $planner->plan('event-bus', $input));
    }

    public function test_non_actionable_gap_is_rejected_instead_of_generating_tautological_output(): void
    {
        $planner = new ExecutionSpecPlanner();
        $input = $this->executionInput(
            currentState: ['Plan feature generates the next bounded execution spec deterministically under docs/specs/<feature>/<NNN-name>.md.'],
            nextSteps: ['Keep later execution systems safely consumable from canonical feature context files.'],
            specTrackingItems: [
                'Plan feature generates the next bounded execution spec deterministically under docs/specs/<feature>/<NNN-name>.md.',
                'Later execution systems can consume canonical feature context files safely.',
            ],
        );

        $plan = $planner->plan('context-persistence', $input);

        $this->assertNull($plan);
    }

    /**
     * @param list<string> $currentState
     * @param list<string> $nextSteps
     * @param list<string> $specTrackingItems
     * @return array{
     *     feature:string,
     *     mode:string,
     *     paths:array{spec:string,state:string,decisions:string,feature_base:string,manifest:string,prompts:string},
     *     spec:array<string,string>,
     *     state:array<string,string>,
     *     decisions:list<array<string,string>>,
     *     spec_tracking_items:list<string>,
     *     description:string,
     *     execution_summary:string
     * }
     */
    private function executionInput(array $currentState, array $nextSteps, array $specTrackingItems): array
    {
        return [
            'feature' => 'blog',
            'mode' => 'new',
            'paths' => [
                'spec' => 'docs/features/blog.spec.md',
                'state' => 'docs/features/blog.md',
                'decisions' => 'docs/features/blog.decisions.md',
                'feature_base' => 'app/features/blog',
                'manifest' => 'app/features/blog/feature.yaml',
                'prompts' => 'app/features/blog/prompts.md',
            ],
            'spec' => [],
            'state' => [
                'Current State' => $this->bulletList($currentState),
                'Next Steps' => $this->bulletList($nextSteps),
            ],
            'decisions' => [],
            'spec_tracking_items' => $specTrackingItems,
            'description' => 'Blog feature.',
            'execution_summary' => 'Blog feature summary.',
        ];
    }

    /**
     * @param list<string> $items
     */
    private function bulletList(array $items): string
    {
        return implode("\n", array_map(
            static fn(string $item): string => '- ' . $item,
            $items,
        ));
    }
}
