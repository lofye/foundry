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
use Foundry\Compiler\IR\JobNode;
use Foundry\Compiler\IR\PipelineStageNode;
use Foundry\Compiler\IR\RouteNode;
use Foundry\Compiler\IR\SchemaNode;
use Foundry\Compiler\IR\WorkflowNode;
use Foundry\Explain\ExplainArtifactCatalog;
use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainSection;
use Foundry\Explain\Contributors\ExplainContributorInterface;
use Foundry\Explain\Contributors\ExplainContribution;
use Foundry\Explain\ExplainEngineFactory;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSupport;
use Foundry\Explain\ExplainTarget;
use Foundry\Explain\ExplainTargetResolver;
use Foundry\Explain\Renderers\ExplanationRendererFactory;
use Foundry\Support\ApiSurfaceRegistry;
use Foundry\Support\FoundryError;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class ExplainArchitectureCoverageTest extends TestCase
{
    private TempProject $project;
    private Paths $paths;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->paths = Paths::fromCwd($this->project->root);
        $this->writeArtifacts($this->project->root);
        $this->writeDocs($this->project->root);
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function test_engine_explains_multiple_subject_kinds_deterministically(): void
    {
        $engine = ExplainEngineFactory::create(
            graph: $this->graphFixture(),
            paths: $this->paths,
            apiSurfaceRegistry: new ApiSurfaceRegistry(),
            impactAnalyzer: new ImpactAnalyzer($this->paths),
            extensionRows: $this->extensionRows(),
        );

        $feature = $engine->explain(ExplainTarget::parse('feature:publish_post'), new ExplainOptions());
        $route = $engine->explain(ExplainTarget::parse('route:POST /posts'), new ExplainOptions());
        $event = $engine->explain(ExplainTarget::parse('event:post.created'), new ExplainOptions());
        $workflow = $engine->explain(ExplainTarget::parse('workflow:editorial'), new ExplainOptions());
        $job = $engine->explain(ExplainTarget::parse('job:notify_followers'), new ExplainOptions());
        $schema = $engine->explain(ExplainTarget::parse('schema:app/features/publish_post/input.schema.json'), new ExplainOptions());
        $pipelineStage = $engine->explain(ExplainTarget::parse('pipeline_stage:auth'), new ExplainOptions());
        $command = $engine->explain(ExplainTarget::parse('command:doctor'), new ExplainOptions());
        $extension = $engine->explain(ExplainTarget::parse('extension:test.explain'), new ExplainOptions());

        $this->assertSame('feature', $feature->subject['kind']);
        $this->assertStringContainsString('Publish post.', (string) $feature->summary['text']);
        $this->assertStringContainsString('It serves POST /posts.', (string) $feature->summary['text']);
        $this->assertSame('request', (string) ($route->executionFlow['entries'][0]['label'] ?? ''));
        $this->assertSame('editorial', (string) ($route->executionFlow['workflows'][0]['resource'] ?? ''));
        $this->assertSame('post.created', (string) ($event->subject['label'] ?? ''));
        $this->assertNotEmpty($event->dependents['items']);
        $this->assertSame('post.created', (string) ($workflow->emits['items'][0]['label'] ?? ''));
        $this->assertSame('notify_followers', (string) ($job->subject['label'] ?? ''));
        $this->assertSame('input', (string) ($schema->schemaInteraction['subject']['role'] ?? ''));
        $this->assertSame('auth', (string) ($pipelineStage->subject['label'] ?? ''));
        $this->assertSame('doctor', (string) ($command->subject['label'] ?? ''));
        $this->assertSame('test.explain', (string) ($extension->subject['label'] ?? ''));
        $this->assertContains('Provide capability: explain.fixture', $extension->responsibilities['items']);
        $this->assertNotEmpty($feature->relatedDocs);
        $this->assertNotEmpty($command->relatedDocs);
    }

    public function test_resolver_handles_exact_typed_fuzzy_and_ambiguous_targets(): void
    {
        $graph = $this->graphFixture(includeSecondPublishFeature: true);
        $resolver = new ExplainTargetResolver(
            $graph,
            new ExplainArtifactCatalog(new BuildLayout($this->paths), $this->paths, new ApiSurfaceRegistry(), $this->extensionRows()),
        );

        $this->assertSame('feature:publish_post', $resolver->resolve(ExplainTarget::parse('feature:publish_post'))->id);
        $this->assertSame('route:POST:/posts', $resolver->resolve(ExplainTarget::parse('POST /posts'))->id);
        $this->assertSame('command:doctor', $resolver->resolve(ExplainTarget::parse('command:doctor'))->id);
        $this->assertSame('extension:test.explain', $resolver->resolve(ExplainTarget::parse('extension:test.explain'))->id);
        $this->assertSame('workflow:editorial', $resolver->resolve(ExplainTarget::parse('editorial'))->id);

        try {
            $resolver->resolve(ExplainTarget::parse('publish'));
            self::fail('Expected ambiguous target.');
        } catch (FoundryError $error) {
            $this->assertSame('EXPLAIN_TARGET_AMBIGUOUS', $error->errorCode);
            $this->assertStringContainsString('Ambiguous target: "publish"', $error->getMessage());
            $this->assertStringContainsString('foundry explain feature:publish_post', $error->getMessage());
            $featureCandidates = array_values(array_filter(
                (array) ($error->details['candidates'] ?? []),
                static fn (mixed $row): bool => is_array($row) && (string) ($row['kind'] ?? '') === 'feature',
            ));
            $this->assertCount(2, $featureCandidates);
        }

        try {
            $resolver->resolve(ExplainTarget::parse('feature:missing'));
            self::fail('Expected missing target.');
        } catch (FoundryError $error) {
            $this->assertSame('EXPLAIN_TARGET_NOT_FOUND', $error->errorCode);
        }
    }

    public function test_artifacts_support_helpers_and_renderers_remain_plan_driven(): void
    {
        $catalog = new ExplainArtifactCatalog(new BuildLayout($this->paths), $this->paths, new ApiSurfaceRegistry(), $this->extensionRows());
        $this->assertArrayHasKey('publish_post', $catalog->featureIndex());
        $this->assertArrayHasKey('POST /posts', $catalog->routeIndex());
        $this->assertArrayHasKey('emit', $catalog->eventIndex());
        $this->assertArrayHasKey('editorial', $catalog->workflowIndex());
        $this->assertArrayHasKey('notify_followers', $catalog->jobIndex());
        $this->assertArrayHasKey('publish_post', $catalog->schemaIndex());
        $this->assertArrayHasKey('publish_post', $catalog->permissionIndex());
        $this->assertArrayHasKey('by_feature', $catalog->executionPlanIndex());
        $this->assertArrayHasKey('guard:auth:publish_post', $catalog->guardIndex());
        $this->assertArrayHasKey('order', $catalog->pipelineIndex());
        $this->assertSame([], $catalog->interceptorIndex());
        $this->assertSame(2, $catalog->diagnosticsReport()['summary']['total']);
        $this->assertNotEmpty($catalog->docsPages());
        $this->assertNotEmpty($catalog->cliCommands());
        $this->assertCount(1, $catalog->extensions());

        $graph = $this->graphFixture();
        $node = $graph->node('route:POST:/posts');
        self::assertNotNull($node);
        $subject = (new ExplainTargetResolver($graph, $catalog))->resolve(ExplainTarget::parse('publish_post'));
        $context = new ExplainContext($subject, ExplainSupport::commandPrefix($this->paths));
        $context->setDocs(['items' => [['title' => 'Fixture Docs']]]);
        $context->setImpact(['affected_features' => ['publish_post']]);
        $this->assertSame([['title' => 'Fixture Docs']], $context->docs()['items']);
        $this->assertSame(['publish_post'], $context->impact()['affected_features']);
        $this->assertArrayHasKey('docs', $context->all());
        $this->assertArrayHasKey('impact', $context->all());

        $this->assertSame('POST /posts', ExplainSupport::normalizeRouteSignature(' post   /posts '));
        $this->assertSame('route:POST:/posts', ExplainSupport::routeNodeId('POST /posts'));
        $this->assertContains('POST /posts', ExplainSupport::nodeAliases($node));
        $this->assertSame('publish_post', ExplainSupport::featureFromNode($graph->node('feature:publish_post')));
        $this->assertCount(1, ExplainSupport::uniqueRows([
            ['id' => 'one', 'label' => 'One'],
            ['id' => 'one', 'label' => 'One'],
        ]));
        $this->assertSame(['a', 'b'], ExplainSupport::uniqueStrings(['b', 'a', 'a']));
        $this->assertSame(['b', 'a'], ExplainSupport::orderedUniqueStrings(['b', 'a', 'a']));
        $this->assertSame('foundry', ExplainSupport::commandPrefix($this->paths));
        $this->assertSame('Test', ExplainSupport::section('id', 'Test', [])['title']);

        $engine = ExplainEngineFactory::create(
            graph: $graph,
            paths: $this->paths,
            apiSurfaceRegistry: new ApiSurfaceRegistry(),
            impactAnalyzer: new ImpactAnalyzer($this->paths),
            extensionRows: $this->extensionRows(),
        );
        $plan = $engine->explain(ExplainTarget::parse('publish_post'), new ExplainOptions());
        $deepPlan = $engine->explain(ExplainTarget::parse('publish_post'), new ExplainOptions(format: 'markdown', deep: true));
        $factory = new ExplanationRendererFactory();

        $text = $factory->forFormat('text')->render($plan);
        $markdown = $factory->forFormat('markdown')->render($plan);
        $deepText = $factory->forFormat('text')->render($deepPlan);
        $deepMarkdown = $factory->forFormat('markdown')->render($deepPlan);
        $json = $factory->forFormat('json')->render($plan);

        $this->assertStringContainsString('Subject', $text);
        $this->assertStringContainsString('Responsibilities', $text);
        $this->assertStringContainsString('Execution Flow', $text);
        $this->assertStringContainsString('Related Docs', $text);
        $this->assertStringContainsString('Execution Flow (Detailed)', $deepText);
        $this->assertStringContainsString('Graph Relationships (Expanded)', $deepText);
        $this->assertStringContainsString('## publish_post', $markdown);
        $this->assertStringContainsString('### Responsibilities', $markdown);
        $this->assertStringContainsString('### Related Docs', $markdown);
        $this->assertStringContainsString('### Execution Flow (Detailed)', $deepMarkdown);
        $this->assertStringContainsString('### Graph Relationships', $deepMarkdown);
        $this->assertStringContainsString('"subject"', $json);
        $this->assertStringContainsString('"relatedDocs"', $json);
    }

    public function test_engine_accepts_contributors_and_merges_their_sections(): void
    {
        $contributor = new class implements ExplainContributorInterface
        {
            public function supports(\Foundry\Explain\ExplainSubject $subject): bool
            {
                return $subject->kind === 'feature';
            }

            public function contribute(\Foundry\Explain\ExplainSubject $subject, ExplainContext $context, \Foundry\Explain\ExplainOptions $options): ExplainContribution
            {
                return ExplainContribution::fromArray([
                    'sections' => [
                        ExplainSupport::section('contributor', 'Contributor', [
                            'source' => 'fixture',
                            'subject' => $subject->id,
                        ]),
                    ],
                    'related_commands' => [
                        $context->commandPrefix . ' inspect feature ' . $subject->label . ' --json',
                    ],
                    'related_docs' => [
                        [
                            'id' => 'fixture-doc',
                            'title' => 'Fixture Explain Notes',
                            'path' => 'docs/fixture-explain.md',
                            'source' => 'fixture',
                        ],
                    ],
                ]);
            }
        };

        file_put_contents($this->project->root . '/docs/fixture-explain.md', "# Fixture Explain Notes\n");

        $engine = ExplainEngineFactory::create(
            graph: $this->graphFixture(),
            paths: $this->paths,
            apiSurfaceRegistry: new ApiSurfaceRegistry(),
            impactAnalyzer: new ImpactAnalyzer($this->paths),
            extensionRows: $this->extensionRows(),
            contributors: [$contributor],
        );

        $plan = $engine->explain(ExplainTarget::parse('feature:publish_post'), new ExplainOptions());

        $this->assertSame('fixture', $this->sectionItems($plan->sections, 'contributor')['source']);
        $this->assertContains('foundry inspect feature publish_post --json', $plan->relatedCommands);
        $fixtureDocs = array_values(array_filter(
            $plan->relatedDocs,
            static fn (array $row): bool => (string) ($row['id'] ?? '') === 'fixture-doc',
        ));
        $this->assertCount(1, $fixtureDocs);
    }

    public function test_explain_output_is_deterministic_across_repeated_runs_and_rendering(): void
    {
        $engine = ExplainEngineFactory::create(
            graph: $this->graphFixture(),
            paths: $this->paths,
            apiSurfaceRegistry: new ApiSurfaceRegistry(),
            impactAnalyzer: new ImpactAnalyzer($this->paths),
            extensionRows: $this->extensionRows(),
        );

        $options = new ExplainOptions(format: 'markdown', deep: true);
        $first = $engine->explain(ExplainTarget::parse('feature:publish_post'), $options);
        $second = $engine->explain(ExplainTarget::parse('feature:publish_post'), $options);

        $this->assertSame($first->toArray(), $second->toArray());

        $renderers = new ExplanationRendererFactory();
        $this->assertSame(
            $renderers->forFormat('text')->render($first),
            $renderers->forFormat('text')->render($second),
        );
        $this->assertSame(
            $renderers->forFormat('markdown')->render($first),
            $renderers->forFormat('markdown')->render($second),
        );
        $this->assertSame(
            $renderers->forFormat('json')->render($first),
            $renderers->forFormat('json')->render($second),
        );
    }

    public function test_deep_mode_enriches_without_changing_plan_shape_or_section_identity(): void
    {
        $engine = ExplainEngineFactory::create(
            graph: $this->graphFixture(),
            paths: $this->paths,
            apiSurfaceRegistry: new ApiSurfaceRegistry(),
            impactAnalyzer: new ImpactAnalyzer($this->paths),
            extensionRows: $this->extensionRows(),
        );

        $shallow = $engine->explain(ExplainTarget::parse('feature:publish_post'), new ExplainOptions());
        $deep = $engine->explain(ExplainTarget::parse('feature:publish_post'), new ExplainOptions(deep: true));

        $this->assertSame(array_keys($shallow->toArray()), array_keys($deep->toArray()));
        $this->assertSame(
            array_values(array_map(static fn (ExplainSection $section): string => $section->id(), $shallow->sections)),
            array_values(array_map(static fn (ExplainSection $section): string => $section->id(), $deep->sections)),
        );

        $renderers = new ExplanationRendererFactory();
        $text = $renderers->forFormat('text')->render($shallow);
        $deepText = $renderers->forFormat('text')->render($deep);
        $json = json_decode($renderers->forFormat('json')->render($shallow), true, 512, JSON_THROW_ON_ERROR);

        $this->assertGreaterThan(-1, strpos($text, 'Subject'));
        $this->assertGreaterThan(strpos($text, 'Subject'), strpos($text, 'Summary'));
        $this->assertGreaterThan(strpos($text, 'Summary'), strpos($text, 'Responsibilities'));
        $this->assertGreaterThan(strpos($text, 'Responsibilities'), strpos($text, 'Execution Flow'));
        $this->assertGreaterThan(strpos($text, 'Execution Flow'), strpos($text, 'Related Docs'));
        $this->assertGreaterThan(strpos($text, 'Related Docs'), strpos($text, 'Diagnostics'));

        $this->assertStringContainsString('Execution Flow (Detailed)', $deepText);
        $this->assertStringContainsString('Responsibilities', $deepText);
        $this->assertStringContainsString('Diagnostics', $deepText);
        $this->assertSame(array_keys($shallow->toArray()), array_keys((array) $json));
    }

    private function graphFixture(bool $includeSecondPublishFeature = false): ApplicationGraph
    {
        $graph = new ApplicationGraph(1, '1.0.0', '2026-03-20T00:00:00+00:00', 'hash-baseline');

        $graph->addNode(new FeatureNode('feature:publish_post', 'app/features/publish_post/feature.yaml', [
            'feature' => 'publish_post',
            'description' => 'publish post',
            'route' => ['method' => 'POST', 'path' => '/posts'],
            'events' => ['emit' => ['post.created']],
            'jobs' => ['dispatch' => ['notify_followers']],
            'auth' => ['permissions' => ['posts.create']],
        ]));
        $graph->addNode(new RouteNode('route:POST:/posts', 'app/features/publish_post/feature.yaml', [
            'feature' => 'publish_post',
            'signature' => 'POST /posts',
            'method' => 'POST',
            'path' => '/posts',
        ]));
        $graph->addNode(new EventNode('event:post.created', 'app/features/publish_post/events.yaml', [
            'feature' => 'publish_post',
            'name' => 'post.created',
        ]));
        $graph->addNode(new ExecutionPlanNode('execution_plan:feature:publish_post', 'app/features/publish_post/feature.yaml', [
            'feature' => 'publish_post',
            'route_signature' => 'POST /posts',
            'stages' => ['request_received', 'auth', 'validation', 'action'],
            'action_node' => 'feature:publish_post',
        ]));
        $graph->addNode(new GuardNode('guard:auth:publish_post', 'app/features/publish_post/feature.yaml', [
            'feature' => 'publish_post',
            'type' => 'authentication',
            'stage' => 'auth',
        ]));
        $graph->addNode(new WorkflowNode('workflow:editorial', 'app/definitions/workflows/editorial.workflow.yaml', [
            'resource' => 'editorial',
            'states' => ['draft', 'review', 'published'],
            'transitions' => [
                'request_review' => [
                    'from' => ['draft'],
                    'to' => 'review',
                    'permission' => 'posts.review',
                    'emit' => ['post.created'],
                ],
            ],
        ]));
        $graph->addNode(new JobNode('job:notify_followers', 'app/features/publish_post/jobs.yaml', [
            'name' => 'notify_followers',
            'features' => ['publish_post'],
            'definitions' => ['publish_post' => ['queue' => 'default']],
        ]));
        $graph->addNode(new SchemaNode('schema:app/features/publish_post/input.schema.json', 'app/features/publish_post/input.schema.json', [
            'path' => 'app/features/publish_post/input.schema.json',
            'role' => 'input',
            'feature' => 'publish_post',
            'document' => ['type' => 'object'],
        ]));
        $graph->addNode(new PipelineStageNode('pipeline_stage:auth', 'config/auth.php', [
            'name' => 'auth',
        ]));

        $graph->addEdge(GraphEdge::make('feature_to_route', 'feature:publish_post', 'route:POST:/posts'));
        $graph->addEdge(GraphEdge::make('feature_to_event_emit', 'feature:publish_post', 'event:post.created'));
        $graph->addEdge(GraphEdge::make('feature_to_execution_plan', 'feature:publish_post', 'execution_plan:feature:publish_post'));
        $graph->addEdge(GraphEdge::make('route_to_execution_plan', 'route:POST:/posts', 'execution_plan:feature:publish_post'));
        $graph->addEdge(GraphEdge::make('execution_plan_to_guard', 'execution_plan:feature:publish_post', 'guard:auth:publish_post'));
        $graph->addEdge(GraphEdge::make('guard_to_pipeline_stage', 'guard:auth:publish_post', 'pipeline_stage:auth'));
        $graph->addEdge(GraphEdge::make('workflow_to_event_emit', 'workflow:editorial', 'event:post.created'));
        $graph->addEdge(GraphEdge::make('feature_to_job_dispatch', 'feature:publish_post', 'job:notify_followers'));
        $graph->addEdge(GraphEdge::make('feature_to_input_schema', 'feature:publish_post', 'schema:app/features/publish_post/input.schema.json'));

        if ($includeSecondPublishFeature) {
            $graph->addNode(new FeatureNode('feature:publish_profile', 'app/features/publish_profile/feature.yaml', [
                'feature' => 'publish_profile',
                'description' => 'publish profile',
            ]));
        }

        return $graph;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function extensionRows(): array
    {
        return [[
            'name' => 'test.explain',
            'version' => '1.0.0',
            'description' => 'Fixture explain extension.',
            'provides' => ['capabilities' => ['explain.fixture']],
            'packs' => ['test.explain.pack'],
        ]];
    }

    private function writeArtifacts(string $root): void
    {
        @mkdir($root . '/app/.foundry/build/projections', 0777, true);
        @mkdir($root . '/app/.foundry/build/diagnostics', 0777, true);

        $executionPlan = [
            'id' => 'execution_plan:feature:publish_post',
            'feature' => 'publish_post',
            'route_signature' => 'POST /posts',
            'route_node' => 'route:POST:/posts',
            'stages' => ['request_received', 'auth', 'validation', 'action'],
            'guards' => ['guard:auth:publish_post'],
            'interceptors' => [],
            'action_node' => 'feature:publish_post',
            'plan_version' => 1,
        ];

        $this->writeProjection($root, 'feature_index.php', [
            'publish_post' => [
                'description' => 'publish post',
                'route' => ['method' => 'POST', 'path' => '/posts'],
            ],
        ]);
        $this->writeProjection($root, 'routes_index.php', [
            'POST /posts' => [
                'feature' => 'publish_post',
                'kind' => 'http',
            ],
        ]);
        $this->writeProjection($root, 'event_index.php', [
            'emit' => [
                'post.created' => [
                    'feature' => 'publish_post',
                    'schema' => ['type' => 'object'],
                ],
            ],
            'subscribe' => [
                'post.created' => ['publish_post', 'editorial'],
            ],
        ]);
        $this->writeProjection($root, 'workflow_index.php', [
            'editorial' => [
                'resource' => 'editorial',
                'states' => ['draft', 'review', 'published'],
                'transitions' => [
                    'request_review' => [
                        'from' => ['draft'],
                        'to' => 'review',
                        'permission' => 'posts.review',
                        'emit' => ['post.created'],
                    ],
                ],
            ],
        ]);
        $this->writeProjection($root, 'job_index.php', [
            'notify_followers' => [
                'feature' => 'publish_post',
                'queue' => 'default',
            ],
        ]);
        $this->writeProjection($root, 'schema_index.php', [
            'publish_post' => [
                'input' => 'app/features/publish_post/input.schema.json',
                'output' => 'app/features/publish_post/output.schema.json',
            ],
        ]);
        $this->writeProjection($root, 'permission_index.php', [
            'publish_post' => [
                'permissions' => ['posts.create'],
            ],
        ]);
        $this->writeProjection($root, 'execution_plan_index.php', [
            'by_feature' => ['publish_post' => $executionPlan],
            'by_route' => ['POST /posts' => $executionPlan],
        ]);
        $this->writeProjection($root, 'guard_index.php', [
            'guard:auth:publish_post' => [
                'id' => 'guard:auth:publish_post',
                'feature' => 'publish_post',
                'type' => 'authentication',
                'stage' => 'auth',
                'config' => ['required' => true],
            ],
        ]);
        $this->writeProjection($root, 'pipeline_index.php', [
            'order' => ['request_received', 'auth', 'validation', 'action'],
            'stages' => [],
            'links' => [],
        ]);
        $this->writeProjection($root, 'interceptor_index.php', []);

        file_put_contents(
            $root . '/app/.foundry/build/diagnostics/latest.json',
            json_encode([
                'summary' => ['error' => 0, 'warning' => 1, 'info' => 1, 'total' => 2],
                'diagnostics' => [
                    [
                        'id' => 'D1',
                        'code' => 'FDY_EVENT',
                        'severity' => 'info',
                        'category' => 'events',
                        'message' => 'Event diagnostic.',
                        'node_id' => 'event:post.created',
                        'source_path' => 'app/features/publish_post/events.yaml',
                        'related_nodes' => [],
                    ],
                    [
                        'id' => 'D2',
                        'code' => 'FDY_FEATURE',
                        'severity' => 'warning',
                        'category' => 'graph',
                        'message' => 'Feature diagnostic.',
                        'node_id' => 'feature:publish_post',
                        'source_path' => 'app/features/publish_post/feature.yaml',
                        'related_nodes' => [],
                    ],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }

    private function writeDocs(string $root): void
    {
        @mkdir($root . '/docs/generated', 0777, true);

        file_put_contents($root . '/docs/architecture-tools.md', "# Architecture Tools\n");
        file_put_contents($root . '/docs/how-it-works.md', "# How It Works\n");
        file_put_contents($root . '/docs/execution-pipeline.md', "# Execution Pipeline\n");
        file_put_contents($root . '/docs/reference.md', "# Reference\n");
        file_put_contents($root . '/docs/extension-author-guide.md', "# Extension Author Guide\n");
        file_put_contents($root . '/docs/extensions-and-migrations.md', "# Extensions And Migrations\n");
        file_put_contents($root . '/docs/public-api-policy.md', "# Public API Policy\n");
        file_put_contents($root . '/docs/generated/graph-overview.md', "# Graph Overview\n");
        file_put_contents($root . '/docs/generated/features.md', "# Feature Catalog\n");
        file_put_contents($root . '/docs/generated/routes.md', "# Route Catalog\n");
        file_put_contents($root . '/docs/generated/events.md', "# Event Registry\n");
        file_put_contents($root . '/docs/generated/jobs.md', "# Job Registry\n");
        file_put_contents($root . '/docs/generated/schemas.md', "# Schema Catalog\n");
        file_put_contents($root . '/docs/generated/cli-reference.md', "# CLI Reference\n");
        file_put_contents($root . '/docs/generated/api-surface.md', "# API Surface Policy\n");
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function writeProjection(string $root, string $file, array $payload): void
    {
        file_put_contents(
            $root . '/app/.foundry/build/projections/' . $file,
            '<?php return ' . var_export($payload, true) . ';',
        );
    }

    /**
     * @param array<int,ExplainSection> $sections
     * @return array<string,mixed>
     */
    private function sectionItems(array $sections, string $id): array
    {
        foreach ($sections as $section) {
            if (!$section instanceof ExplainSection || $section->id() !== $id) {
                continue;
            }

            return $section->items();
        }

        self::fail('Missing explain section: ' . $id);
    }
}
