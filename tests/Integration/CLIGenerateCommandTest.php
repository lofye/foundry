<?php

declare(strict_types=1);

namespace Foundry\Tests\Integration;

use Foundry\CLI\Application;
use Foundry\CLI\CommandContext;
use Foundry\CLI\Commands\GenerateCommand;
use Foundry\Generate\GenerationPlan;
use Foundry\Generate\InteractiveGenerateReviewRequest;
use Foundry\Generate\InteractiveGenerateReviewResult;
use Foundry\Generate\InteractiveGenerateReviewer;
use Foundry\Packs\HostedPackRegistry;
use Foundry\Packs\PackChecksum;
use Foundry\Packs\PackManager;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class CLIGenerateCommandTest extends TestCase
{
    private TempProject $project;
    private string $cwd;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->cwd = getcwd() ?: '.';
        chdir($this->project->root);
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        $this->project->cleanup();
    }

    public function test_generate_reports_missing_pack_without_auto_install(): void
    {
        $app = new Application();

        $result = $this->runCommand($app, [
            'foundry',
            'generate',
            'Create',
            'blog',
            'post',
            'notes',
            '--mode=new',
            '--packs=foundry/blog',
            '--json',
        ]);

        $this->assertSame(1, $result['status']);
        $this->assertSame('GENERATE_PACK_INSTALL_REQUIRED', $result['payload']['error']['code']);
        $this->assertSame(['pack:foundry/blog'], $result['payload']['error']['details']['missing_capabilities']);
        $this->assertSame(['foundry/blog'], $result['payload']['error']['details']['suggested_packs']);
        $this->assertFileExists($this->project->root . '/.foundry/snapshots/pre-generate.json');
        $this->assertFileDoesNotExist($this->project->root . '/.foundry/snapshots/post-generate.json');
        $this->assertFileDoesNotExist($this->project->root . '/.foundry/diffs/last.json');
    }

    public function test_generate_metrics_disabled_by_default_writes_no_metrics_records(): void
    {
        $app = new Application();

        $generate = $this->runCommand($app, [
            'foundry',
            'generate',
            'Create',
            'metrics',
            'disabled',
            '--mode=new',
            '--json',
        ]);

        $this->assertSame(0, $generate['status']);
        $this->assertFileDoesNotExist($this->project->root . '/.foundry/metrics/generate-metrics.json');
    }

    public function test_generate_metrics_enabled_writes_deterministic_metrics_record(): void
    {
        $this->writeJsonFile('.foundry/config/metrics.json', ['metrics' => ['enabled' => true]]);
        $app = new Application();

        $generate = $this->runCommand($app, [
            'foundry',
            'generate',
            'Create',
            'metrics',
            'enabled',
            '--mode=new',
            '--json',
        ]);

        $this->assertSame(0, $generate['status']);
        $path = $this->project->root . '/.foundry/metrics/generate-metrics.json';
        $this->assertFileExists($path);
        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('foundry.generate.metrics_store.v1', $payload['schema']);
        $this->assertIsArray($payload['records']);
        $this->assertCount(1, $payload['records']);
        $record = $payload['records'][0];
        $this->assertSame('foundry.generate.metrics_record.v1', $record['schema']);
        $this->assertSame('single', $record['type']);
        $this->assertSame('completed', $record['status']);
        $this->assertSame(0, $record['entry_index']);
        $this->assertNull($record['timestamp']);
    }

    public function test_generate_can_require_approval_and_block_execution_until_reviewed(): void
    {
        $app = new Application();

        $generate = $this->runCommand($app, [
            'foundry',
            'generate',
            'Create',
            'comments',
            '--mode=new',
            '--require-approval',
            '--min-approvals=2',
            '--json',
        ]);

        $this->assertSame(1, $generate['status']);
        $this->assertFalse($generate['payload']['ok']);
        $this->assertSame('GENERATE_APPROVAL_REQUIRED', $generate['payload']['error']['code']);
        $this->assertSame('pending_approval', $generate['payload']['plan_record']['status']);
        $this->assertFileDoesNotExist($this->project->root . '/app/features/comments/feature.yaml');

        $approveA = $this->runCommand($app, [
            'foundry',
            'generate',
            '--approve',
            '--plan-id=' . (string) $generate['payload']['plan_record']['plan_id'],
            '--user=alice',
            '--json',
        ]);
        $approveB = $this->runCommand($app, [
            'foundry',
            'generate',
            '--approve',
            '--plan-id=' . (string) $generate['payload']['plan_record']['plan_id'],
            '--user=bob',
            '--json',
        ]);

        $this->assertSame(0, $approveA['status']);
        $this->assertSame('pending', $approveA['payload']['status']);
        $this->assertSame(0, $approveB['status']);
        $this->assertSame('approved', $approveB['payload']['status']);
    }

    public function test_generate_reject_action_sets_rejected_state(): void
    {
        $app = new Application();

        $generate = $this->runCommand($app, [
            'foundry',
            'generate',
            'Create',
            'approvals',
            '--mode=new',
            '--require-approval',
            '--json',
        ]);
        $planId = (string) $generate['payload']['plan_record']['plan_id'];
        $reject = $this->runCommand($app, [
            'foundry',
            'generate',
            '--reject',
            '--plan-id=' . $planId,
            '--user=maintainer',
            '--json',
        ]);

        $show = $this->runCommand($app, ['foundry', 'plan:show', $planId, '--json']);
        $this->assertSame(0, $reject['status']);
        $this->assertSame('rejected', $reject['payload']['status']);
        $this->assertSame('pending_approval', $show['payload']['status']);
    }

    public function test_generate_uses_installed_pack_generator_when_pack_is_available(): void
    {
        $app = new Application();

        $install = $this->runCommand($app, ['foundry', 'pack', 'install', $this->fixturePath('foundry-blog'), '--json']);
        $this->assertSame(0, $install['status']);

        $generate = $this->runCommand($app, [
            'foundry',
            'generate',
            'Create',
            'blog',
            'post',
            'notes',
            '--mode=new',
            '--packs=foundry/blog',
            '--json',
        ]);

        $this->assertSame(0, $generate['status']);
        $this->assertSame('pack', $generate['payload']['plan']['origin']);
        $this->assertSame('generate blog-post', $generate['payload']['plan']['generator_id']);
        $this->assertArrayHasKey('confidence', $generate['payload']['plan']);
        $this->assertArrayHasKey('plan_confidence', $generate['payload']);
        $this->assertArrayHasKey('outcome_confidence', $generate['payload']);
        $this->assertSame(['foundry/blog'], $generate['payload']['packs_used']);
        $this->assertSame('pack:foundry/blog', $generate['payload']['metadata']['target']['resolved']);
        $this->assertFileExists($this->project->root . '/app/features/blog_post_notes/feature.yaml');
    }

    public function test_generate_can_auto_install_required_pack_when_allowed(): void
    {
        $downloadUrl = 'https://downloads.example/foundry-blog-1.0.0.zip';
        $manifest = $this->fixtureManifest('foundry-blog');
        $app = $this->hostedGenerateApplication(
            [[
                'name' => 'foundry/blog',
                'version' => '1.0.0',
                'description' => 'Blog workflow tools',
                'download_url' => $downloadUrl,
                'checksum' => $manifest['checksum'],
                'signature' => $manifest['signature'],
                'verified' => true,
            ]],
            [$downloadUrl => $this->fixtureArchive('foundry-blog')],
        );

        $generate = $this->runCommand($app, [
            'foundry',
            'generate',
            'Create',
            'blog',
            'post',
            'notes',
            '--mode=new',
            '--packs=foundry/blog',
            '--allow-pack-install',
            '--json',
        ]);

        $this->assertSame(0, $generate['status']);
        $this->assertSame('pack', $generate['payload']['plan']['origin']);
        $this->assertSame('foundry/blog', $generate['payload']['packs_installed'][0]['pack']);
        $this->assertSame('registry', $generate['payload']['packs_installed'][0]['source']['type']);
        $this->assertArrayHasKey('plan_confidence', $generate['payload']);
        $this->assertArrayHasKey('outcome_confidence', $generate['payload']);
        $this->assertFileExists($this->project->root . '/.foundry/packs/foundry/blog/1.0.0/foundry.json');
        $this->assertFileExists($this->project->root . '/app/features/blog_post_notes/feature.yaml');
    }

    public function test_generate_workflow_executes_steps_sequentially_and_merges_step_context(): void
    {
        $this->writeGenerateWorkflow([
            'shared_context' => [
                'resource' => 'comments',
            ],
            'steps' => [
                [
                    'id' => 'create_comments',
                    'intent' => 'Create {{shared.resource}}',
                    'mode' => 'new',
                ],
                [
                    'id' => 'create_audit',
                    'intent' => 'Create {{steps.create_comments.feature}} audit',
                    'mode' => 'new',
                    'dependencies' => ['create_comments'],
                ],
            ],
        ]);

        $app = new Application();
        $result = $this->runCommand($app, [
            'foundry',
            'generate',
            '--workflow=generate-workflow.json',
            '--multi-step',
            '--json',
        ]);

        $this->assertSame(0, $result['status']);
        $this->assertTrue($result['payload']['ok']);
        $this->assertSame('completed', $result['payload']['workflow']['status']);
        $this->assertSame('foundry.generate.workflow_record.v1', $result['payload']['workflow']['schema']);
        $this->assertSame(2, $result['payload']['workflow']['result']['completed_steps']);
        $this->assertNull($result['payload']['workflow']['started_at']);
        $this->assertNull($result['payload']['workflow']['completed_at']);
        $firstFeature = (string) $result['payload']['workflow']['shared_context']['steps']['create_comments']['feature'];
        $secondFeature = (string) $result['payload']['workflow']['shared_context']['steps']['create_audit']['feature'];
        $this->assertSame('Create ' . $firstFeature . ' audit', $result['payload']['workflow']['steps'][1]['input']['intent']);
        $this->assertSame('completed', $result['payload']['workflow']['steps'][0]['status']);
        $this->assertSame('completed', $result['payload']['workflow']['steps'][1]['status']);
        $this->assertNotEmpty($result['payload']['workflow']['steps'][0]['record_id']);
        $this->assertNotSame('', $firstFeature);
        $this->assertNotSame('', $secondFeature);
        $this->assertFileExists($this->project->root . '/app/features/' . $firstFeature . '/feature.yaml');
        $this->assertFileExists($this->project->root . '/app/features/' . $secondFeature . '/feature.yaml');
        $this->assertSame('completed', $result['payload']['plan_record']['status']);
    }

    public function test_generate_workflow_fails_fast_and_reports_rollback_guidance_for_completed_steps(): void
    {
        $this->writeGenerateWorkflow([
            'shared_context' => [
                'resource' => 'comments',
            ],
            'steps' => [
                [
                    'id' => 'create_comments',
                    'intent' => 'Create {{shared.resource}}',
                    'mode' => 'new',
                ],
                [
                    'id' => 'modify_missing_feature',
                    'intent' => 'Refine missing feature',
                    'mode' => 'modify',
                    'target' => 'missing_feature',
                    'dependencies' => ['create_comments'],
                ],
            ],
        ]);

        $app = new Application();
        $result = $this->runCommand($app, [
            'foundry',
            'generate',
            '--workflow=generate-workflow.json',
            '--multi-step',
            '--json',
        ]);

        $this->assertSame(1, $result['status']);
        $this->assertFalse($result['payload']['ok']);
        $this->assertSame('failed', $result['payload']['workflow']['status']);
        $this->assertSame('modify_missing_feature', $result['payload']['workflow']['result']['failed_step']);
        $this->assertSame('completed', $result['payload']['workflow']['steps'][0]['status']);
        $this->assertSame('failed', $result['payload']['workflow']['steps'][1]['status']);
        $this->assertNotEmpty($result['payload']['workflow']['rollback_guidance']);
        $firstFeature = (string) $result['payload']['workflow']['steps'][0]['output']['plan']['metadata']['feature'];
        $this->assertNotSame('', $firstFeature);
        $this->assertFileExists($this->project->root . '/app/features/' . $firstFeature . '/feature.yaml');
        $this->assertFileDoesNotExist($this->project->root . '/app/features/missing_feature/feature.yaml');
        $this->assertSame('failed', $result['payload']['plan_record']['status']);
    }

    public function test_generate_workflow_plain_output_shows_step_progression_and_rollback_guidance(): void
    {
        $this->writeGenerateWorkflow([
            'shared_context' => [
                'resource' => 'comments',
            ],
            'steps' => [
                [
                    'id' => 'create_comments',
                    'intent' => 'Create {{shared.resource}}',
                    'mode' => 'new',
                ],
                [
                    'id' => 'modify_missing_feature',
                    'intent' => 'Refine missing feature',
                    'mode' => 'modify',
                    'target' => 'missing_feature',
                    'dependencies' => ['create_comments'],
                ],
            ],
        ]);

        $app = new Application();
        $result = $this->runPlainCommand($app, [
            'foundry',
            'generate',
            '--workflow=generate-workflow.json',
            '--multi-step',
        ]);

        $this->assertSame(1, $result['status']);
        $this->assertStringContainsString('Generate workflow failed.', $result['output']);
        $this->assertStringContainsString('Step progression:', $result['output']);
        $this->assertStringContainsString('[completed] create_comments', $result['output']);
        $this->assertStringContainsString('[failed] modify_missing_feature', $result['output']);
        $this->assertStringContainsString('Rollback guidance:', $result['output']);
    }

    public function test_generate_workflow_persists_canonical_parent_and_linked_step_records(): void
    {
        $this->writeGenerateWorkflow([
            'shared_context' => [
                'resource' => 'comments',
            ],
            'steps' => [
                [
                    'id' => 'create_comments',
                    'intent' => 'Create {{shared.resource}}',
                    'mode' => 'new',
                ],
                [
                    'id' => 'create_audit',
                    'intent' => 'Create {{steps.create_comments.feature}} audit',
                    'mode' => 'new',
                    'dependencies' => ['create_comments'],
                ],
            ],
        ]);

        $app = new Application();
        $generate = $this->runCommand($app, [
            'foundry',
            'generate',
            '--workflow=generate-workflow.json',
            '--multi-step',
            '--json',
        ]);
        $this->assertSame(0, $generate['status']);

        $workflowPlanId = (string) $generate['payload']['plan_record']['plan_id'];
        $workflowId = (string) $generate['payload']['workflow']['workflow_id'];
        $firstStepRecordId = (string) $generate['payload']['workflow']['steps'][0]['record_id'];

        $planList = $this->runCommand($app, ['foundry', 'plan:list', '--json']);
        $workflowShow = $this->runCommand($app, ['foundry', 'plan:show', $workflowPlanId, '--json']);
        $stepShow = $this->runCommand($app, ['foundry', 'plan:show', $firstStepRecordId, '--json']);

        $this->assertSame(0, $planList['status']);
        $this->assertSame(0, $workflowShow['status']);
        $this->assertSame(0, $stepShow['status']);
        $this->assertSame('foundry.generate.workflow_record.v1', $workflowShow['payload']['schema']);
        $this->assertSame($workflowId, $workflowShow['payload']['workflow_id']);
        $this->assertSame('repository_file', $workflowShow['payload']['source']['type']);
        $this->assertSame('generate-workflow.json', $workflowShow['payload']['source']['path']);
        $this->assertSame($firstStepRecordId, $workflowShow['payload']['steps'][0]['record_id']);
        $this->assertSame($workflowId, $stepShow['payload']['metadata']['workflow']['workflow_id']);
        $this->assertSame('create_comments', $stepShow['payload']['metadata']['workflow']['step_id']);
        $this->assertSame(0, $stepShow['payload']['metadata']['workflow']['step_index']);
        $this->assertTrue($stepShow['payload']['metadata']['workflow']['is_workflow_step']);
        $this->assertContains('workflow', array_column($planList['payload']['plans'], 'record_kind'));
        $this->assertContains('workflow_step', array_column($planList['payload']['plans'], 'record_kind'));
    }

    public function test_generate_workflow_rejects_invalid_top_level_argument_combinations(): void
    {
        $this->writeGenerateWorkflow([
            'steps' => [[
                'id' => 'create_comments',
                'intent' => 'Create comments',
                'mode' => 'new',
            ]],
        ]);

        $app = new Application();

        $intentConflict = $this->runCommand($app, [
            'foundry',
            'generate',
            'Create',
            'comments',
            '--workflow=generate-workflow.json',
            '--json',
        ]);
        $this->assertSame(1, $intentConflict['status']);
        $this->assertSame('GENERATE_WORKFLOW_INTENT_CONFLICT', $intentConflict['payload']['error']['code']);

        $modeConflict = $this->runCommand($app, [
            'foundry',
            'generate',
            '--workflow=generate-workflow.json',
            '--mode=new',
            '--json',
        ]);
        $this->assertSame(1, $modeConflict['status']);
        $this->assertSame('GENERATE_WORKFLOW_MODE_CONFLICT', $modeConflict['payload']['error']['code']);

        $targetConflict = $this->runCommand($app, [
            'foundry',
            'generate',
            '--workflow=generate-workflow.json',
            '--target=comments',
            '--json',
        ]);
        $this->assertSame(1, $targetConflict['status']);
        $this->assertSame('GENERATE_WORKFLOW_TARGET_CONFLICT', $targetConflict['payload']['error']['code']);

        $gitCommitConflict = $this->runCommand($app, [
            'foundry',
            'generate',
            '--workflow=generate-workflow.json',
            '--git-commit',
            '--json',
        ]);
        $this->assertSame(1, $gitCommitConflict['status']);
        $this->assertSame('GENERATE_WORKFLOW_GIT_COMMIT_UNSUPPORTED', $gitCommitConflict['payload']['error']['code']);

        $explainConflict = $this->runCommand($app, [
            'foundry',
            'generate',
            '--workflow=generate-workflow.json',
            '--explain',
            '--json',
        ]);
        $this->assertSame(1, $explainConflict['status']);
        $this->assertSame('GENERATE_WORKFLOW_EXPLAIN_UNSUPPORTED', $explainConflict['payload']['error']['code']);

        $multiStepMissingWorkflow = $this->runCommand($app, [
            'foundry',
            'generate',
            '--multi-step',
            '--json',
        ]);
        $this->assertSame(1, $multiStepMissingWorkflow['status']);
        $this->assertSame('GENERATE_MULTI_STEP_WORKFLOW_REQUIRED', $multiStepMissingWorkflow['payload']['error']['code']);

        $multiStepMinimum = $this->runCommand($app, [
            'foundry',
            'generate',
            '--workflow=generate-workflow.json',
            '--multi-step',
            '--json',
        ]);
        $this->assertSame(1, $multiStepMinimum['status']);
        $this->assertSame('GENERATE_WORKFLOW_MULTI_STEP_MINIMUM', $multiStepMinimum['payload']['error']['code']);
    }

    public function test_generate_template_executes_single_template_and_persists_template_metadata(): void
    {
        $this->writeGenerateTemplate('single.json', [
            'schema' => 'foundry.generate.template.v1',
            'template_id' => 'feature.recipe',
            'description' => 'Create one feature from params.',
            'parameters' => [
                'name' => ['type' => 'string', 'required' => true],
            ],
            'generate' => [
                'type' => 'single',
                'definition' => [
                    'intent' => 'Create {{parameters.name}}',
                    'mode' => 'new',
                ],
            ],
        ]);

        $app = new Application();
        $generate = $this->runCommand($app, [
            'foundry',
            'generate',
            '--template=feature.recipe',
            '--param',
            'name=comments',
            '--json',
        ]);
        $show = $this->runCommand($app, [
            'foundry',
            'plan:show',
            (string) $generate['payload']['plan_record']['plan_id'],
            '--json',
        ]);

        $this->assertSame(0, $generate['status']);
        $this->assertSame('feature.recipe', $generate['payload']['metadata']['template']['template_id']);
        $this->assertSame('.foundry/templates/single.json', $generate['payload']['metadata']['template']['path']);
        $this->assertSame(['name' => 'comments'], $generate['payload']['metadata']['template']['resolved_parameters']);
        $this->assertSame('Create comments', $generate['payload']['intent']);
        $this->assertFileExists($this->project->root . '/app/features/comments_system/feature.yaml');
        $this->assertSame(0, $show['status']);
        $this->assertSame('feature.recipe', $show['payload']['metadata']['template']['template_id']);
    }

    public function test_generate_template_executes_workflow_template_and_links_template_metadata_into_parent_and_steps(): void
    {
        $this->writeGenerateTemplate('workflow.json', [
            'schema' => 'foundry.generate.template.v1',
            'template_id' => 'workflow.recipe',
            'description' => 'Create a two-step workflow from params.',
            'parameters' => [
                'resource' => ['type' => 'string', 'required' => true],
                'suffix' => ['type' => 'string', 'default' => 'audit'],
            ],
            'generate' => [
                'type' => 'workflow',
                'definition' => [
                    'shared_context' => [
                        'resource' => '{{parameters.resource}}',
                        'suffix' => '{{parameters.suffix}}',
                    ],
                    'steps' => [
                        [
                            'id' => 'create_resource',
                            'intent' => 'Create {{shared.resource}}',
                            'mode' => 'new',
                        ],
                        [
                            'id' => 'create_follow_up',
                            'intent' => 'Create {{steps.create_resource.feature}} {{shared.suffix}}',
                            'mode' => 'new',
                            'dependencies' => ['create_resource'],
                        ],
                    ],
                ],
            ],
        ]);

        $app = new Application();
        $generate = $this->runCommand($app, [
            'foundry',
            'generate',
            '--template=workflow.recipe',
            '--param',
            'resource=comments',
            '--json',
        ]);
        $workflowPlanId = (string) $generate['payload']['plan_record']['plan_id'];
        $firstStepPlanId = (string) $generate['payload']['workflow']['steps'][0]['record_id'];
        $workflowShow = $this->runCommand($app, ['foundry', 'plan:show', $workflowPlanId, '--json']);
        $stepShow = $this->runCommand($app, ['foundry', 'plan:show', $firstStepPlanId, '--json']);

        $this->assertSame(0, $generate['status']);
        $this->assertSame('completed', $generate['payload']['workflow']['status']);
        $this->assertSame('.foundry/templates/workflow.json', $generate['payload']['workflow']['source']['path']);
        $this->assertSame('workflow.recipe', $generate['payload']['metadata']['template']['template_id']);
        $this->assertSame(['resource' => 'comments', 'suffix' => 'audit'], $generate['payload']['metadata']['template']['resolved_parameters']);
        $this->assertSame(0, $workflowShow['status']);
        $this->assertSame('workflow.recipe', $workflowShow['payload']['metadata']['template']['template_id']);
        $this->assertSame(0, $stepShow['status']);
        $this->assertSame('workflow.recipe', $stepShow['payload']['metadata']['template']['template_id']);
        $this->assertSame('create_resource', $stepShow['payload']['metadata']['workflow']['step_id']);
    }

    public function test_generate_template_rejects_invalid_argument_combinations_and_parameter_failures(): void
    {
        $this->writeGenerateTemplate('single.json', [
            'schema' => 'foundry.generate.template.v1',
            'template_id' => 'feature.recipe',
            'description' => 'Create one feature from params.',
            'parameters' => [
                'name' => ['type' => 'string', 'required' => true],
                'enabled' => ['type' => 'boolean', 'required' => true],
            ],
            'generate' => [
                'type' => 'single',
                'definition' => [
                    'intent' => 'Create {{parameters.name}}',
                    'mode' => 'new',
                ],
            ],
        ]);

        $app = new Application();

        $intentConflict = $this->runCommand($app, [
            'foundry',
            'generate',
            'Create',
            'comments',
            '--template=feature.recipe',
            '--json',
        ]);
        $this->assertSame(1, $intentConflict['status']);
        $this->assertSame('GENERATE_TEMPLATE_INTENT_CONFLICT', $intentConflict['payload']['error']['code']);

        $modeConflict = $this->runCommand($app, [
            'foundry',
            'generate',
            '--template=feature.recipe',
            '--mode=new',
            '--json',
        ]);
        $this->assertSame(1, $modeConflict['status']);
        $this->assertSame('GENERATE_TEMPLATE_MODE_CONFLICT', $modeConflict['payload']['error']['code']);

        $missingRequired = $this->runCommand($app, [
            'foundry',
            'generate',
            '--template=feature.recipe',
            '--json',
        ]);
        $this->assertSame(1, $missingRequired['status']);
        $this->assertSame('GENERATE_TEMPLATE_PARAMETER_REQUIRED', $missingRequired['payload']['error']['code']);

        $invalidType = $this->runCommand($app, [
            'foundry',
            'generate',
            '--template=feature.recipe',
            '--param',
            'name=comments',
            '--param',
            'enabled=yes',
            '--json',
        ]);
        $this->assertSame(1, $invalidType['status']);
        $this->assertSame('GENERATE_TEMPLATE_PARAMETER_VALUE_INVALID', $invalidType['payload']['error']['code']);
    }

    public function test_generate_template_reports_plain_text_template_summary_and_plan_list_template_metadata(): void
    {
        $this->writeGenerateTemplate('single.json', [
            'schema' => 'foundry.generate.template.v1',
            'template_id' => 'feature.recipe',
            'description' => 'Create one feature from params.',
            'parameters' => [
                'name' => ['type' => 'string', 'required' => true],
            ],
            'generate' => [
                'type' => 'single',
                'definition' => [
                    'intent' => 'Create {{parameters.name}}',
                    'mode' => 'new',
                ],
            ],
        ]);

        $app = new Application();
        $plain = $this->runPlainCommand($app, [
            'foundry',
            'generate',
            '--template=feature.recipe',
            '--param',
            'name=comments',
        ]);
        $list = $this->runCommand($app, ['foundry', 'plan:list', '--json']);

        $this->assertSame(0, $plain['status']);
        $this->assertStringContainsString('Template: feature.recipe', $plain['output']);
        $this->assertStringContainsString('Template file: .foundry/templates/single.json', $plain['output']);
        $this->assertStringContainsString('Template params: {"name":"comments"}', $plain['output']);
        $this->assertSame(0, $list['status']);
        $this->assertContains('feature.recipe', array_column($list['payload']['plans'], 'template_id'));
    }

    public function test_generate_template_rejects_invalid_param_syntax_duplicates_unknown_template_and_workflow_specific_conflicts(): void
    {
        $this->writeGenerateTemplate('workflow.json', [
            'schema' => 'foundry.generate.template.v1',
            'template_id' => 'workflow.recipe',
            'description' => 'Create a workflow from params.',
            'parameters' => [
                'resource' => ['type' => 'string', 'required' => true],
            ],
            'generate' => [
                'type' => 'workflow',
                'definition' => [
                    'shared_context' => ['resource' => '{{parameters.resource}}'],
                    'steps' => [
                        ['id' => 'create_resource', 'intent' => 'Create {{shared.resource}}', 'mode' => 'new'],
                        ['id' => 'create_follow_up', 'intent' => 'Create {{steps.create_resource.feature}} audit', 'mode' => 'new', 'dependencies' => ['create_resource']],
                    ],
                ],
            ],
        ]);

        $app = new Application();

        $invalidParam = $this->runCommand($app, [
            'foundry',
            'generate',
            '--template=workflow.recipe',
            '--param',
            'broken',
            '--json',
        ]);
        $this->assertSame(1, $invalidParam['status']);
        $this->assertSame('GENERATE_TEMPLATE_PARAM_INVALID', $invalidParam['payload']['error']['code']);

        $duplicateParam = $this->runCommand($app, [
            'foundry',
            'generate',
            '--template=workflow.recipe',
            '--param',
            'resource=comments',
            '--param',
            'resource=posts',
            '--json',
        ]);
        $this->assertSame(1, $duplicateParam['status']);
        $this->assertSame('GENERATE_TEMPLATE_PARAM_DUPLICATE', $duplicateParam['payload']['error']['code']);

        $notFound = $this->runCommand($app, [
            'foundry',
            'generate',
            '--template=missing.recipe',
            '--json',
        ]);
        $this->assertSame(1, $notFound['status']);
        $this->assertSame('GENERATE_TEMPLATE_NOT_FOUND', $notFound['payload']['error']['code']);

        $gitCommitConflict = $this->runCommand($app, [
            'foundry',
            'generate',
            '--template=workflow.recipe',
            '--param',
            'resource=comments',
            '--git-commit',
            '--json',
        ]);
        $this->assertSame(1, $gitCommitConflict['status']);
        $this->assertSame('GENERATE_WORKFLOW_GIT_COMMIT_UNSUPPORTED', $gitCommitConflict['payload']['error']['code']);

        $explainConflict = $this->runCommand($app, [
            'foundry',
            'generate',
            '--template=workflow.recipe',
            '--param',
            'resource=comments',
            '--explain',
            '--json',
        ]);
        $this->assertSame(1, $explainConflict['status']);
        $this->assertSame('GENERATE_WORKFLOW_EXPLAIN_UNSUPPORTED', $explainConflict['payload']['error']['code']);
    }

    public function test_generate_template_supports_split_template_flag_and_inline_param_assignment_and_rejects_additional_conflicts(): void
    {
        $this->writeGenerateTemplate('single.json', [
            'schema' => 'foundry.generate.template.v1',
            'template_id' => 'feature.recipe',
            'description' => 'Create one feature from params.',
            'parameters' => [
                'name' => ['type' => 'string', 'required' => true],
                'enabled' => ['type' => 'boolean', 'required' => true],
            ],
            'generate' => [
                'type' => 'single',
                'definition' => [
                    'intent' => 'Create {{parameters.name}}',
                    'mode' => 'new',
                    'enabled' => '{{parameters.enabled}}',
                ],
            ],
        ]);

        $app = new Application();
        $generate = $this->runCommand($app, [
            'foundry',
            'generate',
            '--template',
            'feature.recipe',
            '--param=name=comments',
            '--param=enabled=true',
            '--json',
        ]);

        $this->assertSame(0, $generate['status']);
        $this->assertSame('feature.recipe', $generate['payload']['metadata']['template']['template_id']);
        $this->assertSame(['enabled' => true, 'name' => 'comments'], $generate['payload']['metadata']['template']['resolved_parameters']);

        $workflowConflict = $this->runCommand($app, [
            'foundry',
            'generate',
            '--template=feature.recipe',
            '--workflow=generate-workflow.json',
            '--json',
        ]);
        $this->assertSame(1, $workflowConflict['status']);
        $this->assertSame('GENERATE_TEMPLATE_WORKFLOW_CONFLICT', $workflowConflict['payload']['error']['code']);

        $targetConflict = $this->runCommand($app, [
            'foundry',
            'generate',
            '--template=feature.recipe',
            '--target=comments_system',
            '--json',
        ]);
        $this->assertSame(1, $targetConflict['status']);
        $this->assertSame('GENERATE_TEMPLATE_TARGET_CONFLICT', $targetConflict['payload']['error']['code']);

        $multiStepConflict = $this->runCommand($app, [
            'foundry',
            'generate',
            '--template=feature.recipe',
            '--multi-step',
            '--json',
        ]);
        $this->assertSame(1, $multiStepConflict['status']);
        $this->assertSame('GENERATE_TEMPLATE_MULTI_STEP_CONFLICT', $multiStepConflict['payload']['error']['code']);
    }

    public function test_generate_template_rejects_unknown_parameters_and_invalid_single_template_shapes(): void
    {
        $this->writeGenerateTemplate('unknown.json', [
            'schema' => 'foundry.generate.template.v1',
            'template_id' => 'unknown.recipe',
            'description' => 'Template for unknown param validation.',
            'parameters' => [
                'name' => ['type' => 'string', 'required' => true],
            ],
            'generate' => [
                'type' => 'single',
                'definition' => [
                    'intent' => 'Create {{parameters.name}}',
                    'mode' => 'new',
                ],
            ],
        ]);

        $this->writeGenerateTemplate('invalid-mode.json', [
            'schema' => 'foundry.generate.template.v1',
            'template_id' => 'invalid.mode.recipe',
            'description' => 'Invalid mode template.',
            'parameters' => [
                'name' => ['type' => 'string', 'required' => true],
            ],
            'generate' => [
                'type' => 'single',
                'definition' => [
                    'intent' => 'Create {{parameters.name}}',
                    'mode' => 'ship',
                ],
            ],
        ]);

        $this->writeGenerateTemplate('missing-target.json', [
            'schema' => 'foundry.generate.template.v1',
            'template_id' => 'missing.target.recipe',
            'description' => 'Missing target template.',
            'parameters' => [
                'name' => ['type' => 'string', 'required' => true],
            ],
            'generate' => [
                'type' => 'single',
                'definition' => [
                    'intent' => 'Repair {{parameters.name}}',
                    'mode' => 'repair',
                ],
            ],
        ]);

        $app = new Application();

        $unknownParam = $this->runCommand($app, [
            'foundry',
            'generate',
            '--template=unknown.recipe',
            '--param',
            'name=comments',
            '--param',
            'extra=1',
            '--json',
        ]);
        $this->assertSame(1, $unknownParam['status']);
        $this->assertSame('GENERATE_TEMPLATE_PARAMETER_UNKNOWN', $unknownParam['payload']['error']['code']);

        $invalidMode = $this->runCommand($app, [
            'foundry',
            'generate',
            '--template=invalid.mode.recipe',
            '--param',
            'name=comments',
            '--json',
        ]);
        $this->assertSame(1, $invalidMode['status']);
        $this->assertSame('GENERATE_TEMPLATE_MODE_INVALID', $invalidMode['payload']['error']['code']);

        $missingTarget = $this->runCommand($app, [
            'foundry',
            'generate',
            '--template=missing.target.recipe',
            '--param',
            'name=comments',
            '--json',
        ]);
        $this->assertSame(1, $missingTarget['status']);
        $this->assertSame('GENERATE_TEMPLATE_TARGET_REQUIRED', $missingTarget['payload']['error']['code']);
    }

    public function test_generate_records_architectural_snapshots_diff_and_post_explain(): void
    {
        $app = new Application();

        $generate = $this->runCommand($app, [
            'foundry',
            'generate',
            'Create',
            'comments',
            '--mode=new',
            '--explain',
            '--json',
        ]);

        $this->assertSame(0, $generate['status']);
        $this->assertFileExists($this->project->root . '/.foundry/snapshots/pre-generate.json');
        $this->assertFileExists($this->project->root . '/.foundry/snapshots/post-generate.json');
        $this->assertFileExists($this->project->root . '/.foundry/diffs/last.json');
        $this->assertSame('.foundry/snapshots/pre-generate.json', $generate['payload']['snapshots']['pre']);
        $this->assertSame('.foundry/snapshots/post-generate.json', $generate['payload']['snapshots']['post']);
        $this->assertSame('.foundry/diffs/last.json', $generate['payload']['snapshots']['diff']);
        $this->assertArrayHasKey('plan_confidence', $generate['payload']);
        $this->assertArrayHasKey('outcome_confidence', $generate['payload']);
        $this->assertIsArray($generate['payload']['architecture_diff']);
        $this->assertArrayHasKey('confidence', $generate['payload']['architecture_diff']);
        $this->assertGreaterThan(0, $generate['payload']['architecture_diff']['summary']['added']);
        $this->assertSame(
            'feature:' . $generate['payload']['plan']['metadata']['feature'],
            $generate['payload']['post_explain']['subject']['id'],
        );
        $this->assertArrayHasKey('confidence', $generate['payload']['post_explain']);

        $diff = $this->runCommand($app, ['foundry', 'explain', '--diff', '--json']);
        $this->assertSame(0, $diff['status']);
        $this->assertSame($generate['payload']['architecture_diff'], $diff['payload']);
    }

    public function test_explain_diff_fails_cleanly_when_snapshots_are_incompatible(): void
    {
        mkdir($this->project->root . '/.foundry/snapshots', 0777, true);
        mkdir($this->project->root . '/.foundry/diffs', 0777, true);

        file_put_contents($this->project->root . '/.foundry/snapshots/pre-generate.json', json_encode([
            'schema_version' => 1,
            'label' => 'pre-generate',
            'metadata' => ['explain_schema_version' => 2],
            'categories' => [],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($this->project->root . '/.foundry/snapshots/post-generate.json', json_encode([
            'schema_version' => 1,
            'label' => 'post-generate',
            'metadata' => ['explain_schema_version' => 99],
            'categories' => [],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($this->project->root . '/.foundry/diffs/last.json', json_encode([
            'schema_version' => 1,
            'summary' => ['added' => 0, 'removed' => 0, 'modified' => 0],
            'added' => [],
            'removed' => [],
            'modified' => [],
        ], JSON_THROW_ON_ERROR));

        $app = new Application();
        $diff = $this->runCommand($app, ['foundry', 'explain', '--diff', '--json']);

        $this->assertSame(1, $diff['status']);
        $this->assertSame('EXPLAIN_DIFF_SNAPSHOT_INCOMPATIBLE', $diff['payload']['error']['code']);
    }

    public function test_generate_blocks_dirty_git_repo_without_allow_dirty(): void
    {
        $this->initGitRepository();
        file_put_contents($this->project->root . '/composer.json', str_replace('"project"', '"project-test"', (string) file_get_contents($this->project->root . '/composer.json')));

        $app = new Application();
        $generate = $this->runCommand($app, [
            'foundry',
            'generate',
            'Create',
            'comments',
            '--mode=new',
            '--json',
        ]);

        $this->assertSame(1, $generate['status']);
        $this->assertSame('GENERATE_GIT_DIRTY_TREE', $generate['payload']['error']['code']);
        $this->assertContains('composer.json', $generate['payload']['error']['details']['changed_files']);
        $this->assertFileDoesNotExist($this->project->root . '/app/features/comments/feature.yaml');
    }

    public function test_generate_can_allow_dirty_repo_and_persist_generate_history(): void
    {
        $this->initGitRepository();
        file_put_contents($this->project->root . '/composer.json', str_replace('"project"', '"project-test"', (string) file_get_contents($this->project->root . '/composer.json')));

        $app = new Application();
        $generate = $this->runCommand($app, [
            'foundry',
            'generate',
            'Create',
            'comments',
            '--mode=new',
            '--dry-run',
            '--allow-dirty',
            '--json',
        ]);

        $history = $this->runCommand($app, ['foundry', 'history', '--kind=generate', '--json']);

        $this->assertSame(0, $generate['status']);
        $this->assertTrue($generate['payload']['git']['available']);
        $this->assertContains(
            'Git working tree was dirty before generation; auto-commit may be skipped for safety.',
            $generate['payload']['git']['warnings'],
        );
        $this->assertSame('generate', $generate['payload']['record']['kind']);
        $this->assertMatchesRegularExpression('/^[a-f0-9-]{36}$/', (string) $generate['payload']['plan_record']['plan_id']);
        $this->assertStringStartsWith('.foundry/plans/', (string) $generate['payload']['plan_record']['storage_path']);
        $this->assertSame(0, $history['status']);
        $this->assertContains(
            $generate['payload']['record']['id'],
            array_values(array_map(
                static fn(array $entry): string => (string) ($entry['id'] ?? ''),
                $history['payload']['entries'],
            )),
        );
    }

    public function test_generate_policy_deny_blocks_execution_before_file_writes(): void
    {
        $this->writeGeneratePolicy([
            'version' => 1,
            'rules' => [[
                'id' => 'protect-features',
                'type' => 'deny',
                'description' => 'Prevent feature file creation.',
                'match' => [
                    'actions' => ['create_file'],
                    'paths' => ['app/features/**'],
                ],
            ]],
        ]);

        $result = $this->runCommand(new Application(), [
            'foundry',
            'generate',
            'Create',
            'comments',
            '--mode=new',
            '--json',
        ]);

        $this->assertSame(1, $result['status']);
        $this->assertSame('GENERATE_POLICY_VIOLATION', $result['payload']['error']['code']);
        $this->assertSame('deny', $result['payload']['error']['details']['policy']['status']);
        $this->assertSame(['protect-features'], $result['payload']['error']['details']['policy']['matched_rule_ids']);
        $this->assertFileDoesNotExist($this->project->root . '/app/features/comments_system/feature.yaml');
    }

    public function test_generate_policy_warnings_surface_without_blocking_execution(): void
    {
        $this->writeGeneratePolicy([
            'version' => 1,
            'rules' => [[
                'id' => 'warn-feature-creation',
                'type' => 'warn',
                'description' => 'Warn on feature file creation.',
                'match' => [
                    'actions' => ['create_file'],
                    'paths' => ['app/features/**'],
                ],
            ]],
        ]);

        $result = $this->runCommand(new Application(), [
            'foundry',
            'generate',
            'Create',
            'comments',
            '--mode=new',
            '--dry-run',
            '--json',
        ]);

        $this->assertSame(0, $result['status']);
        $this->assertSame('warn', $result['payload']['policy']['status']);
        $this->assertFalse($result['payload']['policy']['blocking']);
        $this->assertSame('warn-feature-creation', $result['payload']['policy']['warnings'][0]['rule_id']);
    }

    public function test_generate_policy_check_evaluates_without_execution(): void
    {
        $this->writeGeneratePolicy([
            'version' => 1,
            'rules' => [[
                'id' => 'protect-features',
                'type' => 'deny',
                'description' => 'Prevent feature file creation.',
                'match' => [
                    'actions' => ['create_file'],
                    'paths' => ['app/features/**'],
                ],
            ]],
        ]);

        $result = $this->runCommand(new Application(), [
            'foundry',
            'generate',
            'Create',
            'comments',
            '--mode=new',
            '--policy-check',
            '--json',
        ]);

        $this->assertSame(0, $result['status']);
        $this->assertTrue($result['payload']['metadata']['policy_check']);
        $this->assertSame('deny', $result['payload']['policy']['status']);
        $this->assertTrue($result['payload']['policy']['blocking']);
        $this->assertTrue($result['payload']['verification_results']['skipped']);
        $this->assertFileDoesNotExist($this->project->root . '/app/features/comments_system/feature.yaml');
    }

    public function test_generate_can_explicitly_override_policy_violations_and_persist_policy_result(): void
    {
        $this->writeGeneratePolicy([
            'version' => 1,
            'rules' => [[
                'id' => 'protect-features',
                'type' => 'deny',
                'description' => 'Prevent feature file creation.',
                'match' => [
                    'actions' => ['create_file'],
                    'paths' => ['app/features/**'],
                ],
            ]],
        ]);

        $generate = $this->runCommand(new Application(), [
            'foundry',
            'generate',
            'Create',
            'comments',
            '--mode=new',
            '--allow-policy-violations',
            '--json',
        ]);
        $show = $this->runCommand(new Application(), [
            'foundry',
            'plan:show',
            $generate['payload']['plan_record']['plan_id'],
            '--json',
        ]);

        $this->assertSame(0, $generate['status']);
        $this->assertSame('deny', $generate['payload']['policy']['status']);
        $this->assertTrue($generate['payload']['policy']['override_requested']);
        $this->assertTrue($generate['payload']['policy']['override_used']);
        $this->assertSame('flag', $generate['payload']['policy']['override_source']);
        $this->assertFileExists($this->project->root . '/app/features/comments_system/feature.yaml');
        $this->assertSame(0, $show['status']);
        $this->assertSame('deny', $show['payload']['policy']['status']);
        $this->assertTrue($show['payload']['policy']['override_used']);
    }

    public function test_generate_interactive_can_explicitly_override_policy_violations(): void
    {
        $this->writeGeneratePolicy([
            'version' => 1,
            'rules' => [[
                'id' => 'protect-features',
                'type' => 'deny',
                'description' => 'Prevent feature file creation.',
                'match' => [
                    'actions' => ['create_file'],
                    'paths' => ['app/features/**'],
                ],
            ]],
        ]);

        $app = $this->interactiveApplication(
            static fn(InteractiveGenerateReviewRequest $request): InteractiveGenerateReviewResult => new InteractiveGenerateReviewResult(
                approved: true,
                plan: $request->plan,
                userDecisions: [
                    ['type' => 'policy_override', 'approved' => true],
                    ['type' => 'approve'],
                ],
                preview: ['summary' => [], 'actions' => [], 'diffs' => []],
                risk: ['level' => 'LOW', 'reasons' => ['Plan is additive only.'], 'risky_action_indexes' => [], 'risky_paths' => []],
                allowPolicyViolations: true,
            ),
        );

        $result = $this->runCommand($app, [
            'foundry',
            'generate',
            'Create',
            'comments',
            '--mode=new',
            '--interactive',
            '--json',
        ]);

        $this->assertSame(0, $result['status']);
        $this->assertTrue($result['payload']['interactive']['allow_policy_violations']);
        $this->assertSame('deny', $result['payload']['policy']['status']);
        $this->assertTrue($result['payload']['policy']['override_used']);
        $this->assertSame('interactive_confirmation', $result['payload']['policy']['override_source']);
        $this->assertFileExists($this->project->root . '/app/features/comments_system/feature.yaml');
    }

    public function test_generate_human_output_includes_policy_summary(): void
    {
        $this->writeGeneratePolicy([
            'version' => 1,
            'rules' => [[
                'id' => 'warn-feature-creation',
                'type' => 'warn',
                'description' => 'Warn on feature file creation.',
                'match' => [
                    'actions' => ['create_file'],
                    'paths' => ['app/features/**'],
                ],
            ]],
        ]);

        $result = $this->runPlainCommand(new Application(), [
            'foundry',
            'generate',
            'Create',
            'comments',
            '--mode=new',
            '--dry-run',
        ]);

        $this->assertSame(0, $result['status']);
        $this->assertStringContainsString('Policy: WARN', $result['output']);
        $this->assertStringContainsString('Policy rules: warn-feature-creation', $result['output']);
        $this->assertStringContainsString('Policy warning: Warn on feature file creation.', $result['output']);
    }

    public function test_generate_human_output_reports_missing_policy_file(): void
    {
        $result = $this->runPlainCommand(new Application(), [
            'foundry',
            'generate',
            'Create',
            'comments',
            '--mode=new',
            '--dry-run',
        ]);

        $this->assertSame(0, $result['status']);
        $this->assertStringContainsString('Policy: PASS', $result['output']);
        $this->assertStringContainsString('Policy file: not loaded', $result['output']);
    }

    public function test_generate_human_output_reports_applied_policy_override(): void
    {
        $this->writeGeneratePolicy([
            'version' => 1,
            'rules' => [[
                'id' => 'protect-features',
                'type' => 'deny',
                'description' => 'Prevent feature file creation.',
                'match' => [
                    'actions' => ['create_file'],
                    'paths' => ['app/features/**'],
                ],
            ]],
        ]);

        $result = $this->runPlainCommand(new Application(), [
            'foundry',
            'generate',
            'Create',
            'comments',
            '--mode=new',
            '--allow-policy-violations',
        ]);

        $this->assertSame(0, $result['status']);
        $this->assertStringContainsString('Policy: DENY', $result['output']);
        $this->assertStringContainsString('Policy override: applied', $result['output']);
        $this->assertStringContainsString('Policy file: .foundry/policies/generate.json', $result['output']);
    }

    public function test_generate_policy_check_human_output_reports_required_override(): void
    {
        $this->writeGeneratePolicy([
            'version' => 1,
            'rules' => [[
                'id' => 'protect-features',
                'type' => 'deny',
                'description' => 'Prevent feature file creation.',
                'match' => [
                    'actions' => ['create_file'],
                    'paths' => ['app/features/**'],
                ],
            ]],
        ]);

        $result = $this->runPlainCommand(new Application(), [
            'foundry',
            'generate',
            'Create',
            'comments',
            '--mode=new',
            '--policy-check',
        ]);

        $this->assertSame(0, $result['status']);
        $this->assertStringContainsString('Generate policy check completed.', $result['output']);
        $this->assertStringContainsString('Policy: DENY', $result['output']);
        $this->assertStringContainsString('Policy override: required for execution', $result['output']);
    }

    public function test_generate_rejects_git_commit_with_policy_check(): void
    {
        $result = $this->runCommand(new Application(), [
            'foundry',
            'generate',
            'Create',
            'comments',
            '--mode=new',
            '--policy-check',
            '--git-commit',
            '--json',
        ]);

        $this->assertSame(1, $result['status']);
        $this->assertSame('GENERATE_GIT_COMMIT_DRY_RUN_INVALID', $result['payload']['error']['code']);
    }

    public function test_plan_list_and_show_return_persisted_generate_plan_records_deterministically(): void
    {
        $app = new Application();

        $first = $this->runCommand($app, [
            'foundry',
            'generate',
            'Create',
            'comments',
            '--mode=new',
            '--dry-run',
            '--json',
        ]);
        $second = $this->runCommand($app, [
            'foundry',
            'generate',
            'Create',
            'bookmarks',
            '--mode=new',
            '--dry-run',
            '--json',
        ]);

        $listA = $this->runCommand($app, ['foundry', 'plan:list', '--json']);
        $listB = $this->runCommand($app, ['foundry', 'plan:list', '--json']);
        $show = $this->runCommand($app, ['foundry', 'plan:show', $first['payload']['plan_record']['plan_id'], '--json']);

        $this->assertSame(0, $listA['status']);
        $this->assertSame($listA['payload'], $listB['payload']);
        $this->assertContains(
            $first['payload']['plan_record']['plan_id'],
            array_values(array_map(
                static fn(array $row): string => (string) ($row['plan_id'] ?? ''),
                $listA['payload']['plans'],
            )),
        );
        $this->assertContains(
            $second['payload']['plan_record']['plan_id'],
            array_values(array_map(
                static fn(array $row): string => (string) ($row['plan_id'] ?? ''),
                $listA['payload']['plans'],
            )),
        );
        $this->assertSame(0, $show['status']);
        $this->assertSame($first['payload']['plan_record']['plan_id'], $show['payload']['plan_id']);
        $this->assertSame('success', $show['payload']['status']);
        $this->assertSame('Create comments', $show['payload']['intent']);
        $this->assertSame('new', $show['payload']['mode']);
        $this->assertSame([], $show['payload']['actions_executed']);
        $this->assertSame($first['payload']['plan']['actions'], $show['payload']['plan_original']['actions']);
    }

    public function test_plan_replay_reuses_stored_plan_artifact_by_id(): void
    {
        $app = new Application();
        $generate = $this->runCommand($app, [
            'foundry',
            'generate',
            'Create',
            'comments',
            '--mode=new',
            '--dry-run',
            '--json',
        ]);

        $planId = (string) $generate['payload']['plan_record']['plan_id'];
        $feature = (string) $generate['payload']['plan']['metadata']['feature'];
        $recordPath = $this->project->root . '/' . $generate['payload']['plan_record']['storage_path'];
        $record = json_decode((string) file_get_contents($recordPath), true, 512, JSON_THROW_ON_ERROR);
        $record['plan_original']['metadata']['execution']['feature_definition']['description'] = 'Replay-owned description.';
        file_put_contents($recordPath, json_encode($record, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);

        $replay = $this->runCommand($app, ['foundry', 'plan:replay', $planId, '--json']);

        $this->assertSame(0, $replay['status']);
        $this->assertSame('replayed', $replay['payload']['status']);
        $this->assertFalse($replay['payload']['drift_detected']);
        $this->assertSame('original', $replay['payload']['source_record']['selected_plan']);
        $this->assertFileExists($this->project->root . '/app/features/' . $feature . '/feature.yaml');
        $this->assertStringContainsString(
            'Replay-owned description.',
            (string) file_get_contents($this->project->root . '/app/features/' . $feature . '/feature.yaml'),
        );
    }

    public function test_plan_replay_strict_mode_fails_when_material_drift_is_detected(): void
    {
        $app = new Application();
        $generate = $this->runCommand($app, [
            'foundry',
            'generate',
            'Create',
            'comments',
            '--mode=new',
            '--dry-run',
            '--json',
        ]);

        $planId = (string) $generate['payload']['plan_record']['plan_id'];
        $recordPath = $this->project->root . '/' . $generate['payload']['plan_record']['storage_path'];
        $record = json_decode((string) file_get_contents($recordPath), true, 512, JSON_THROW_ON_ERROR);
        $record['metadata']['source_hash'] = 'strict-drift-source-hash';
        file_put_contents($recordPath, json_encode($record, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);

        $replay = $this->runCommand($app, ['foundry', 'plan:replay', $planId, '--strict', '--json']);

        $this->assertSame(1, $replay['status']);
        $this->assertSame('PLAN_REPLAY_STRICT_DRIFT', $replay['payload']['error']['code']);
        $this->assertTrue($replay['payload']['error']['details']['drift_summary']['detected']);
    }

    public function test_plan_replay_adaptive_mode_surfaces_drift_and_proceeds_when_safe(): void
    {
        $app = new Application();
        $generate = $this->runCommand($app, [
            'foundry',
            'generate',
            'Create',
            'comments',
            '--mode=new',
            '--dry-run',
            '--json',
        ]);

        $planId = (string) $generate['payload']['plan_record']['plan_id'];
        $feature = (string) $generate['payload']['plan']['metadata']['feature'];
        $recordPath = $this->project->root . '/' . $generate['payload']['plan_record']['storage_path'];
        $record = json_decode((string) file_get_contents($recordPath), true, 512, JSON_THROW_ON_ERROR);
        $record['metadata']['source_hash'] = 'adaptive-drift-source-hash';
        file_put_contents($recordPath, json_encode($record, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);

        $replay = $this->runCommand($app, ['foundry', 'plan:replay', $planId, '--json']);

        $this->assertSame(0, $replay['status']);
        $this->assertSame('adaptive', $replay['payload']['replay_mode']);
        $this->assertTrue($replay['payload']['drift_detected']);
        $this->assertNotEmpty($replay['payload']['drift_summary']['messages']);
        $this->assertSame('replayed', $replay['payload']['status']);
        $this->assertFileExists($this->project->root . '/app/features/' . $feature . '/feature.yaml');
    }

    public function test_plan_replay_dry_run_validates_without_writing_files(): void
    {
        $app = new Application();
        $generate = $this->runCommand($app, [
            'foundry',
            'generate',
            'Create',
            'comments',
            '--mode=new',
            '--dry-run',
            '--json',
        ]);

        $planId = (string) $generate['payload']['plan_record']['plan_id'];
        $feature = (string) $generate['payload']['plan']['metadata']['feature'];

        $replay = $this->runCommand($app, ['foundry', 'plan:replay', $planId, '--dry-run', '--json']);

        $this->assertSame(0, $replay['status']);
        $this->assertSame('dry_run', $replay['payload']['status']);
        $this->assertTrue($replay['payload']['replayable']);
        $this->assertTrue($replay['payload']['verification']['skipped']);
        $this->assertNotEmpty($replay['payload']['actions_executed']);
        $this->assertFileDoesNotExist($this->project->root . '/app/features/' . $feature . '/feature.yaml');
    }

    public function test_plan_undo_dry_run_previews_without_changing_files(): void
    {
        $app = new Application();
        $generate = $this->runCommand($app, [
            'foundry',
            'generate',
            'Create',
            'comments',
            '--mode=new',
            '--json',
        ]);

        $planId = (string) $generate['payload']['plan_record']['plan_id'];
        $feature = (string) $generate['payload']['plan']['metadata']['feature'];
        $featurePath = $this->project->root . '/app/features/' . $feature . '/feature.yaml';

        $undo = $this->runCommand($app, ['foundry', 'plan:undo', $planId, '--dry-run', '--json']);

        $this->assertSame(0, $undo['status']);
        $this->assertSame('dry_run', $undo['payload']['status']);
        $this->assertSame('snapshot', $undo['payload']['rollback_mode']);
        $this->assertTrue($undo['payload']['fully_reversible']);
        $this->assertTrue($undo['payload']['reversible']);
        $this->assertSame('high', $undo['payload']['confidence_level']);
        $this->assertNotEmpty($undo['payload']['reversible_actions']);
        $this->assertSame([], $undo['payload']['reversed_actions']);
        $this->assertSame([], $undo['payload']['integrity_warnings']);
        $this->assertFileExists($featurePath);
    }

    public function test_plan_undo_requires_explicit_confirmation_for_destructive_deletes(): void
    {
        $app = new Application();
        $generate = $this->runCommand($app, [
            'foundry',
            'generate',
            'Create',
            'comments',
            '--mode=new',
            '--json',
        ]);

        $planId = (string) $generate['payload']['plan_record']['plan_id'];
        $feature = (string) $generate['payload']['plan']['metadata']['feature'];
        $featurePath = $this->project->root . '/app/features/' . $feature . '/feature.yaml';

        $undo = $this->runCommand($app, ['foundry', 'plan:undo', $planId, '--json']);

        $this->assertSame(1, $undo['status']);
        $this->assertSame('confirmation_required', $undo['payload']['status']);
        $this->assertTrue($undo['payload']['requires_confirmation']);
        $this->assertSame([], $undo['payload']['reversed_actions']);
        $this->assertSame([], $undo['payload']['files_recovered']);
        $this->assertFileExists($featurePath);
    }

    public function test_plan_undo_reverses_generated_create_file_actions_when_confirmed(): void
    {
        $app = new Application();
        $generate = $this->runCommand($app, [
            'foundry',
            'generate',
            'Create',
            'comments',
            '--mode=new',
            '--json',
        ]);

        $planId = (string) $generate['payload']['plan_record']['plan_id'];
        $feature = (string) $generate['payload']['plan']['metadata']['feature'];
        $featurePath = $this->project->root . '/app/features/' . $feature . '/feature.yaml';
        $recordPath = $this->project->root . '/' . $generate['payload']['plan_record']['storage_path'];
        $record = json_decode((string) file_get_contents($recordPath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertFalse(
            in_array(
                true,
                array_values(array_map(
                    static fn(array $snapshot): bool => ($snapshot['exists'] ?? true) === true,
                    $record['undo']['file_snapshots_before'],
                )),
                true,
            ),
        );
        $this->assertContains(
            'app/features/' . $feature . '/feature.yaml',
            array_values(array_map(
                static fn(array $patch): string => (string) ($patch['path'] ?? ''),
                $record['undo']['patches'],
            )),
        );
        $this->assertFileExists($featurePath);

        $undo = $this->runCommand($app, ['foundry', 'plan:undo', $planId, '--yes', '--json']);

        $this->assertSame(0, $undo['status']);
        $this->assertSame('undone', $undo['payload']['status']);
        $this->assertSame('snapshot', $undo['payload']['rollback_mode']);
        $this->assertTrue($undo['payload']['fully_reversible']);
        $this->assertNotEmpty($undo['payload']['reversed_actions']);
        $this->assertContains('app/features/' . $feature . '/feature.yaml', $undo['payload']['files_recovered']);
        $this->assertFileDoesNotExist($featurePath);
    }

    public function test_generate_interactive_reject_persists_aborted_plan_record(): void
    {
        $app = $this->interactiveApplication(
            static fn(InteractiveGenerateReviewRequest $request): InteractiveGenerateReviewResult => new InteractiveGenerateReviewResult(
                approved: false,
                plan: $request->plan,
                userDecisions: [['type' => 'reject']],
                preview: ['summary' => [], 'actions' => [], 'diffs' => []],
                risk: ['level' => 'LOW', 'reasons' => ['Plan is additive only.'], 'risky_action_indexes' => [], 'risky_paths' => []],
            ),
        );

        $result = $this->runCommand($app, [
            'foundry',
            'generate',
            'Create',
            'comments',
            '--mode=new',
            '--interactive',
            '--json',
        ]);
        $show = $this->runCommand($app, ['foundry', 'plan:show', $result['payload']['plan_record']['plan_id'], '--json']);

        $this->assertSame(0, $result['status']);
        $this->assertSame(0, $show['status']);
        $this->assertSame('aborted', $show['payload']['status']);
        $this->assertTrue($show['payload']['interactive']['enabled']);
        $this->assertTrue($show['payload']['interactive']['rejected']);
        $this->assertSame([['type' => 'reject']], $show['payload']['user_decisions']);
        $this->assertNotNull($show['payload']['plan_original']);
        $this->assertNull($show['payload']['plan_final']);
    }

    public function test_generate_failure_persists_failed_plan_record(): void
    {
        $app = new Application();

        $failed = $this->runCommand($app, [
            'foundry',
            'generate',
            'Create',
            'blog',
            'post',
            'notes',
            '--mode=new',
            '--packs=foundry/blog',
            '--json',
        ]);
        $plans = $this->runCommand($app, ['foundry', 'plan:list', '--json']);
        $failedPlanId = null;

        foreach ($plans['payload']['plans'] as $plan) {
            if (($plan['status'] ?? null) !== 'failed' || ($plan['intent'] ?? null) !== 'Create blog post notes') {
                continue;
            }

            $failedPlanId = (string) $plan['plan_id'];
            break;
        }

        $this->assertSame(1, $failed['status']);
        $this->assertIsString($failedPlanId);

        $show = $this->runCommand($app, ['foundry', 'plan:show', $failedPlanId, '--json']);

        $this->assertSame(0, $show['status']);
        $this->assertSame('failed', $show['payload']['status']);
        $this->assertSame('GENERATE_PACK_INSTALL_REQUIRED', $show['payload']['error']['code']);
        $this->assertNull($show['payload']['generation_context_packet']);
        $this->assertNull($show['payload']['plan_original']);
    }

    public function test_generate_git_preflight_ignores_internal_foundry_artifacts_between_runs(): void
    {
        $this->initGitRepository();
        $app = new Application();

        $first = $this->runCommand($app, [
            'foundry',
            'generate',
            'Create',
            'comments',
            '--mode=new',
            '--dry-run',
            '--json',
        ]);
        $second = $this->runCommand($app, [
            'foundry',
            'generate',
            'Create',
            'comments',
            '--mode=new',
            '--dry-run',
            '--json',
        ]);

        $this->assertSame(0, $first['status']);
        $this->assertSame(0, $second['status']);
        $this->assertTrue($second['payload']['git']['available']);
        $this->assertSame([], $second['payload']['git']['warnings']);
    }

    public function test_generate_can_create_scoped_git_commit_after_successful_verification(): void
    {
        $this->initGitRepository();
        $app = new Application();

        $generate = $this->runCommand($app, [
            'foundry',
            'generate',
            'Create',
            'comments',
            '--mode=new',
            '--git-commit',
            '--json',
        ]);

        $this->assertSame(0, $generate['status']);
        $this->assertTrue($generate['payload']['git']['commit']['created']);
        $this->assertNotEmpty($generate['payload']['git']['commit']['commit']);
        $this->assertContains('app/features/comments_system/feature.yaml', $generate['payload']['git']['commit']['files']);
        $this->assertSame('foundry generate (new): Create comments', $this->git(['log', '-1', '--format=%s']));
    }

    public function test_generate_interactive_dry_run_includes_review_payload(): void
    {
        $app = $this->interactiveApplication(
            static fn(InteractiveGenerateReviewRequest $request): InteractiveGenerateReviewResult => new InteractiveGenerateReviewResult(
                approved: true,
                plan: $request->plan,
                userDecisions: [['type' => 'approve']],
                preview: ['summary' => [], 'actions' => [], 'diffs' => []],
                risk: ['level' => 'LOW', 'reasons' => ['Plan is additive only.'], 'risky_action_indexes' => [], 'risky_paths' => []],
            ),
        );

        $result = $this->runCommand($app, [
            'foundry',
            'generate',
            'Create',
            'comments',
            '--mode=new',
            '--interactive',
            '--dry-run',
            '--json',
        ]);

        $this->assertSame(0, $result['status']);
        $this->assertTrue($result['payload']['interactive']['enabled']);
        $this->assertTrue($result['payload']['interactive']['approved']);
        $this->assertArrayHasKey('original_plan', $result['payload']['interactive']);
        $this->assertNull($result['payload']['interactive']['modified_plan']);
        $this->assertSame('interactive', $result['payload']['safety_routing']['recommended_mode']);
        $this->assertTrue($result['payload']['safety_routing']['forced_by_user']);
        $this->assertSame(['explicit_interactive'], $result['payload']['safety_routing']['reason_codes']);
    }

    public function test_generate_new_dry_run_exposes_safety_routing_payload(): void
    {
        $result = $this->runCommand(new Application(), [
            'foundry',
            'generate',
            'Create',
            'comments',
            '--mode=new',
            '--dry-run',
            '--json',
        ]);

        $this->assertSame(0, $result['status']);
        $this->assertIsArray($result['payload']['safety_routing']);
        $this->assertContains($result['payload']['safety_routing']['recommended_mode'], ['interactive', 'non_interactive']);
        $this->assertSame('generate-with-safety-routing', $result['payload']['safety_routing']['skill']['name']);
        $this->assertArrayHasKey('signals', $result['payload']['safety_routing']);
        $this->assertArrayHasKey('reason_codes', $result['payload']['safety_routing']);
    }

    public function test_generate_modify_dry_run_recommends_interactive_safety_routing(): void
    {
        $baseline = $this->runCommand(new Application(), [
            'foundry',
            'generate',
            'Create',
            'comments',
            '--mode=new',
            '--json',
        ]);
        $this->assertSame(0, $baseline['status']);

        $feature = (string) $baseline['payload']['plan']['metadata']['feature'];

        $result = $this->runCommand(new Application(), [
            'foundry',
            'generate',
            'Refine',
            'comments',
            'notes',
            '--mode=modify',
            '--target=' . $feature,
            '--dry-run',
            '--json',
        ]);

        $this->assertSame(0, $result['status']);
        $this->assertSame('interactive', $result['payload']['safety_routing']['recommended_mode']);
        $this->assertTrue($result['payload']['safety_routing']['recommended_interactive']);
        $this->assertSame('MEDIUM', $result['payload']['safety_routing']['signals']['risk_level']);
        $this->assertContains('elevated_risk', $result['payload']['safety_routing']['reason_codes']);
    }

    public function test_generate_interactive_smoke_invocation_reaches_review_and_rejects_non_destructively(): void
    {
        $app = $this->interactiveApplication(
            static fn(InteractiveGenerateReviewRequest $request): InteractiveGenerateReviewResult => new InteractiveGenerateReviewResult(
                approved: false,
                plan: $request->plan,
                userDecisions: [['type' => 'reject']],
                preview: ['summary' => [], 'actions' => [], 'diffs' => []],
                risk: ['level' => 'LOW', 'reasons' => ['Plan is additive only.'], 'risky_action_indexes' => [], 'risky_paths' => []],
            ),
        );

        $result = $this->runCommand($app, [
            'foundry',
            'generate',
            'Create',
            'comments',
            '--mode=new',
            '--interactive',
            '--json',
        ]);

        $this->assertSame(0, $result['status']);
        $this->assertArrayNotHasKey('error', $result['payload']);
        $this->assertTrue($result['payload']['interactive']['enabled']);
        $this->assertTrue($result['payload']['interactive']['rejected']);
        $this->assertSame([['type' => 'reject']], $result['payload']['interactive']['user_decisions']);
        $this->assertArrayHasKey('original_plan', $result['payload']['interactive']);
        $this->assertTrue($result['payload']['verification_results']['skipped']);
        $this->assertFileDoesNotExist($this->project->root . '/app/features/comments_system/feature.yaml');
    }

    public function test_generate_interactive_reject_aborts_without_writing_files(): void
    {
        $app = $this->interactiveApplication(
            static fn(InteractiveGenerateReviewRequest $request): InteractiveGenerateReviewResult => new InteractiveGenerateReviewResult(
                approved: false,
                plan: $request->plan,
                userDecisions: [['type' => 'reject']],
                preview: ['summary' => [], 'actions' => [], 'diffs' => []],
                risk: ['level' => 'LOW', 'reasons' => ['Plan is additive only.'], 'risky_action_indexes' => [], 'risky_paths' => []],
            ),
        );

        $result = $this->runCommand($app, [
            'foundry',
            'generate',
            'Create',
            'comments',
            '--mode=new',
            '--interactive',
            '--json',
        ]);

        $this->assertSame(0, $result['status']);
        $this->assertTrue($result['payload']['interactive']['rejected']);
        $this->assertTrue($result['payload']['verification_results']['skipped']);
        $this->assertFileDoesNotExist($this->project->root . '/app/features/comments_system/feature.yaml');
    }

    public function test_generate_interactive_can_execute_filtered_modify_plan(): void
    {
        $baseline = $this->runCommand(new Application(), [
            'foundry',
            'generate',
            'Create',
            'comments',
            '--mode=new',
            '--json',
        ]);
        $this->assertSame(0, $baseline['status']);

        $feature = (string) $baseline['payload']['plan']['metadata']['feature'];
        $manifestPath = $this->project->root . '/app/features/' . $feature . '/feature.yaml';
        $promptsPath = $this->project->root . '/app/features/' . $feature . '/prompts.md';
        $originalPrompts = (string) file_get_contents($promptsPath);

        $app = $this->interactiveApplication(function (InteractiveGenerateReviewRequest $request) use ($feature): InteractiveGenerateReviewResult {
            $filtered = $this->modifiedPlanWithoutPath($request->plan, 'app/features/' . $feature . '/prompts.md');

            return new InteractiveGenerateReviewResult(
                approved: true,
                plan: $filtered,
                userDecisions: [
                    ['type' => 'exclude_file', 'path' => 'app/features/' . $feature . '/prompts.md'],
                    ['type' => 'approve'],
                ],
                preview: ['summary' => [], 'actions' => [], 'diffs' => []],
                risk: ['level' => 'MEDIUM', 'reasons' => ['Plan modifies existing files.'], 'risky_action_indexes' => [], 'risky_paths' => []],
                modified: true,
            );
        });

        $result = $this->runCommand($app, [
            'foundry',
            'generate',
            'Refine',
            'comments',
            'notes',
            '--mode=modify',
            '--target=' . $feature,
            '--interactive',
            '--json',
        ]);

        $this->assertSame(0, $result['status']);
        $this->assertTrue($result['payload']['interactive']['modified']);
        $this->assertSame($originalPrompts, (string) file_get_contents($promptsPath));
        $this->assertStringContainsString('Modification intent: Refine comments notes.', (string) file_get_contents($manifestPath));
        $this->assertCount(1, $result['payload']['actions_taken']);
    }

    /**
     * @param array<int,string> $argv
     * @return array{status:int,payload:array<string,mixed>}
     */
    private function runCommand(Application $app, array $argv): array
    {
        ob_start();
        $status = $app->run($argv);
        $output = trim((string) ob_get_clean());

        /** @var array<string,mixed> $payload */
        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        return ['status' => $status, 'payload' => $payload];
    }

    /**
     * @param array<int,string> $argv
     * @return array{status:int,output:string}
     */
    private function runPlainCommand(Application $app, array $argv): array
    {
        ob_start();
        $status = $app->run($argv);
        $output = trim((string) ob_get_clean());

        return ['status' => $status, 'output' => $output];
    }

    private function fixturePath(string $name): string
    {
        return dirname(__DIR__) . '/Fixtures/Packs/' . $name;
    }

    /**
     * @param array<string,mixed> $policy
     */
    private function writeGeneratePolicy(array $policy): void
    {
        $dir = $this->project->root . '/.foundry/policies';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents(
            $dir . '/generate.json',
            json_encode($policy, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL,
        );
    }

    /**
     * @param array<string,mixed> $workflow
     */
    private function writeGenerateWorkflow(array $workflow): void
    {
        file_put_contents(
            $this->project->root . '/generate-workflow.json',
            json_encode($workflow, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL,
        );
    }

    /**
     * @param array<string,mixed> $template
     */
    private function writeGenerateTemplate(string $filename, array $template): void
    {
        $dir = $this->project->root . '/.foundry/templates';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents(
            $dir . '/' . $filename,
            json_encode($template, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL,
        );
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function writeJsonFile(string $relativePath, array $payload): void
    {
        $absolutePath = $this->project->root . '/' . ltrim($relativePath, '/');
        $directory = dirname($absolutePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents(
            $absolutePath,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function fixtureManifest(string $fixtureName): array
    {
        return json_decode((string) file_get_contents($this->fixturePath($fixtureName) . '/foundry.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<int,array<string,mixed>> $registryEntries
     * @param array<string,string> $downloads
     */
    private function hostedGenerateApplication(array $registryEntries, array $downloads): Application
    {
        $registryUrl = 'https://registry.example/packs';
        $responses = $downloads + [
            $registryUrl => json_encode($registryEntries, JSON_THROW_ON_ERROR),
        ];

        $fetcher = static function (string $url) use ($responses): string {
            if (!array_key_exists($url, $responses)) {
                throw new \RuntimeException('Unexpected URL: ' . $url);
            }

            return $responses[$url];
        };

        $paths = Paths::fromCwd($this->project->root);
        $registry = new HostedPackRegistry($paths, $fetcher, $registryUrl);
        $manager = new PackManager($paths, $registry);

        $commands = [new GenerateCommand($manager)];

        foreach (Application::registeredCommands() as $command) {
            if ($command instanceof GenerateCommand) {
                continue;
            }

            $commands[] = $command;
        }

        return new Application($commands);
    }

    /**
     * @param callable(InteractiveGenerateReviewRequest):InteractiveGenerateReviewResult $callback
     */
    private function interactiveApplication(callable $callback): Application
    {
        $commands = [new GenerateCommand(
            interactiveReviewerFactory: static function (CommandContext $context) use ($callback): InteractiveGenerateReviewer {
                return new class ($callback) implements InteractiveGenerateReviewer {
                    public function __construct(private readonly mixed $callback) {}

                    #[\Override]
                    public function review(InteractiveGenerateReviewRequest $request): InteractiveGenerateReviewResult
                    {
                        return ($this->callback)($request);
                    }
                };
            },
        )];

        foreach (Application::registeredCommands() as $command) {
            if ($command instanceof GenerateCommand) {
                continue;
            }

            $commands[] = $command;
        }

        return new Application($commands);
    }

    private function fixtureArchive(string $fixtureName): string
    {
        $archive = tempnam(sys_get_temp_dir(), 'foundry-generate-archive-');
        assert(is_string($archive));

        $zip = new \ZipArchive();
        $opened = $zip->open($archive, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $this->assertSame(true, $opened);

        $source = $this->fixturePath($fixtureName);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof \SplFileInfo || !$fileInfo->isFile()) {
                continue;
            }

            $relative = substr($fileInfo->getPathname(), strlen(rtrim($source, '/') . '/'));
            $zip->addFile($fileInfo->getPathname(), $relative);
        }

        $zip->close();
        $contents = file_get_contents($archive);
        @unlink($archive);

        return is_string($contents) ? $contents : '';
    }

    private function initGitRepository(): void
    {
        $this->git(['init']);
        $this->git(['branch', '-m', 'main']);
        $this->git(['config', 'user.name', 'Foundry Tests']);
        $this->git(['config', 'user.email', 'foundry-tests@example.invalid']);
        $this->git(['add', '.']);
        $this->git(['commit', '-m', 'Initial commit']);
    }

    /**
     * @param array<int,string> $args
     */
    private function git(array $args): string
    {
        $command = array_merge(['git', '-C', $this->project->root], $args);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);
        $this->assertIsResource($process);

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $status = proc_close($process);

        $this->assertSame(0, $status, trim($stderr) !== '' ? trim($stderr) : trim($stdout));

        return trim($stdout);
    }

    private function modifiedPlanWithoutPath(GenerationPlan $plan, string $excludedPath): GenerationPlan
    {
        $actions = array_values(array_filter(
            $plan->actions,
            static fn(array $action): bool => (string) ($action['path'] ?? '') !== $excludedPath,
        ));
        $affectedFiles = array_values(array_filter(
            $plan->affectedFiles,
            static fn(string $path): bool => $path !== $excludedPath,
        ));

        return new GenerationPlan(
            actions: $actions,
            affectedFiles: $affectedFiles,
            risks: ['Interactive review modified the original plan before execution.'],
            validations: $plan->validations,
            origin: $plan->origin,
            generatorId: $plan->generatorId,
            extension: $plan->extension,
            metadata: $plan->metadata,
            confidence: $plan->confidence,
        );
    }
}
