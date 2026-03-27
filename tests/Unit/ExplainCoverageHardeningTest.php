<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Compiler\Analysis\ImpactAnalyzer;
use Foundry\Compiler\ApplicationGraph;
use Foundry\Compiler\IR\FeatureNode;
use Foundry\Explain\Analyzers\RelatedDocsAnalyzer;
use Foundry\Explain\Collectors\ImpactContextCollector;
use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;
use Foundry\Explain\RelationshipSection;
use Foundry\Explain\SuggestedFixesBuilder;
use Foundry\Support\Paths;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ExplainCoverageHardeningTest extends TestCase
{
    public function test_relationship_section_is_an_immutable_array_view(): void
    {
        $section = new RelationshipSection([
            'items' => [
                ['id' => 'feature:account', 'kind' => 'feature', 'label' => 'account'],
                ['id' => 'feature:account', 'kind' => 'feature', 'label' => 'account'],
                'skip-me',
            ],
        ]);

        $this->assertTrue(isset($section['items']));
        $this->assertSame(
            [['id' => 'feature:account', 'kind' => 'feature', 'label' => 'account']],
            $section->items(),
        );
        $this->assertSame($section->toArray(), iterator_to_array($section));
        $this->assertSame($section->toArray(), $section->jsonSerialize());
        $this->assertNull($section['missing']);

        try {
            $section['items'] = [];
            self::fail('Expected immutable explain section to reject writes.');
        } catch (LogicException $e) {
            $this->assertSame('Explain views are immutable.', $e->getMessage());
        }

        try {
            unset($section['items']);
            self::fail('Expected immutable explain section to reject unsets.');
        } catch (LogicException $e) {
            $this->assertSame('Explain views are immutable.', $e->getMessage());
        }
    }

    public function test_suggested_fixes_builder_covers_diagnostics_missing_relationships_and_action_binding(): void
    {
        $builder = new SuggestedFixesBuilder();
        $subject = new ExplainSubject(
            kind: 'route',
            id: 'route:POST /posts',
            label: 'POST /posts',
            graphNodeIds: ['route:POST:/posts'],
            aliases: ['POST /posts'],
            metadata: [],
        );

        $fixes = $builder->build($subject, [
            'diagnostics' => [
                'items' => [
                    ['suggested_fix' => 'Repair the route manifest.'],
                    ['message' => 'No subscribers: post.created'],
                    ['message' => 'Event has no subscribers: post.updated'],
                    'skip-me',
                    ['message' => ''],
                ],
            ],
            'permissions' => [
                'missing' => ['posts.create', ''],
            ],
            'dependencies' => [
                'items' => [
                    ['kind' => 'workflow', 'label' => 'editorial.publish', 'missing' => true],
                    ['kind' => 'job', 'label' => 'notify_followers', 'missing' => true],
                ],
            ],
            'dependents' => [
                'items' => [
                    ['kind' => 'schema', 'label' => 'post.input', 'missing' => true],
                ],
            ],
            'graph_relationships' => [
                'outbound' => [
                    ['kind' => 'event', 'label' => 'post.created', 'missing' => true],
                    ['kind' => 'extension', 'label' => 'openapi', 'missing' => true],
                ],
            ],
            'execution_flow' => [
                'action' => null,
            ],
        ]);

        $this->assertSame([
            'Repair the route manifest.',
            'Add a subscriber or workflow for event: post.created',
            'Add a subscriber or workflow for event: post.updated',
            'Add permission mapping for: posts.create',
            'Register event: post.created',
            'Register or remove reference to: openapi',
            'Register job: notify_followers',
            'Register schema: post.input',
            'Register workflow: editorial.publish',
            'Add a feature action binding to the execution pipeline.',
        ], $fixes);
    }

    public function test_suggested_fixes_builder_does_not_infer_route_action_fix_for_non_route_subjects(): void
    {
        $builder = new SuggestedFixesBuilder();
        $subject = new ExplainSubject(
            kind: 'command',
            id: 'command:doctor',
            label: 'doctor',
            graphNodeIds: [],
            aliases: ['doctor'],
            metadata: [],
        );

        $fixes = $builder->build($subject, [
            'execution_flow' => [
                'action' => null,
            ],
        ]);

        $this->assertSame([], $fixes);
    }

    public function test_related_docs_analyzer_honors_options_and_deduplicates_docs(): void
    {
        $subject = new ExplainSubject(
            kind: 'feature',
            id: 'feature:publish_post',
            label: 'publish_post',
            graphNodeIds: ['feature:publish_post'],
            aliases: ['publish_post'],
            metadata: [],
        );
        $context = new ExplainContext($subject, 'php bin/foundry');
        $context->setDocs([
            'items' => [
                ['title' => 'Architecture Tools', 'path' => '/docs/architecture-tools'],
                ['title' => 'Architecture Tools', 'path' => '/docs/architecture-tools'],
                'skip-me',
            ],
        ]);

        $analyzer = new RelatedDocsAnalyzer();

        $this->assertTrue($analyzer->supports($subject));
        $this->assertSame('related_docs', $analyzer->sectionId());
        $this->assertSame(['items' => []], $analyzer->analyze($subject, $context, new ExplainOptions(includeRelatedDocs: false)));
        $this->assertSame([
            'items' => [
                ['title' => 'Architecture Tools', 'path' => '/docs/architecture-tools'],
            ],
        ], $analyzer->analyze($subject, $context, new ExplainOptions(includeRelatedDocs: true)));
    }

    public function test_impact_context_collector_sets_impact_for_graph_subjects_and_ignores_empty_targets(): void
    {
        $graph = new ApplicationGraph(1, '1.0.0', '2026-03-21T00:00:00+00:00', 'hash');
        $graph->addNode(new FeatureNode(
            'feature:publish_post',
            'app/features/publish_post/feature.yaml',
            ['feature' => 'publish_post'],
        ));

        $collector = new ImpactContextCollector(new ImpactAnalyzer(Paths::fromCwd(getcwd() ?: '.')), $graph);

        $graphSubject = new ExplainSubject(
            kind: 'feature',
            id: 'feature:publish_post',
            label: 'publish_post',
            graphNodeIds: ['feature:publish_post'],
            aliases: ['publish_post'],
            metadata: [],
        );
        $graphContext = new ExplainContext($graphSubject, 'php bin/foundry');

        $this->assertTrue($collector->supports($graphSubject));
        $collector->collect($graphSubject, $graphContext, new ExplainOptions());
        $this->assertSame('feature:publish_post', $graphContext->impact()['node_id'] ?? null);
        $this->assertSame('feature', $graphContext->impact()['node_type'] ?? null);

        $emptySubject = new ExplainSubject(
            kind: 'command',
            id: 'command:doctor',
            label: 'doctor',
            graphNodeIds: [],
            aliases: ['doctor'],
            metadata: [],
        );
        $emptyContext = new ExplainContext($emptySubject, 'php bin/foundry');

        $this->assertFalse($collector->supports($emptySubject));
        $collector->collect($emptySubject, $emptyContext, new ExplainOptions());
        $this->assertNull($emptyContext->impact());
    }
}
