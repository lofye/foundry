<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Compiler\ApplicationGraph;
use Foundry\Compiler\Analysis\ImpactAnalyzer;
use Foundry\Compiler\GraphEdge;
use Foundry\Compiler\IR\EventNode;
use Foundry\Compiler\IR\FeatureNode;
use Foundry\Compiler\IR\RouteNode;
use Foundry\Pro\ArchitectureExplainer;
use Foundry\Pro\DeepDiagnosticsBuilder;
use Foundry\Pro\GraphDiffAnalyzer;
use Foundry\Pro\TraceAnalyzer;
use Foundry\Support\Paths;
use PHPUnit\Framework\TestCase;

final class ProAnalysisToolsTest extends TestCase
{
    public function test_deep_diagnostics_builder_returns_hotspots_and_focus_feature(): void
    {
        $graph = $this->graphFixture();

        $payload = (new DeepDiagnosticsBuilder())->build($graph, 'publish_post');

        $this->assertSame(3, $payload['graph']['node_count']);
        $this->assertSame(2, $payload['graph']['edge_count']);
        $this->assertSame('publish_post', $payload['focus_feature']['feature']);
        $this->assertNotEmpty($payload['hotspots']);
    }

    public function test_architecture_explainer_resolves_feature_and_renders_explanation(): void
    {
        $graph = $this->graphFixture();
        $explainer = new ArchitectureExplainer(new ImpactAnalyzer(new Paths('/tmp/foundry-pro-tests', '/tmp/foundry-pro-tests')));

        $payload = $explainer->explain($graph, 'publish_post');

        $this->assertSame('feature:publish_post', $payload['resolved_node_id']);
        $this->assertSame('feature', $payload['node']['type']);
        $this->assertNotEmpty($payload['dependencies']);
        $this->assertStringContainsString('publish_post resolves to feature publish_post.', $payload['explanation']);
    }

    public function test_graph_diff_analyzer_detects_changed_nodes_and_added_edges(): void
    {
        $baseline = $this->graphFixture();
        $current = new ApplicationGraph(1, '1.0.0', '2026-03-20T00:00:00+00:00', 'hash-current');

        $featureNode = new FeatureNode('feature:publish_post', 'app/features/publish_post/feature.yaml', [
            'feature' => 'publish_post',
            'description' => 'updated publish post',
        ]);
        $routeNode = new RouteNode('route:POST /posts', 'app/features/publish_post/feature.yaml', [
            'feature' => 'publish_post',
            'signature' => 'POST /posts',
        ]);
        $eventNode = new EventNode('event:post.created', 'app/features/publish_post/events.yaml', [
            'feature' => 'publish_post',
            'name' => 'post.created',
        ]);

        $current->addNode($featureNode);
        $current->addNode($routeNode);
        $current->addNode($eventNode);
        $current->addEdge(GraphEdge::make('serves', 'feature:publish_post', 'route:POST /posts'));
        $current->addEdge(GraphEdge::make('emits', 'feature:publish_post', 'event:post.created'));
        $current->addEdge(GraphEdge::make('subscribes', 'route:POST /posts', 'event:post.created'));

        $payload = (new GraphDiffAnalyzer())->diff($baseline, $current);

        $this->assertSame(1, $payload['summary']['changed_nodes']);
        $this->assertSame(1, $payload['summary']['added_edges']);
        $this->assertContains('publish_post', $payload['affected_features']);
    }

    public function test_trace_analyzer_filters_and_categorizes_events(): void
    {
        $dir = sys_get_temp_dir() . '/foundry-pro-trace-' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        $path = $dir . '/trace.log';
        file_put_contents($path, "publish:started\npublish:finished\ncache:flush\n");

        $payload = (new TraceAnalyzer())->analyze($path, 'publish');

        $this->assertTrue($payload['found']);
        $this->assertSame(3, $payload['total_events']);
        $this->assertSame(2, $payload['matched_events']);
        $this->assertSame(2, $payload['categories']['publish']);

        @unlink($path);
        @rmdir($dir);
    }

    private function graphFixture(): ApplicationGraph
    {
        $graph = new ApplicationGraph(1, '1.0.0', '2026-03-20T00:00:00+00:00', 'hash-baseline');

        $featureNode = new FeatureNode('feature:publish_post', 'app/features/publish_post/feature.yaml', [
            'feature' => 'publish_post',
            'description' => 'publish post',
        ]);
        $routeNode = new RouteNode('route:POST /posts', 'app/features/publish_post/feature.yaml', [
            'feature' => 'publish_post',
            'signature' => 'POST /posts',
        ]);
        $eventNode = new EventNode('event:post.created', 'app/features/publish_post/events.yaml', [
            'feature' => 'publish_post',
            'name' => 'post.created',
        ]);

        $graph->addNode($featureNode);
        $graph->addNode($routeNode);
        $graph->addNode($eventNode);
        $graph->addEdge(GraphEdge::make('serves', 'feature:publish_post', 'route:POST /posts'));
        $graph->addEdge(GraphEdge::make('emits', 'feature:publish_post', 'event:post.created'));

        return $graph;
    }
}
