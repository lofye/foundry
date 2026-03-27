<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Compiler\ApplicationGraph;
use Foundry\Compiler\BuildLayout;
use Foundry\Compiler\IR\FeatureNode;
use Foundry\Explain\Analyzers\SectionAnalyzerInterface;
use Foundry\Explain\Analyzers\SubjectAnalysisResult;
use Foundry\Explain\Analyzers\SubjectAnalyzerInterface;
use Foundry\Explain\ExplainArtifactCatalog;
use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainSection;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;
use Foundry\Explain\ExplainSubjectFactory;
use Foundry\Explain\ExplanationPlanAssembler;
use Foundry\Explain\SuggestedFixesBuilder;
use Foundry\Explain\SummarySectionBuilder;
use Foundry\Explain\ExplainTarget;
use Foundry\Explain\ExplainTargetResolver;
use Foundry\Support\ApiSurfaceRegistry;
use Foundry\Support\FoundryError;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class ExplainFoundationTest extends TestCase
{
    private TempProject $project;
    private Paths $paths;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->paths = Paths::fromCwd($this->project->root);
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function test_target_parse_preserves_explicit_kind_even_when_unsupported(): void
    {
        $target = ExplainTarget::parse('unknown:thing');

        $this->assertSame('unknown:thing', $target->raw);
        $this->assertSame('unknown', $target->kind);
        $this->assertSame('thing', $target->selector);
    }

    public function test_resolver_fails_cleanly_for_unsupported_kind(): void
    {
        $resolver = new ExplainTargetResolver(
            new ApplicationGraph(1, '1.0.0', '2026-03-20T00:00:00+00:00', 'hash'),
            new ExplainArtifactCatalog(new BuildLayout($this->paths), $this->paths, new ApiSurfaceRegistry()),
        );

        $this->expectException(FoundryError::class);
        $this->expectExceptionMessage('Unsupported explain target kind: unknown.');

        try {
            $resolver->resolve(ExplainTarget::parse('unknown:thing'));
        } catch (FoundryError $error) {
            $this->assertSame('EXPLAIN_TARGET_KIND_UNSUPPORTED', $error->errorCode);
            $this->assertContains('feature', (array) ($error->details['supported_kinds'] ?? []));
            throw $error;
        }
    }

    public function test_subject_factory_normalizes_graph_and_command_subjects(): void
    {
        $factory = new ExplainSubjectFactory();
        $node = new FeatureNode('feature:publish_post', 'app/features/publish_post/feature.yaml', [
            'feature' => 'publish_post',
            'description' => 'publish post',
        ]);

        $graphSubject = $factory->fromGraphNode($node);
        $commandSubject = $factory->fromCommandRow([
            'signature' => 'doctor',
            'usage' => 'doctor --json',
        ]);

        $this->assertSame('feature', $graphSubject->kind);
        $this->assertSame('feature:publish_post', $graphSubject->id);
        $this->assertSame(['feature:publish_post'], $graphSubject->graphNodeIds);
        $this->assertSame('command', $commandSubject?->kind);
        $this->assertSame('command:doctor', $commandSubject?->id);
        $this->assertSame(['doctor'], $commandSubject?->aliases);
    }

    public function test_context_initializes_foundation_placeholders(): void
    {
        $subject = new ExplainSubject(
            'feature',
            'feature:publish_post',
            'publish_post',
            ['feature:publish_post'],
            ['publish_post'],
            ['feature' => 'publish_post'],
        );
        $context = new ExplainContext(
            $subject,
            'foundry',
        );

        $this->assertSame($subject, $context->subject);
        $this->assertSame('foundry', $context->commandPrefix);
        $this->assertArrayHasKey('subject_node', $context->all());
        $this->assertArrayHasKey('graph_neighborhood', $context->all());
        $this->assertArrayHasKey('pipeline', $context->all());
        $this->assertArrayHasKey('commands', $context->all());
        $this->assertArrayHasKey('workflows', $context->all());
        $this->assertArrayHasKey('events', $context->all());
        $this->assertArrayHasKey('schemas', $context->all());
        $this->assertArrayHasKey('extensions', $context->all());
        $this->assertArrayHasKey('diagnostics', $context->all());
        $this->assertArrayHasKey('docs', $context->all());
    }

    public function test_plan_assembler_orders_sections_and_omits_truly_empty_ones(): void
    {
        $subject = new ExplainSubject(
            kind: 'feature',
            id: 'feature:publish_post',
            label: 'publish_post',
            graphNodeIds: ['feature:publish_post'],
            aliases: ['publish_post'],
            metadata: ['feature' => 'publish_post'],
        );
        $context = new ExplainContext($subject, 'foundry');

        $assembler = new ExplanationPlanAssembler(
            new SummarySectionBuilder(),
            new SuggestedFixesBuilder(),
            [
                new class implements SubjectAnalyzerInterface
                {
                    public function supports(ExplainSubject $subject): bool
                    {
                        return $subject->kind === 'feature';
                    }

                    public function analyze(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): SubjectAnalysisResult
                    {
                        return new SubjectAnalysisResult(
                            responsibilities: ['Own the publish_post lifecycle'],
                            summaryInputs: [
                                'feature' => 'publish_post',
                                'description' => 'publish post',
                            ],
                            sections: [
                                ['id' => 'impact', 'title' => 'Impact', 'items' => ['risk' => 'low']],
                                ['id' => 'notes', 'title' => 'Notes', 'items' => ['owner' => 'core']],
                                ['id' => 'empty', 'title' => 'Empty', 'items' => []],
                            ],
                        );
                    }
                },
            ],
            [
                new class implements SectionAnalyzerInterface
                {
                    public function supports(ExplainSubject $subject): bool
                    {
                        return true;
                    }

                    public function sectionId(): string
                    {
                        return 'dependencies';
                    }

                    public function analyze(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): array
                    {
                        return [
                            'items' => [
                                ['kind' => 'feature', 'label' => 'account'],
                            ],
                        ];
                    }
                },
                new class implements SectionAnalyzerInterface
                {
                    public function supports(ExplainSubject $subject): bool
                    {
                        return true;
                    }

                    public function sectionId(): string
                    {
                        return 'related_commands';
                    }

                    public function analyze(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): array
                    {
                        return [
                            'items' => [
                                'foundry doctor',
                                'foundry doctor',
                            ],
                        ];
                    }
                },
                new class implements SectionAnalyzerInterface
                {
                    public function supports(ExplainSubject $subject): bool
                    {
                        return true;
                    }

                    public function sectionId(): string
                    {
                        return 'related_docs';
                    }

                    public function analyze(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): array
                    {
                        return [
                            'items' => [
                                ['title' => 'Feature Docs', 'path' => 'docs/features.md'],
                                ['title' => 'Feature Docs', 'path' => 'docs/features.md'],
                            ],
                        ];
                    }
                },
                new class implements SectionAnalyzerInterface
                {
                    public function supports(ExplainSubject $subject): bool
                    {
                        return true;
                    }

                    public function sectionId(): string
                    {
                        return 'diagnostics';
                    }

                    public function analyze(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): array
                    {
                        return [
                            'summary' => ['error' => 0, 'warning' => 0, 'info' => 0, 'total' => 0],
                            'items' => [],
                        ];
                    }
                },
            ],
        );

        $plan = $assembler->assemble(
            $subject,
            $context,
            new ExplainOptions(type: 'feature'),
            metadata: ['options' => (new ExplainOptions(type: 'feature'))->toArray()],
        );

        $this->assertSame(
            ['subject', 'summary', 'responsibilities', 'dependencies', 'related_commands', 'related_docs', 'diagnostics', 'notes', 'impact'],
            $plan->sectionOrder,
        );
        $this->assertSame(
            ['notes', 'impact'],
            array_values(array_map(static fn (ExplainSection $section): string => $section->id(), $plan->sections)),
        );
        $this->assertCount(1, $plan->relatedCommands);
        $this->assertCount(1, $plan->relatedDocs);
        $this->assertSame(['items' => [['kind' => 'feature', 'label' => 'account']]], $plan->dependencies->toArray());
        $this->assertStringContainsString('Publish post.', (string) $plan->summary['text']);
        $this->assertSame('feature', $plan->metadata['options']['type']);
    }
}
