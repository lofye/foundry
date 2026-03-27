<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Compiler\ApplicationGraph;
use Foundry\Compiler\GraphEdge;
use Foundry\Compiler\IR\CacheNode;
use Foundry\Compiler\IR\ExecutionPlanNode;
use Foundry\Compiler\IR\EventNode;
use Foundry\Compiler\IR\FeatureNode;
use Foundry\Compiler\IR\GuardNode;
use Foundry\Compiler\IR\InterceptorNode;
use Foundry\Compiler\IR\PermissionNode;
use Foundry\Compiler\IR\PipelineStageNode;
use Foundry\Compiler\IR\RouteNode;
use Foundry\Compiler\IR\WorkflowNode;
use Foundry\Compiler\Visualization\GraphVisualizer;
use PHPUnit\Framework\TestCase;

final class GraphVisualizerTest extends TestCase
{
    public function test_builds_views_and_renders_all_formats(): void
    {
        $graph = new ApplicationGraph(1, '1.0.0', gmdate(DATE_ATOM), 'hash');

        $graph->addNode(new FeatureNode('feature:publish_post', 'app/features/publish_post/feature.yaml', ['feature' => 'publish_post']));
        $graph->addNode(new FeatureNode('feature:update_feed', 'app/features/update_feed/feature.yaml', ['feature' => 'update_feed']));
        $graph->addNode(new EventNode('event:post.created', 'app/features/publish_post/events.yaml', ['name' => 'post.created']));
        $graph->addNode(new RouteNode('route:POST:/posts', 'app/features/publish_post/feature.yaml', ['signature' => 'POST /posts']));
        $graph->addNode(new CacheNode('cache:posts:list', 'app/features/publish_post/cache.yaml', ['key' => 'posts:list']));
        $graph->addNode(new PipelineStageNode('pipeline_stage:auth', 'app/.foundry/pipeline', ['name' => 'auth', 'order' => 0]));
        $graph->addNode(new PipelineStageNode('pipeline_stage:validation', 'app/.foundry/pipeline', ['name' => 'validation', 'order' => 1]));
        $graph->addNode(new ExecutionPlanNode('execution_plan:feature:publish_post', 'app/features/publish_post/feature.yaml', ['feature' => 'publish_post']));
        $graph->addNode(new GuardNode('guard:auth:publish_post', 'app/features/publish_post/feature.yaml', ['type' => 'authentication']));
        $graph->addNode(new InterceptorNode('interceptor:trace.auth', 'app/.foundry/extensions', ['id' => 'trace.auth', 'stage' => 'auth']));
        $graph->addNode(new PermissionNode('permission:posts.create', 'app/features/publish_post/permissions.yaml', ['name' => 'posts.create']));
        $graph->addNode(new WorkflowNode('workflow:posts', 'app/definitions/workflows/posts.workflow.yaml', ['resource' => 'posts']));

        $graph->addEdge(GraphEdge::make('event_publisher_to_subscriber', 'feature:publish_post', 'feature:update_feed', ['event' => 'post.created']));
        $graph->addEdge(GraphEdge::make('feature_to_event_emit', 'feature:publish_post', 'event:post.created'));
        $graph->addEdge(GraphEdge::make('feature_to_event_subscribe', 'feature:update_feed', 'event:post.created'));
        $graph->addEdge(GraphEdge::make('feature_to_route', 'feature:publish_post', 'route:POST:/posts'));
        $graph->addEdge(GraphEdge::make('feature_to_cache_invalidation', 'feature:publish_post', 'cache:posts:list'));
        $graph->addEdge(GraphEdge::make('pipeline_stage_next', 'pipeline_stage:auth', 'pipeline_stage:validation'));
        $graph->addEdge(GraphEdge::make('feature_to_execution_plan', 'feature:publish_post', 'execution_plan:feature:publish_post'));
        $graph->addEdge(GraphEdge::make('execution_plan_to_stage', 'execution_plan:feature:publish_post', 'pipeline_stage:auth'));
        $graph->addEdge(GraphEdge::make('execution_plan_to_guard', 'execution_plan:feature:publish_post', 'guard:auth:publish_post'));
        $graph->addEdge(GraphEdge::make('execution_plan_to_interceptor', 'execution_plan:feature:publish_post', 'interceptor:trace.auth'));
        $graph->addEdge(GraphEdge::make('guard_to_pipeline_stage', 'guard:auth:publish_post', 'pipeline_stage:auth'));
        $graph->addEdge(GraphEdge::make('interceptor_to_pipeline_stage', 'interceptor:trace.auth', 'pipeline_stage:auth'));
        $graph->addEdge(GraphEdge::make('workflow_to_permission', 'workflow:posts', 'permission:posts.create'));
        $graph->addEdge(GraphEdge::make('workflow_to_event_emit', 'workflow:posts', 'event:post.created'));

        $visualizer = new GraphVisualizer();

        $dependencies = $visualizer->build($graph, 'dependencies');
        $this->assertSame('dependencies', $dependencies['view']);
        $this->assertCount(2, $dependencies['nodes']);
        $this->assertCount(1, $dependencies['edges']);

        $events = $visualizer->build($graph, 'events');
        $this->assertCount(3, $events['nodes']);
        $this->assertCount(2, $events['edges']);

        $routes = $visualizer->build($graph, 'routes');
        $this->assertNotEmpty($routes['nodes']);
        $this->assertNotEmpty($routes['edges']);

        $caches = $visualizer->build($graph, 'caches', 'publish_post');
        $this->assertSame('publish_post', $caches['feature_filter']);
        $this->assertNotEmpty($caches['nodes']);
        $this->assertNotEmpty($caches['edges']);

        $pipeline = $visualizer->build($graph, 'pipeline');
        $this->assertSame('pipeline', $pipeline['view']);
        $this->assertNotEmpty($pipeline['nodes']);
        $this->assertNotEmpty($pipeline['edges']);

        $workflowInspection = $visualizer->inspect($graph, ['workflow' => 'posts']);
        $this->assertSame('workflows', $workflowInspection['view']);
        $this->assertSame('posts', $workflowInspection['filters']['workflow']);
        $this->assertContains('posts', $workflowInspection['summary']['workflows']);

        $commandInspection = $visualizer->inspect($graph, ['command' => 'POST /posts']);
        $this->assertSame('command', $commandInspection['view']);
        $this->assertSame('POST /posts', $commandInspection['filters']['command']);
        $this->assertContains('POST /posts', $commandInspection['summary']['routes']);

        $extensionInspection = $visualizer->inspect($graph, ['extension' => 'core'], [[
            'name' => 'core',
            'source_path' => 'src/Compiler/Extensions/CoreCompilerExtension.php',
            'packs' => ['core.foundation'],
            'pipeline_stages' => ['auth'],
            'pipeline_interceptors' => ['trace.auth'],
        ]]);
        $this->assertSame('extensions', $extensionInspection['view']);
        $this->assertSame('core', $extensionInspection['filters']['extension']);
        $this->assertContains('core', $extensionInspection['summary']['extensions']);

        $mermaid = $visualizer->render($dependencies, 'mermaid');
        $this->assertStringContainsString('graph TD', $mermaid);
        $this->assertStringContainsString('post.created', $mermaid);

        $dot = $visualizer->render($events, 'dot');
        $this->assertStringContainsString('digraph foundry', $dot);
        $this->assertStringContainsString('feature:publish_post', $dot);

        $svg = $visualizer->render($caches, 'svg');
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('posts:list', $svg);

        $json = $visualizer->render($routes, 'json');
        $this->assertStringContainsString('"view"', $json);
        $this->assertStringContainsString('"routes"', $json);

        $pipelineMermaid = $visualizer->render($pipeline, 'mermaid');
        $this->assertStringContainsString('trace.auth', $pipelineMermaid);

        $summary = $visualizer->renderSummary($commandInspection);
        $this->assertStringContainsString('Command graph for POST /posts', $summary);
        $this->assertStringContainsString('routes: POST /posts', $summary);
    }

    public function test_unknown_view_and_format_fall_back_deterministically(): void
    {
        $graph = new ApplicationGraph(1, '1.0.0', gmdate(DATE_ATOM), 'hash');
        $graph->addNode(new FeatureNode('feature:alpha', 'app/features/alpha/feature.yaml', ['feature' => 'alpha']));

        $visualizer = new GraphVisualizer();
        $data = $visualizer->build($graph, 'unknown-view');
        $this->assertSame('dependencies', $data['view']);

        $rendered = $visualizer->render($data, 'unknown-format');
        $this->assertStringContainsString('graph TD', $rendered);
    }
}
