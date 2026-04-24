<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Explain\ExplainModel;
use Foundry\Generate\GeneratePolicyEngine;
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

    public function test_review_supports_help_and_inspection_commands_before_rejection(): void
    {
        $base = $this->project->root . '/app/features/comments';
        mkdir($base, 0777, true);
        file_put_contents($base . '/permissions.yaml', "version: 1\npermissions: []\n");

        $intent = new Intent(raw: 'Inspect comments', mode: 'modify', interactive: true);
        $plan = new GenerationPlan(
            actions: [[
                'type' => 'update_file',
                'path' => 'app/features/comments/permissions.yaml',
                'summary' => 'Update permissions.',
                'explain_node_id' => 'feature:comments',
            ]],
            affectedFiles: ['app/features/comments/permissions.yaml'],
            risks: ['Updates permissions.'],
            validations: ['verify_feature'],
            origin: 'core',
            generatorId: 'core.feature.modify',
            metadata: ['feature' => 'comments'],
        );

        $inputs = ['help', 'inspect graph', 'inspect explain', 'inspect action 1', 'bogus', 'reject'];
        $output = '';
        $reviewer = new TerminalInteractiveGenerateReviewer(
            inputReader: static function () use (&$inputs): string {
                return array_shift($inputs) ?? 'reject';
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
            explainRendered: "Line one\nLine two",
        ));

        $this->assertFalse($result->approved);
        $this->assertStringContainsString('Supported review commands:', $output);
        $this->assertStringContainsString('Graph inspection:', $output);
        $this->assertStringContainsString('Explain inspection:', $output);
        $this->assertStringContainsString('Action inspection:', $output);
        $this->assertStringContainsString('Unknown review command.', $output);
        $this->assertSame(['auth'], $result->preview['actions'][0]['dependencies']);
    }

    public function test_review_can_toggle_risky_actions_and_handle_empty_or_invalid_selections(): void
    {
        $base = $this->project->root . '/app/features/comments';
        mkdir($base, 0777, true);
        file_put_contents($base . '/legacy.txt', "legacy\n");
        file_put_contents($base . '/prompts.md', "# comments\n");

        $intent = new Intent(raw: 'Refine comments', mode: 'modify', interactive: true);
        $plan = new GenerationPlan(
            actions: [
                [
                    'type' => 'delete_file',
                    'path' => 'app/features/comments/legacy.txt',
                    'summary' => 'Delete legacy file.',
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
                'app/features/comments/legacy.txt',
                'app/features/comments/prompts.md',
            ],
            risks: ['Deletes a file.'],
            validations: ['verify_feature'],
            origin: 'core',
            generatorId: 'core.feature.modify',
            metadata: ['feature' => 'comments'],
        );

        $inputs = ['exclude action 9', 'exclude file 2', 'toggle risky', 'toggle risky', 'approve', 'yes'];
        $output = '';
        $reviewer = new TerminalInteractiveGenerateReviewer(
            inputReader: static function () use (&$inputs): string {
                return array_shift($inputs) ?? 'reject';
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
        ));

        $this->assertTrue($result->approved);
        $this->assertStringContainsString('Action index is out of range.', $output);
        $this->assertStringContainsString('Excluded file `app/features/comments/prompts.md`.', $output);
        $this->assertStringContainsString('Risk toggle would remove every action from the plan.', $output);
        $this->assertStringContainsString('Excluded risky actions.', $output);
        $this->assertStringNotContainsString('Restored risky actions.', $output);
    }

    public function test_review_surfaces_policy_violations_and_allows_explicit_override_confirmation(): void
    {
        $dir = $this->project->root . '/.foundry/policies';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/generate.json', json_encode([
            'version' => 1,
            'rules' => [[
                'id' => 'protect-features',
                'type' => 'deny',
                'description' => 'Prevent feature file creation without explicit override.',
                'match' => [
                    'actions' => ['create_file'],
                    'paths' => ['app/features/**'],
                ],
            ]],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);

        $intent = new Intent(raw: 'Create comments', mode: 'new', interactive: true);
        $plan = new GenerationPlan(
            actions: [[
                'type' => 'create_file',
                'path' => 'app/features/comments/feature.yaml',
                'summary' => 'Create feature manifest.',
                'explain_node_id' => 'feature:comments',
            ]],
            affectedFiles: ['app/features/comments/feature.yaml'],
            risks: ['Creates feature files.'],
            validations: ['verify_feature'],
            origin: 'core',
            generatorId: 'core.feature.new',
            metadata: ['feature' => 'comments'],
        );

        $inputs = ['approve', 'yes'];
        $output = '';
        $reviewer = new TerminalInteractiveGenerateReviewer(
            inputReader: static function () use (&$inputs): string {
                return array_shift($inputs) ?? 'yes';
            },
            outputWriter: static function (string $text) use (&$output): void {
                $output .= $text;
            },
            previewBuilder: new GeneratePlanPreviewBuilder(new Paths($this->project->root)),
            policyEngine: new GeneratePolicyEngine(new Paths($this->project->root)),
        );

        $result = $reviewer->review(new InteractiveGenerateReviewRequest(
            intent: $intent,
            plan: $plan,
            context: $this->context($intent),
        ));

        $this->assertTrue($result->approved);
        $this->assertTrue($result->allowPolicyViolations);
        $this->assertStringContainsString('Policy status: DENY', $output);
        $this->assertStringContainsString('Violation: Prevent feature file creation without explicit override.', $output);
    }

    public function test_review_re_evaluates_policy_after_excluding_offending_action(): void
    {
        $dir = $this->project->root . '/.foundry/policies';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/generate.json', json_encode([
            'version' => 1,
            'rules' => [[
                'id' => 'protect-feature-deletes',
                'type' => 'deny',
                'description' => 'Prevent feature file deletion.',
                'match' => [
                    'actions' => ['delete_file'],
                    'paths' => ['app/features/**'],
                ],
            ]],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);

        $legacyPath = $this->project->root . '/app/features/comments/legacy.txt';
        $docsPath = $this->project->root . '/app/features/comments/prompts.md';
        mkdir(dirname($legacyPath), 0777, true);
        file_put_contents($legacyPath, "legacy\n");
        file_put_contents($docsPath, "# comments\n");

        $intent = new Intent(raw: 'Refine comments', mode: 'modify', interactive: true);
        $plan = new GenerationPlan(
            actions: [
                [
                    'type' => 'delete_file',
                    'path' => 'app/features/comments/legacy.txt',
                    'summary' => 'Delete legacy file.',
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
                'app/features/comments/legacy.txt',
                'app/features/comments/prompts.md',
            ],
            risks: ['Deletes legacy files.'],
            validations: ['verify_feature'],
            origin: 'core',
            generatorId: 'core.feature.modify',
            metadata: ['feature' => 'comments'],
        );

        $inputs = ['exclude action 1', 'approve'];
        $reviewer = new TerminalInteractiveGenerateReviewer(
            inputReader: static function () use (&$inputs): string {
                return array_shift($inputs) ?? 'approve';
            },
            outputWriter: static function (string $text): void {},
            previewBuilder: new GeneratePlanPreviewBuilder(new Paths($this->project->root)),
            policyEngine: new GeneratePolicyEngine(new Paths($this->project->root)),
        );

        $result = $reviewer->review(new InteractiveGenerateReviewRequest(
            intent: $intent,
            plan: $plan,
            context: $this->context($intent),
        ));

        $this->assertTrue($result->approved);
        $this->assertFalse($result->allowPolicyViolations);
        $this->assertCount(1, $result->plan->actions);
        $this->assertSame('app/features/comments/prompts.md', $result->plan->actions[0]['path']);
        $this->assertSame('pass', $result->preview['summary']['policy_status']);
    }

    public function test_toggle_risky_reports_when_original_plan_has_no_risky_actions(): void
    {
        $base = $this->project->root . '/app/features/comments';
        mkdir($base, 0777, true);
        file_put_contents($base . '/prompts.md', "# comments\n");

        $intent = new Intent(raw: 'Refine comments', mode: 'modify', interactive: true);
        $plan = new GenerationPlan(
            actions: [[
                'type' => 'update_docs',
                'path' => 'app/features/comments/prompts.md',
                'summary' => 'Update prompts.',
                'explain_node_id' => 'feature:comments',
            ]],
            affectedFiles: ['app/features/comments/prompts.md'],
            risks: ['Updates prompts.'],
            validations: ['verify_feature'],
            origin: 'core',
            generatorId: 'core.feature.modify',
            metadata: ['feature' => 'comments'],
        );

        $inputs = ['toggle risky', 'reject'];
        $output = '';
        $reviewer = new TerminalInteractiveGenerateReviewer(
            inputReader: static function () use (&$inputs): string {
                return array_shift($inputs) ?? 'reject';
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
        ));

        $this->assertFalse($result->approved);
        $this->assertStringContainsString('No risky actions are currently present in the original plan.', $output);
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
