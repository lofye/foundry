<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Generate\GenerateWorkflowContextResolver;
use Foundry\Generate\GenerateWorkflowLoader;
use Foundry\Support\FoundryError;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class GenerateWorkflowLoaderTest extends TestCase
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

    public function test_loader_builds_deterministic_workflow_definition_and_context_resolution(): void
    {
        file_put_contents($this->project->root . '/generate-workflow.json', json_encode([
            'shared_context' => [
                'resource' => 'comments',
            ],
            'steps' => [
                [
                    'id' => 'create_comments',
                    'description' => 'Create {{shared.resource}} feature',
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
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);

        $loader = new GenerateWorkflowLoader(Paths::fromCwd($this->project->root));
        $workflowA = $loader->load('generate-workflow.json');
        $workflowB = $loader->load('generate-workflow.json');

        $this->assertSame($workflowA->id, $workflowB->id);
        $this->assertSame('generate-workflow.json', $workflowA->path);
        $this->assertCount(2, $workflowA->steps);

        $resolver = new GenerateWorkflowContextResolver();
        $first = $resolver->resolveStep($workflowA->steps[0], [
            'shared' => $workflowA->sharedContext,
            'steps' => [],
            'workflow' => ['id' => $workflowA->id, 'path' => $workflowA->path],
        ]);
        $second = $resolver->resolveStep($workflowA->steps[1], [
            'shared' => $workflowA->sharedContext,
            'steps' => [
                'create_comments' => [
                    'feature' => 'comments',
                ],
            ],
            'workflow' => ['id' => $workflowA->id, 'path' => $workflowA->path],
        ]);

        $this->assertSame('Create comments', $first->rawIntent);
        $this->assertSame('Create comments feature', $first->description);
        $this->assertSame('Create comments audit', $second->rawIntent);
    }

    public function test_loader_rejects_dependencies_that_do_not_reference_earlier_steps(): void
    {
        file_put_contents($this->project->root . '/generate-workflow.json', json_encode([
            'steps' => [
                [
                    'id' => 'create_audit',
                    'intent' => 'Create comments audit',
                    'mode' => 'new',
                    'dependencies' => ['create_comments'],
                ],
                [
                    'id' => 'create_comments',
                    'intent' => 'Create comments',
                    'mode' => 'new',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);

        $loader = new GenerateWorkflowLoader(Paths::fromCwd($this->project->root));

        try {
            $loader->load('generate-workflow.json');
            self::fail('Expected invalid dependency failure.');
        } catch (FoundryError $error) {
            $this->assertSame('GENERATE_WORKFLOW_DEPENDENCY_INVALID', $error->errorCode);
        }
    }

    public function test_loader_rejects_missing_file_invalid_json_missing_steps_and_invalid_step_fields(): void
    {
        $loader = new GenerateWorkflowLoader(Paths::fromCwd($this->project->root));

        try {
            $loader->load('missing-workflow.json');
            self::fail('Expected missing workflow failure.');
        } catch (FoundryError $error) {
            $this->assertSame('GENERATE_WORKFLOW_NOT_FOUND', $error->errorCode);
        }

        file_put_contents($this->project->root . '/bad.json', '{');
        try {
            $loader->load('bad.json');
            self::fail('Expected invalid JSON failure.');
        } catch (FoundryError $error) {
            $this->assertSame('GENERATE_WORKFLOW_INVALID', $error->errorCode);
        }

        file_put_contents($this->project->root . '/empty.json', json_encode([], JSON_THROW_ON_ERROR));
        try {
            $loader->load('empty.json');
            self::fail('Expected missing steps failure.');
        } catch (FoundryError $error) {
            $this->assertSame('GENERATE_WORKFLOW_STEPS_REQUIRED', $error->errorCode);
        }

        $cases = [
            'missing-id.json' => [
                'steps' => [[
                    'intent' => 'Create comments',
                    'mode' => 'new',
                ]],
                'code' => 'GENERATE_WORKFLOW_STEP_ID_REQUIRED',
            ],
            'duplicate-id.json' => [
                'steps' => [
                    ['id' => 'create_comments', 'intent' => 'Create comments', 'mode' => 'new'],
                    ['id' => 'create_comments', 'intent' => 'Create audit', 'mode' => 'new'],
                ],
                'code' => 'GENERATE_WORKFLOW_STEP_ID_DUPLICATE',
            ],
            'missing-intent.json' => [
                'steps' => [[
                    'id' => 'create_comments',
                    'mode' => 'new',
                ]],
                'code' => 'GENERATE_WORKFLOW_STEP_INTENT_REQUIRED',
            ],
            'invalid-mode.json' => [
                'steps' => [[
                    'id' => 'create_comments',
                    'intent' => 'Create comments',
                    'mode' => 'ship',
                ]],
                'code' => 'GENERATE_WORKFLOW_STEP_MODE_INVALID',
            ],
            'missing-target.json' => [
                'steps' => [[
                    'id' => 'modify_comments',
                    'intent' => 'Refine comments',
                    'mode' => 'modify',
                ]],
                'code' => 'GENERATE_WORKFLOW_STEP_TARGET_REQUIRED',
            ],
        ];

        foreach ($cases as $filename => $case) {
            file_put_contents(
                $this->project->root . '/' . $filename,
                json_encode($case, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL,
            );

            try {
                $loader->load($filename);
                self::fail('Expected workflow validation failure for ' . $filename . '.');
            } catch (FoundryError $error) {
                $this->assertSame($case['code'], $error->errorCode);
            }
        }
    }

    public function test_context_resolver_rejects_missing_and_non_scalar_placeholders_and_definition_to_array_is_stable(): void
    {
        file_put_contents($this->project->root . '/generate-workflow.json', json_encode([
            'shared_context' => [
                'resource' => 'comments',
                'details' => ['nested' => true],
            ],
            'steps' => [[
                'id' => 'create_comments',
                'intent' => 'Create {{shared.resource}}',
                'mode' => 'new',
                'packs' => ['{{shared.resource}}/pack'],
            ]],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);

        $loader = new GenerateWorkflowLoader(Paths::fromCwd($this->project->root));
        $workflow = $loader->load('generate-workflow.json');
        $resolver = new GenerateWorkflowContextResolver();

        $this->assertSame('create_comments', $workflow->toArray()['steps'][0]['id']);

        try {
            $resolver->resolveString('{{shared.missing}}', ['shared' => $workflow->sharedContext], 'create_comments');
            self::fail('Expected missing placeholder failure.');
        } catch (FoundryError $error) {
            $this->assertSame('GENERATE_WORKFLOW_CONTEXT_MISSING', $error->errorCode);
        }

        try {
            $resolver->resolveString('{{shared.details}}', ['shared' => $workflow->sharedContext], 'create_comments');
            self::fail('Expected non-scalar placeholder failure.');
        } catch (FoundryError $error) {
            $this->assertSame('GENERATE_WORKFLOW_CONTEXT_INVALID', $error->errorCode);
        }

        $resolved = $resolver->resolveStep($workflow->steps[0], [
            'shared' => $workflow->sharedContext,
            'steps' => [],
            'workflow' => ['id' => $workflow->id, 'path' => $workflow->path],
        ]);

        $this->assertSame(['comments/pack'], $resolved->packHints);
    }
}
