<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Compiler\Analysis\ImpactAnalyzer;
use Foundry\Compiler\ApplicationGraph;
use Foundry\Compiler\GraphEdge;
use Foundry\Compiler\IR\ExecutionPlanNode;
use Foundry\Compiler\IR\FeatureNode;
use Foundry\Compiler\IR\GuardNode;
use Foundry\Compiler\IR\RouteNode;
use Foundry\Compiler\Prompt\GraphPromptBuilder;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class GraphPromptBuilderTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function test_builds_feature_scored_prompt_context_bundle(): void
    {
        $graph = $this->graphFixture();
        $builder = new GraphPromptBuilder(new ImpactAnalyzer(Paths::fromCwd($this->project->root)));

        $bundle = $builder->build($graph, 'Add auth checks to publish_post POST /posts endpoint', false);

        $this->assertContains('publish_post', $bundle['selected_features']);
        $this->assertNotEmpty($bundle['context_bundle']['nodes']);
        $this->assertNotEmpty($bundle['context_bundle']['edges']);
        $this->assertArrayHasKey('feature', $bundle['context_bundle']['node_counts']);
        $this->assertNotEmpty($bundle['context_bundle']['execution_plans']);
        $this->assertStringContainsString('Instruction:', (string) $bundle['prompt']['text']);
        $this->assertStringContainsString('Execution plans:', (string) $bundle['prompt']['text']);
        $this->assertStringContainsString('Output requirements:', (string) $bundle['prompt']['text']);
        $this->assertNotEmpty($bundle['recommended_commands']);
        $this->assertNotEmpty($bundle['impact']);
    }

    public function test_build_uses_deterministic_fallback_when_instruction_has_no_match(): void
    {
        $graph = $this->graphFixture();
        $builder = new GraphPromptBuilder(new ImpactAnalyzer(Paths::fromCwd($this->project->root)));

        $fallback = $builder->build($graph, 'do something unrelated', false);
        $this->assertCount(3, $fallback['selected_features']);

        $featureContext = $builder->build($graph, 'do something unrelated', true);
        $this->assertCount(4, $featureContext['selected_features']);
    }

    private function graphFixture(): ApplicationGraph
    {
        $graph = new ApplicationGraph(1, '1.0.0', gmdate(DATE_ATOM), 'hash');

        $graph->addNode(new FeatureNode('feature:publish_post', 'app/features/publish_post/feature.yaml', [
            'feature' => 'publish_post',
            'route' => ['method' => 'POST', 'path' => '/posts'],
            'events' => ['emit' => ['post.created']],
            'cache' => ['invalidate' => ['posts:list']],
            'auth' => ['permissions' => ['posts.create']],
            'tests' => ['required' => ['feature']],
        ]));
        $graph->addNode(new FeatureNode('feature:list_posts', 'app/features/list_posts/feature.yaml', [
            'feature' => 'list_posts',
            'route' => ['method' => 'GET', 'path' => '/posts'],
            'events' => ['emit' => []],
            'cache' => ['invalidate' => ['posts:list']],
            'auth' => ['permissions' => []],
            'tests' => ['required' => ['feature']],
        ]));
        $graph->addNode(new FeatureNode('feature:update_post', 'app/features/update_post/feature.yaml', [
            'feature' => 'update_post',
            'route' => ['method' => 'PATCH', 'path' => '/posts/{id}'],
            'events' => ['emit' => ['post.updated']],
            'cache' => ['invalidate' => ['posts:detail']],
            'auth' => ['permissions' => ['posts.update']],
            'tests' => ['required' => ['feature']],
        ]));
        $graph->addNode(new FeatureNode('feature:delete_post', 'app/features/delete_post/feature.yaml', [
            'feature' => 'delete_post',
            'route' => ['method' => 'DELETE', 'path' => '/posts/{id}'],
            'events' => ['emit' => ['post.deleted']],
            'cache' => ['invalidate' => ['posts:list', 'posts:detail']],
            'auth' => ['permissions' => ['posts.delete']],
            'tests' => ['required' => ['feature']],
        ]));

        $graph->addNode(new RouteNode('route:POST:/posts', 'app/features/publish_post/feature.yaml', ['signature' => 'POST /posts']));
        $graph->addNode(new RouteNode('route:GET:/posts', 'app/features/list_posts/feature.yaml', ['signature' => 'GET /posts']));
        $graph->addNode(new ExecutionPlanNode('execution_plan:feature:publish_post', 'app/features/publish_post/feature.yaml', [
            'feature' => 'publish_post',
            'route_signature' => 'POST /posts',
            'stages' => ['auth', 'validation', 'action'],
            'guards' => ['guard:auth:publish_post'],
        ]));
        $graph->addNode(new GuardNode('guard:auth:publish_post', 'app/features/publish_post/feature.yaml', [
            'feature' => 'publish_post',
            'type' => 'authentication',
        ]));

        $graph->addEdge(GraphEdge::make('feature_to_route', 'feature:publish_post', 'route:POST:/posts'));
        $graph->addEdge(GraphEdge::make('feature_to_route', 'feature:list_posts', 'route:GET:/posts'));
        $graph->addEdge(GraphEdge::make('feature_to_execution_plan', 'feature:publish_post', 'execution_plan:feature:publish_post'));
        $graph->addEdge(GraphEdge::make('execution_plan_to_guard', 'execution_plan:feature:publish_post', 'guard:auth:publish_post'));
        $graph->addEdge(GraphEdge::make('event_publisher_to_subscriber', 'feature:publish_post', 'feature:list_posts', ['event' => 'post.created']));
        $graph->addEdge(GraphEdge::make('event_publisher_to_subscriber', 'feature:update_post', 'feature:list_posts', ['event' => 'post.updated']));

        return $graph;
    }
}
