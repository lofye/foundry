<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Pro\Generation\PromptFeaturePlanner;
use PHPUnit\Framework\TestCase;

final class PromptFeaturePlannerTest extends TestCase
{
    public function test_plan_derives_graph_aware_feature_deterministically(): void
    {
        $planner = new PromptFeaturePlanner();

        $plan = $planner->plan('Add bookmark support', $this->bundleFixture(), [], true);

        $this->assertSame('bookmark_post', $plan['feature']['feature']);
        $this->assertSame('POST', $plan['feature']['route']['method']);
        $this->assertSame('/posts/{id}/bookmark', $plan['feature']['route']['path']);
        $this->assertSame(['posts.bookmark'], $plan['feature']['auth']['permissions']);
        $this->assertSame(['post.bookmarked'], $plan['feature']['events']['emit']);
        $this->assertNull($plan['workflow']);
        $this->assertTrue($plan['trace']['deterministic']);
        $this->assertSame(['publish_post'], $plan['trace']['selected_features']);
    }

    public function test_plan_can_derive_workflow_for_approval_prompts(): void
    {
        $planner = new PromptFeaturePlanner();

        $plan = $planner->plan('Add post approval workflow', $this->bundleFixture(), [], true);

        $this->assertIsArray($plan['workflow']);
        $this->assertSame('posts_approval', $plan['workflow']['name']);
        $this->assertSame('posts', $plan['workflow']['definition']['resource']);
        $this->assertSame(['approved', 'draft', 'pending_review'], $plan['workflow']['definition']['states']);
        $this->assertArrayHasKey('approve', $plan['workflow']['definition']['transitions']);
        $this->assertArrayHasKey('submit', $plan['workflow']['definition']['transitions']);
    }

    /**
     * @return array<string,mixed>
     */
    private function bundleFixture(): array
    {
        return [
            'selected_features' => ['publish_post'],
            'tokens' => ['add', 'bookmark', 'support'],
            'context_bundle' => [
                'nodes' => [
                    [
                        'id' => 'feature:publish_post',
                        'type' => 'feature',
                        'payload' => [
                            'feature' => 'publish_post',
                            'route' => ['method' => 'POST', 'path' => '/posts'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
