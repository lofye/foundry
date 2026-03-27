<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Explain\Contributors\ExplainContribution;
use Foundry\Explain\Contributors\ExplainContributorInterface;
use Foundry\Explain\Contributors\ExplainContributorRegistry;
use Foundry\Explain\DiagnosticsContextData;
use Foundry\Explain\DiagnosticsSection;
use Foundry\Explain\DocsContextData;
use Foundry\Explain\ExecutionFlowSection;
use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSection;
use Foundry\Explain\ExplainSubject;
use Foundry\Explain\ExplanationPlan;
use Foundry\Explain\GraphNeighborhoodContext;
use Foundry\Explain\GraphRelationshipsSection;
use Foundry\Explain\PipelineContextData;
use Foundry\Explain\RelationshipSection;
use Foundry\Explain\Renderers\JsonExplanationRenderer;
use PHPUnit\Framework\TestCase;

final class ExplainPolishTest extends TestCase
{
    public function test_context_promotes_high_value_slices_into_typed_views(): void
    {
        $subject = new ExplainSubject(
            kind: 'route',
            id: 'route:POST /posts',
            label: 'POST /posts',
            graphNodeIds: ['route:POST:/posts'],
            aliases: ['POST /posts'],
            metadata: ['feature' => 'publish_post'],
        );
        $context = new ExplainContext($subject, 'php bin/foundry');

        $context->setGraphNeighborhood([
            'dependencies' => [
                ['id' => 'feature:publish_post', 'kind' => 'feature', 'label' => 'publish_post'],
            ],
        ]);
        $context->setPipeline([
            'feature' => 'publish_post',
            'guards' => [
                ['id' => 'guard:auth', 'type' => 'authentication', 'stage' => 'auth'],
            ],
        ]);
        $context->setDiagnostics([
            'summary' => ['error' => 1, 'warning' => 0, 'info' => 0, 'total' => 1],
            'items' => [
                ['severity' => 'error', 'code' => 'FDY1001', 'message' => 'Broken wiring.'],
            ],
        ]);
        $context->setDocs([
            'items' => [
                ['title' => 'Execution Pipeline', 'path' => '/docs/execution-pipeline'],
            ],
        ]);

        $this->assertInstanceOf(GraphNeighborhoodContext::class, $context->graphNeighborhood());
        $this->assertInstanceOf(PipelineContextData::class, $context->pipeline());
        $this->assertInstanceOf(DiagnosticsContextData::class, $context->diagnostics());
        $this->assertInstanceOf(DocsContextData::class, $context->docs());
        $this->assertSame('publish_post', $context->pipeline()['feature']);
        $this->assertSame(1, $context->diagnostics()['summary']['total']);
        $this->assertSame('/docs/execution-pipeline', $context->docs()['items'][0]['path']);
    }

    public function test_plan_and_json_renderer_expose_deliberate_public_contract(): void
    {
        $plan = new ExplanationPlan(
            subject: ['id' => 'feature:publish_post', 'kind' => 'feature', 'label' => 'publish_post'],
            summary: ['text' => 'Publish post is a feature.', 'deterministic' => true, 'deep' => false],
            responsibilities: ['items' => ['Serve the publish flow']],
            executionFlow: new ExecutionFlowSection([
                'entries' => [['kind' => 'action', 'label' => 'publish_post feature action']],
                'action' => ['feature' => 'publish_post'],
            ]),
            dependencies: new RelationshipSection([
                'items' => [['kind' => 'feature', 'label' => 'account']],
            ]),
            dependents: new RelationshipSection([
                'items' => [['kind' => 'route', 'label' => 'POST /posts']],
            ]),
            emits: ['items' => [['kind' => 'event', 'label' => 'post.created']]],
            triggers: ['items' => [['kind' => 'workflow', 'label' => 'editorial']]],
            permissions: ['required' => ['posts.create']],
            schemaInteraction: ['items' => [['kind' => 'schema', 'label' => 'input.schema.json']]],
            graphRelationships: new GraphRelationshipsSection([
                'outbound' => [['kind' => 'event', 'label' => 'post.created']],
            ]),
            diagnostics: new DiagnosticsSection([
                'summary' => ['error' => 0, 'warning' => 1, 'info' => 0, 'total' => 1],
                'items' => [['severity' => 'warning', 'message' => 'Event has no subscribers: post.created']],
            ]),
            relatedCommands: ['php bin/foundry inspect graph --json'],
            relatedDocs: [['title' => 'Architecture Tools', 'path' => '/docs/architecture-tools']],
            suggestedFixes: ['Add a subscriber or workflow for event: post.created'],
            sections: [
                ExplainSection::fromArray([
                    'id' => 'command_surface',
                    'title' => 'Command Surface',
                    'items' => ['signature' => 'doctor'],
                ]),
            ],
            sectionOrder: ['subject', 'summary', 'responsibilities', 'diagnostics', 'command_surface'],
            metadata: ['target' => ['selector' => 'publish_post']],
        );

        $payload = $plan->toArray();
        $json = json_decode((new JsonExplanationRenderer())->render($plan), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(
            ['subject', 'summary', 'responsibilities', 'executionFlow', 'relationships', 'emits', 'triggers', 'permissions', 'schemaInteraction', 'relatedCommands', 'relatedDocs', 'diagnostics', 'suggestedFixes', 'sections', 'sectionOrder', 'metadata'],
            array_keys($payload),
        );
        $this->assertSame($payload, $json);
        $this->assertSame('account', $json['relationships']['dependsOn']['items'][0]['label']);
        $this->assertSame('POST /posts', $json['relationships']['usedBy']['items'][0]['label']);
        $this->assertSame('post.created', $json['relationships']['graph']['outbound'][0]['label']);
        $this->assertSame('php bin/foundry inspect graph --json', $json['relatedCommands'][0]);
        $this->assertSame('/docs/architecture-tools', $json['relatedDocs'][0]['path']);
    }

    public function test_section_inference_and_contributor_registry_are_deterministic(): void
    {
        $subject = new ExplainSubject(
            kind: 'feature',
            id: 'feature:publish_post',
            label: 'publish_post',
            graphNodeIds: ['feature:publish_post'],
            aliases: ['publish_post'],
            metadata: ['feature' => 'publish_post'],
        );
        $context = new ExplainContext($subject, 'php bin/foundry');
        $options = new ExplainOptions();

        $stringList = ExplainSection::fromArray([
            'id' => 'capabilities',
            'title' => 'Capabilities',
            'items' => ['capability: explain.fixture', 'pack: fixture.pack'],
        ]);
        $keyValue = ExplainSection::fromArray([
            'id' => 'command_surface',
            'title' => 'Command Surface',
            'items' => ['signature' => 'doctor'],
        ]);

        $registry = new ExplainContributorRegistry([
            new class implements ExplainContributorInterface
            {
                public function supports(ExplainSubject $subject): bool
                {
                    return false;
                }

                public function contribute(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): ExplainContribution
                {
                    return new ExplainContribution();
                }
            },
            new class($stringList) implements ExplainContributorInterface
            {
                public function __construct(private ExplainSection $section)
                {
                }

                public function supports(ExplainSubject $subject): bool
                {
                    return $subject->kind === 'feature';
                }

                public function contribute(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): ExplainContribution
                {
                    return new ExplainContribution(
                        sections: [$this->section],
                        relatedCommands: [$context->commandPrefix . ' inspect feature ' . $subject->label . ' --json'],
                        relatedDocs: [['title' => 'Feature Docs', 'path' => '/docs/features/publish-post']],
                    );
                }
            },
            new class($keyValue) implements ExplainContributorInterface
            {
                public function __construct(private ExplainSection $section)
                {
                }

                public function supports(ExplainSubject $subject): bool
                {
                    return $subject->kind === 'feature';
                }

                public function contribute(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): ExplainContribution
                {
                    return new ExplainContribution(sections: [$this->section]);
                }
            },
        ]);

        $contributions = $registry->contributionsFor($subject, $context, $options);

        $this->assertSame('string_list', $stringList->shape());
        $this->assertSame('key_value', $keyValue->shape());
        $this->assertCount(2, $contributions);
        $this->assertSame('capabilities', $contributions[0]->sections[0]->id());
        $this->assertSame('command_surface', $contributions[1]->sections[0]->id());
        $this->assertSame('php bin/foundry inspect feature publish_post --json', $contributions[0]->relatedCommands[0]);
        $this->assertSame('/docs/features/publish-post', $contributions[0]->relatedDocs[0]['path']);
    }

    public function test_docs_describe_markdown_deep_and_contributor_registry(): void
    {
        $readme = (string) file_get_contents(__DIR__ . '/../../README.md');
        $architectureDocs = (string) file_get_contents(__DIR__ . '/../../docs/architecture-tools.md');
        $extensionGuide = (string) file_get_contents(__DIR__ . '/../../docs/extension-author-guide.md');

        $this->assertStringContainsString('--markdown', $readme);
        $this->assertStringContainsString('--deep', $readme);
        $this->assertStringContainsString('ExplainContribution', $readme);
        $this->assertStringContainsString('executionFlow', $architectureDocs);
        $this->assertStringContainsString('relatedCommands', $architectureDocs);
        $this->assertStringContainsString('ExplainContributorRegistry', $architectureDocs);
        $this->assertStringContainsString('ExplainContributorInterface', $extensionGuide);
        $this->assertStringContainsString('ExplainContribution', $extensionGuide);
    }
}
