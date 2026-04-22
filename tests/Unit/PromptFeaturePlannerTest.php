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

    public function test_plan_normalizes_provider_overrides_and_invalid_route_data(): void
    {
        $planner = new PromptFeaturePlanner();

        $plan = $planner->plan('Review posts', $this->bundleFixture(), [
            'feature' => [
                'feature' => 'Review Post',
                'description' => '',
                'owners' => ['ops', 'ops', 'product'],
                'route' => [
                    'method' => 'TRACE',
                    'path' => 'review',
                ],
                'input' => [
                    'fields' => [
                        'Post ID' => ['type' => 'uuid', 'required' => true],
                        7 => 'ignored',
                    ],
                ],
                'auth' => [
                    'required' => false,
                    'strategies' => ['bearer', 'session', 'bearer'],
                    'permissions' => ['posts.review', 'posts.review'],
                ],
            ],
            'workflow' => [
                'name' => 'Posts Approval',
                'definition' => [
                    'resource' => 'posts',
                    'states' => ['approved', 'pending_review', 'approved'],
                    'transitions' => [
                        'approve' => ['from' => ['pending_review'], 'to' => 'approved'],
                    ],
                ],
            ],
            'explanation' => ' Provider override ',
        ]);

        $this->assertSame('review_post', $plan['feature']['feature']);
        $this->assertSame('Generated feature.', $plan['feature']['description']);
        $this->assertSame(['ops', 'product'], $plan['feature']['owners']);
        $this->assertSame('POST', $plan['feature']['route']['method']);
        $this->assertSame('/generated', $plan['feature']['route']['path']);
        $this->assertArrayHasKey('post_id', $plan['feature']['input']['fields']);
        $this->assertSame('uuid', $plan['feature']['input']['fields']['post_id']['type']);
        $this->assertArrayHasKey('comment', $plan['feature']['input']['fields']);
        $this->assertArrayHasKey('id', $plan['feature']['input']['fields']);
        $this->assertSame(['bearer', 'session'], $plan['feature']['auth']['strategies']);
        $this->assertSame(['posts.review'], $plan['feature']['auth']['permissions']);
        $this->assertSame('posts_approval', $plan['workflow']['name']);
        $this->assertSame(['approved', 'pending_review'], $plan['workflow']['definition']['states']);
        $this->assertSame('Provider override', $plan['explanation']);
    }

    public function test_plan_derives_read_and_delete_actions_from_instruction_keywords(): void
    {
        $planner = new PromptFeaturePlanner();

        $viewPlan = $planner->plan('Fetch posts for reporting', $this->bundleFixture(), [], true);
        $deletePlan = $planner->plan('Remove posts on request', $this->bundleFixture(), [], true);

        $this->assertSame('view_post', $viewPlan['feature']['feature']);
        $this->assertSame('GET', $viewPlan['feature']['route']['method']);
        $this->assertSame('/posts/{id}', $viewPlan['feature']['route']['path']);
        $this->assertSame('view', $viewPlan['trace']['derived_action']);

        $this->assertSame('delete_post', $deletePlan['feature']['feature']);
        $this->assertSame('DELETE', $deletePlan['feature']['route']['method']);
        $this->assertSame('/posts/{id}', $deletePlan['feature']['route']['path']);
        $this->assertSame('delete', $deletePlan['trace']['derived_action']);
    }

    public function test_response_schema_exposes_expected_top_level_sections(): void
    {
        $schema = (new PromptFeaturePlanner())->responseSchema();

        $this->assertFalse($schema['additionalProperties']);
        $this->assertArrayHasKey('feature', $schema['properties']);
        $this->assertArrayHasKey('workflow', $schema['properties']);
        $this->assertArrayHasKey('explanation', $schema['properties']);
        $this->assertFalse($schema['properties']['feature']['additionalProperties']);
        $this->assertFalse($schema['properties']['workflow']['additionalProperties']);
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
