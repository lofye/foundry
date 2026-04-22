<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Explain\ExplainModel;
use Foundry\Generate\GeneratePlanPreviewBuilder;
use Foundry\Generate\GenerationContextPacket;
use Foundry\Generate\GenerationPlan;
use Foundry\Generate\Intent;
use Foundry\Generate\InteractiveGenerateReviewRequest;
use Foundry\Generate\TerminalInteractiveGenerateReviewer;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class TerminalInteractiveGenerateReviewerTest extends TestCase
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

    public function test_review_can_exclude_action_before_approval(): void
    {
        $base = $this->project->root . '/app/features/comments';
        mkdir($base, 0777, true);
        file_put_contents($base . '/feature.yaml', "version: 1\nfeature: comments\ndescription: Old\n");
        file_put_contents($base . '/prompts.md', "# comments\n\nOld notes.\n");

        $intent = new Intent(raw: 'Refine comments', mode: 'modify', interactive: true);
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

        $inputs = ['exclude action 2', 'approve'];
        $output = '';
        $reviewer = new TerminalInteractiveGenerateReviewer(
            inputReader: static function () use (&$inputs): string {
                return array_shift($inputs) ?? 'approve';
            },
            outputWriter: static function (string $text) use (&$output): void {
                $output .= $text;
            },
            previewBuilder: new GeneratePlanPreviewBuilder(new Paths($this->project->root)),
        );

        $result = $reviewer->review(new InteractiveGenerateReviewRequest(
            intent: $intent,
            plan: $plan,
            context: $this->context($intent),
            explainRendered: 'Current explain output',
        ));

        $this->assertTrue($result->approved);
        $this->assertTrue($result->modified);
        $this->assertCount(1, $result->plan->actions);
        $this->assertSame('app/features/comments/feature.yaml', $result->plan->actions[0]['path']);
        $this->assertStringContainsString('Interactive generate review', $output);
    }

    public function test_high_risk_review_requires_explicit_confirmation(): void
    {
        $path = $this->project->root . '/app/features/comments/legacy.txt';
        mkdir(dirname($path), 0777, true);
        file_put_contents($path, "legacy\n");

        $intent = new Intent(raw: 'Remove legacy comments file', mode: 'modify', interactive: true);
        $plan = new GenerationPlan(
            actions: [
                [
                    'type' => 'delete_file',
                    'path' => 'app/features/comments/legacy.txt',
                    'summary' => 'Delete legacy comments file.',
                    'explain_node_id' => 'feature:comments',
                ],
            ],
            affectedFiles: ['app/features/comments/legacy.txt'],
            risks: ['Deletes a file.'],
            validations: ['verify_feature'],
            origin: 'core',
            generatorId: 'core.feature.modify',
            metadata: [
                'execution' => [
                    'strategy' => 'unsupported_preview_strategy',
                ],
            ],
        );

        $inputs = ['approve', 'yes'];
        $reviewer = new TerminalInteractiveGenerateReviewer(
            inputReader: static function () use (&$inputs): string {
                return array_shift($inputs) ?? 'yes';
            },
            outputWriter: static function (string $text): void {},
            previewBuilder: new GeneratePlanPreviewBuilder(new Paths($this->project->root)),
        );

        $result = $reviewer->review(new InteractiveGenerateReviewRequest(
            intent: $intent,
            plan: $plan,
            context: $this->context($intent),
        ));

        $this->assertTrue($result->approved);
        $this->assertTrue($result->allowRisky);
        $this->assertSame('HIGH', $result->risk['level']);
    }

    /**
     * @return GenerationContextPacket
     */
    private function context(Intent $intent): GenerationContextPacket
    {
        return new GenerationContextPacket(
            intent: $intent,
            model: new ExplainModel(
                subject: ['id' => 'feature:comments', 'kind' => 'feature'],
                graph: [],
                execution: [],
                guards: [],
                events: [],
                schemas: [],
                relationships: ['graph' => ['inbound' => [], 'outbound' => [], 'lateral' => []]],
                diagnostics: [],
                docs: ['related' => []],
                impact: [],
                commands: [],
                metadata: [],
                extensions: [],
            ),
            targets: [['requested' => 'comments', 'resolved' => 'feature:comments']],
            graphRelationships: [],
            constraints: [],
            docs: [],
            validationSteps: ['verify_feature'],
            availableGenerators: [],
            installedPacks: [],
            missingCapabilities: [],
            suggestedPacks: [],
        );
    }
}
