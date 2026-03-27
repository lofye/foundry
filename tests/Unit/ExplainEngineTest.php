<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Compiler\ApplicationGraph;
use Foundry\Compiler\Analysis\ImpactAnalyzer;
use Foundry\Compiler\BuildLayout;
use Foundry\Compiler\GraphEdge;
use Foundry\Compiler\IR\EventNode;
use Foundry\Compiler\IR\ExecutionPlanNode;
use Foundry\Compiler\IR\FeatureNode;
use Foundry\Compiler\IR\GuardNode;
use Foundry\Compiler\IR\RouteNode;
use Foundry\Explain\ExplainArtifactCatalog;
use Foundry\Explain\ExplainEngineFactory;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainTarget;
use Foundry\Explain\ExplainTargetResolver;
use Foundry\Support\ApiSurfaceRegistry;
use Foundry\Support\FoundryError;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class ExplainEngineTest extends TestCase
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

    public function test_resolver_supports_command_targets_and_reports_ambiguity(): void
    {
        $graph = new ApplicationGraph(1, '1.0.0', '2026-03-20T00:00:00+00:00', 'hash');
        $graph->addNode(new FeatureNode('feature:publish_post', 'app/features/publish_post/feature.yaml', ['feature' => 'publish_post']));
        $graph->addNode(new FeatureNode('feature:publish_profile', 'app/features/publish_profile/feature.yaml', ['feature' => 'publish_profile']));

        $paths = Paths::fromCwd($this->project->root);
        $resolver = new ExplainTargetResolver(
            $graph,
            new ExplainArtifactCatalog(new BuildLayout($paths), $paths, new ApiSurfaceRegistry()),
        );

        $command = $resolver->resolve(ExplainTarget::parse('command:doctor'));
        $this->assertSame('command', $command->kind);
        $this->assertSame('doctor', $command->label);

        try {
            $resolver->resolve(ExplainTarget::parse('publish'));
            self::fail('Expected ambiguous explain target.');
        } catch (FoundryError $error) {
            $this->assertSame('EXPLAIN_TARGET_AMBIGUOUS', $error->errorCode);
            $this->assertCount(2, (array) ($error->details['candidates'] ?? []));
            $this->assertStringContainsString('Ambiguous target: "publish"', $error->getMessage());
            $this->assertStringContainsString('foundry explain feature:publish_post', $error->getMessage());
        }
    }

    public function test_engine_builds_feature_plan_from_graph_and_compiled_artifacts(): void
    {
        $this->writeExplainArtifacts($this->project->root);
        $graph = $this->graphFixture();
        $paths = Paths::fromCwd($this->project->root);

        $engine = ExplainEngineFactory::create(
            graph: $graph,
            paths: $paths,
            apiSurfaceRegistry: new ApiSurfaceRegistry(),
            impactAnalyzer: new ImpactAnalyzer($paths),
        );

        $plan = $engine->explain(ExplainTarget::parse('publish_post'), new ExplainOptions());
        $payload = $plan->toArray();

        $this->assertSame(
            ['subject', 'summary', 'responsibilities', 'executionFlow', 'relationships', 'emits', 'triggers', 'permissions', 'schemaInteraction', 'relatedCommands', 'relatedDocs', 'diagnostics', 'suggestedFixes', 'sections', 'sectionOrder', 'metadata'],
            array_keys($payload),
        );
        $this->assertSame('feature', $payload['subject']['kind']);
        $this->assertSame('feature:publish_post', $payload['subject']['id']);
        $this->assertTrue($payload['summary']['deterministic']);
        $this->assertArrayHasKey('graph_node_ids', $payload['subject']);
        $this->assertArrayHasKey('schema_version', $payload['metadata']);
        $this->assertArrayHasKey('target', $payload['metadata']);
        $this->assertArrayHasKey('options', $payload['metadata']);
        $this->assertArrayHasKey('graph', $payload['metadata']);
        $this->assertArrayNotHasKey('compiled_at', $payload['metadata']['graph']);
        $this->assertNotEmpty($payload['responsibilities']['items']);
        $this->assertNotEmpty($payload['relationships']['dependsOn']['items']);
        $this->assertSame('publish_post', $payload['executionFlow']['action']['feature']);
        $this->assertNotEmpty($payload['executionFlow']['guards']);
        $this->assertNotEmpty($payload['emits']['items']);
        $this->assertNotEmpty($payload['triggers']['items']);
        $this->assertContains('posts.create', $payload['permissions']['required']);
        $this->assertNotEmpty($payload['schemaInteraction']['items']);
        $this->assertNotEmpty($payload['relationships']['graph']['outbound']);
        $this->assertSame(1, $payload['diagnostics']['summary']['total']);
        $this->assertContains('foundry inspect feature publish_post --json', $payload['relatedCommands']);
        $this->assertSame('publish_post', $payload['metadata']['impact']['affected_features'][0]);
    }

    public function test_engine_output_is_stable_when_graph_compile_timestamp_changes(): void
    {
        $this->writeExplainArtifacts($this->project->root);
        $paths = Paths::fromCwd($this->project->root);

        $first = ExplainEngineFactory::create(
            graph: $this->graphFixture('2026-03-20T00:00:00+00:00'),
            paths: $paths,
            apiSurfaceRegistry: new ApiSurfaceRegistry(),
            impactAnalyzer: new ImpactAnalyzer($paths),
        )->explain(ExplainTarget::parse('publish_post'), new ExplainOptions())->toArray();

        $second = ExplainEngineFactory::create(
            graph: $this->graphFixture('2026-03-21T12:34:56+00:00'),
            paths: $paths,
            apiSurfaceRegistry: new ApiSurfaceRegistry(),
            impactAnalyzer: new ImpactAnalyzer($paths),
        )->explain(ExplainTarget::parse('publish_post'), new ExplainOptions())->toArray();

        $this->assertSame($first, $second);
    }

    private function graphFixture(string $compiledAt = '2026-03-20T00:00:00+00:00'): ApplicationGraph
    {
        $graph = new ApplicationGraph(1, '1.0.0', $compiledAt, 'hash-baseline');

        $featureNode = new FeatureNode('feature:publish_post', 'app/features/publish_post/feature.yaml', [
            'feature' => 'publish_post',
            'description' => 'publish post',
            'route' => ['method' => 'POST', 'path' => '/posts'],
            'jobs' => ['dispatch' => ['notify_followers']],
            'auth' => ['permissions' => ['posts.create']],
        ]);
        $routeNode = new RouteNode('route:POST:/posts', 'app/features/publish_post/feature.yaml', [
            'feature' => 'publish_post',
            'signature' => 'POST /posts',
            'method' => 'POST',
            'path' => '/posts',
        ]);
        $eventNode = new EventNode('event:post.created', 'app/features/publish_post/events.yaml', [
            'feature' => 'publish_post',
            'name' => 'post.created',
        ]);
        $executionPlanNode = new ExecutionPlanNode('execution_plan:feature:publish_post', 'app/features/publish_post/feature.yaml', [
            'feature' => 'publish_post',
            'route_signature' => 'POST /posts',
            'stages' => ['auth', 'validation', 'action'],
        ]);
        $guardNode = new GuardNode('guard:auth:publish_post', 'app/features/publish_post/feature.yaml', [
            'feature' => 'publish_post',
            'type' => 'authentication',
            'stage' => 'auth',
        ]);

        $graph->addNode($featureNode);
        $graph->addNode($routeNode);
        $graph->addNode($eventNode);
        $graph->addNode($executionPlanNode);
        $graph->addNode($guardNode);
        $graph->addEdge(GraphEdge::make('feature_to_route', 'feature:publish_post', 'route:POST:/posts'));
        $graph->addEdge(GraphEdge::make('feature_to_event_emit', 'feature:publish_post', 'event:post.created'));
        $graph->addEdge(GraphEdge::make('feature_to_execution_plan', 'feature:publish_post', 'execution_plan:feature:publish_post'));
        $graph->addEdge(GraphEdge::make('execution_plan_to_guard', 'execution_plan:feature:publish_post', 'guard:auth:publish_post'));

        return $graph;
    }

    private function writeExplainArtifacts(string $root): void
    {
        @mkdir($root . '/app/.foundry/build/projections', 0777, true);
        @mkdir($root . '/app/.foundry/build/diagnostics', 0777, true);

        $executionPlan = [
            'id' => 'execution_plan:feature:publish_post',
            'feature' => 'publish_post',
            'route_signature' => 'POST /posts',
            'route_node' => 'route:POST:/posts',
            'stages' => ['auth', 'validation', 'action'],
            'guards' => ['guard:auth:publish_post'],
            'interceptors' => [],
            'action_node' => 'feature:publish_post',
            'plan_version' => 1,
        ];

        file_put_contents(
            $root . '/app/.foundry/build/projections/feature_index.php',
            '<?php return ' . var_export([
                'publish_post' => [
                    'description' => 'publish post',
                    'route' => ['method' => 'POST', 'path' => '/posts'],
                    'auth' => ['permissions' => ['posts.create']],
                    'jobs' => ['dispatch' => ['notify_followers']],
                ],
            ], true) . ';',
        );
        file_put_contents(
            $root . '/app/.foundry/build/projections/execution_plan_index.php',
            '<?php return ' . var_export([
                'by_feature' => ['publish_post' => $executionPlan],
                'by_route' => ['POST /posts' => $executionPlan],
            ], true) . ';',
        );
        file_put_contents(
            $root . '/app/.foundry/build/projections/guard_index.php',
            '<?php return ' . var_export([
                'guard:auth:publish_post' => [
                    'id' => 'guard:auth:publish_post',
                    'feature' => 'publish_post',
                    'type' => 'authentication',
                    'stage' => 'auth',
                    'config' => ['required' => true],
                ],
            ], true) . ';',
        );
        file_put_contents(
            $root . '/app/.foundry/build/projections/event_index.php',
            '<?php return ' . var_export([
                'emit' => [
                    'post.created' => [
                        'feature' => 'publish_post',
                        'schema' => ['type' => 'object'],
                    ],
                ],
                'subscribe' => [],
            ], true) . ';',
        );
        file_put_contents(
            $root . '/app/.foundry/build/projections/pipeline_index.php',
            '<?php return ' . var_export([
                'order' => ['auth', 'validation', 'action'],
                'stages' => [],
                'links' => [],
            ], true) . ';',
        );
        file_put_contents($root . '/app/.foundry/build/projections/interceptor_index.php', '<?php return [];');
        file_put_contents($root . '/app/.foundry/build/projections/workflow_index.php', '<?php return [];');
        file_put_contents(
            $root . '/app/.foundry/build/projections/schema_index.php',
            '<?php return ' . var_export([
                'publish_post' => [
                    'input' => 'app/features/publish_post/input.schema.json',
                ],
            ], true) . ';',
        );
        file_put_contents(
            $root . '/app/.foundry/build/projections/permission_index.php',
            '<?php return ' . var_export([
                'publish_post' => [
                    'permissions' => ['posts.create'],
                ],
            ], true) . ';',
        );

        file_put_contents(
            $root . '/app/.foundry/build/diagnostics/latest.json',
            json_encode([
                'summary' => ['error' => 0, 'warning' => 1, 'info' => 0, 'total' => 1],
                'diagnostics' => [[
                    'id' => 'D0001',
                    'code' => 'FDY9999_TEST_WARNING',
                    'severity' => 'warning',
                    'category' => 'tests',
                    'message' => 'Fixture diagnostic for explain tests.',
                    'node_id' => 'feature:publish_post',
                    'source_path' => 'app/features/publish_post/feature.yaml',
                    'source_line' => null,
                    'related_nodes' => [],
                    'suggested_fix' => null,
                    'pass' => 'tests.fixture',
                    'why_it_matters' => null,
                    'details' => [],
                ]],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }
}
