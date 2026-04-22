<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Generate\FeaturePlanBuilder;
use Foundry\Generate\GeneratePlanPreviewBuilder;
use Foundry\Generate\GenerationPlan;
use Foundry\Generate\Intent;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class GeneratePlanPreviewBuilderTest extends TestCase
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

    public function test_build_preview_for_modify_feature_plan_includes_unified_diff(): void
    {
        $base = $this->project->root . '/app/features/comments';
        mkdir($base, 0777, true);
        file_put_contents($base . '/feature.yaml', "version: 1\nfeature: comments\ndescription: Old\n");
        file_put_contents($base . '/prompts.md', "# comments\n\nOld notes.\n");

        $plan = new GenerationPlan(
            actions: [
                [
                    'type' => 'update_file',
                    'path' => 'app/features/comments/feature.yaml',
                    'summary' => 'Update feature manifest.',
                    'explain_node_id' => 'feature:comments',
                ],
                [
                    'type' => 'update_docs',
                    'path' => 'app/features/comments/prompts.md',
                    'summary' => 'Update prompts.',
                    'explain_node_id' => 'feature:comments',
                ],
            ],
            affectedFiles: [
                'app/features/comments/feature.yaml',
                'app/features/comments/prompts.md',
            ],
            risks: ['Updates feature metadata.'],
            validations: ['verify_feature'],
            origin: 'core',
            generatorId: 'core.feature.modify',
            metadata: [
                'execution' => [
                    'strategy' => 'modify_feature',
                    'manifest_path' => 'app/features/comments/feature.yaml',
                    'manifest' => [
                        'version' => 2,
                        'feature' => 'comments',
                        'description' => 'Updated description.',
                    ],
                    'prompts_path' => 'app/features/comments/prompts.md',
                    'prompts_content' => "# comments\n\nUpdated notes.\n",
                ],
                'feature' => 'comments',
            ],
        );

        $preview = (new GeneratePlanPreviewBuilder(new Paths($this->project->root)))->build(
            $plan,
            new Intent(raw: 'Refine comments', mode: 'modify', interactive: true),
        );

        $this->assertCount(2, $preview['files']);
        $this->assertSame('app/features/comments/feature.yaml', $preview['files'][0]['path']);
        $this->assertStringContainsString('--- a/app/features/comments/feature.yaml', $preview['files'][0]['unified_diff']);
        $this->assertStringContainsString('Updated description.', $preview['files'][0]['unified_diff']);
    }

    public function test_build_preview_for_feature_definition_plan_marks_created_files(): void
    {
        $feature = 'comment_notes';
        $requiredTests = ['contract'];
        $plan = new GenerationPlan(
            actions: FeaturePlanBuilder::scaffoldActions($feature, $requiredTests, 'feature:' . $feature),
            affectedFiles: FeaturePlanBuilder::predictedFiles($feature, $requiredTests),
            risks: ['Creates a new feature.'],
            validations: ['verify_feature'],
            origin: 'core',
            generatorId: 'core.feature.new',
            metadata: [
                'execution' => [
                    'strategy' => 'feature_definition',
                    'feature_definition' => [
                        'feature' => $feature,
                        'description' => 'Create comment notes.',
                        'kind' => 'http',
                        'owners' => ['platform'],
                        'route' => [
                            'method' => 'POST',
                            'path' => '/comments/{id}/notes',
                        ],
                        'input' => [
                            'fields' => [
                                'id' => ['type' => 'string', 'required' => true],
                            ],
                        ],
                        'output' => [
                            'fields' => [
                                'id' => ['type' => 'string', 'required' => true],
                            ],
                        ],
                        'auth' => [
                            'required' => true,
                            'strategies' => ['bearer'],
                            'permissions' => ['comments.note'],
                        ],
                        'database' => [
                            'reads' => ['comments'],
                            'writes' => ['comment_notes'],
                            'transactions' => 'required',
                            'queries' => ['comment_notes'],
                        ],
                        'cache' => [
                            'reads' => [],
                            'writes' => [],
                            'invalidate' => ['comments:list'],
                        ],
                        'events' => [
                            'emit' => ['comments.noted'],
                            'subscribe' => [],
                        ],
                        'jobs' => [
                            'dispatch' => [],
                        ],
                        'tests' => [
                            'required' => $requiredTests,
                        ],
                        'llm' => [
                            'editable' => true,
                            'risk_level' => 'medium',
                            'notes_file' => 'prompts.md',
                        ],
                    ],
                ],
                'feature' => $feature,
            ],
        );

        $preview = (new GeneratePlanPreviewBuilder(new Paths($this->project->root)))->build(
            $plan,
            new Intent(raw: 'Create comment notes', mode: 'new', interactive: true),
        );

        $featureYaml = array_values(array_filter(
            $preview['files'],
            static fn(array $file): bool => $file['path'] === 'app/features/' . $feature . '/feature.yaml',
        ));

        $this->assertCount(1, $featureYaml);
        $this->assertSame('create', $featureYaml[0]['change_type']);
        $this->assertStringContainsString('+++ b/app/features/' . $feature . '/feature.yaml', $featureYaml[0]['unified_diff']);
    }
}
