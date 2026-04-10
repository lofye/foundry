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
        $this->assertSame($first['slug'], $second['slug']);
        $this->assertSame('add-rss-feed-support', $first['slug']);
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
            ['Add RSS feed support for published posts.'],
            $plan['scope'],
        );
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
