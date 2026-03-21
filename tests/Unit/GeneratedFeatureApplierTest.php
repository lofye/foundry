<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Generation\FeatureGenerator;
use Foundry\Generation\WorkflowGenerator;
use Foundry\Pro\Generation\GeneratedFeatureApplier;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class GeneratedFeatureApplierTest extends TestCase
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

    public function test_predicted_files_include_workflow_artifacts_and_transition_feature(): void
    {
        $applier = $this->applier();

        $files = $applier->predictedFiles($this->planFixture());

        $this->assertContains($this->project->root . '/app/definitions/workflows/posts_review.workflow.yaml', $files);
        $this->assertContains($this->project->root . '/app/features/approve_post/feature.yaml', $files);
        $this->assertContains($this->project->root . '/app/features/transition_post_workflow/feature.yaml', $files);
    }

    public function test_apply_writes_feature_and_workflow_files(): void
    {
        $applier = $this->applier();

        $result = $applier->apply($this->planFixture());

        $this->assertFileExists($this->project->root . '/app/features/approve_post/feature.yaml');
        $this->assertFileExists($this->project->root . '/app/features/approve_post/action.php');
        $this->assertFileExists($this->project->root . '/app/definitions/workflows/posts_review.workflow.yaml');
        $this->assertFileExists($this->project->root . '/app/features/transition_post_workflow/feature.yaml');
        $this->assertArrayHasKey('workflow', $result['artifacts']);
        $this->assertContains($this->project->root . '/app/definitions/workflows/posts_review.workflow.yaml', $result['files']);
    }

    private function applier(): GeneratedFeatureApplier
    {
        $features = new FeatureGenerator($this->paths);

        return new GeneratedFeatureApplier(
            $this->paths,
            $features,
            new WorkflowGenerator($this->paths, $features),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function planFixture(): array
    {
        return [
            'feature' => [
                'feature' => 'approve_post',
                'description' => 'Approve a post.',
                'kind' => 'http',
                'owners' => ['product'],
                'route' => ['method' => 'POST', 'path' => '/posts/{id}/approve'],
                'input' => ['fields' => [
                    'id' => ['type' => 'string', 'required' => true],
                    'comment' => ['type' => 'string', 'required' => false],
                ]],
                'output' => ['fields' => [
                    'id' => ['type' => 'string', 'required' => true],
                    'status' => ['type' => 'string', 'required' => true],
                ]],
                'auth' => ['required' => true, 'strategies' => ['bearer'], 'permissions' => ['posts.approve']],
                'database' => ['reads' => ['posts'], 'writes' => ['posts'], 'queries' => ['approve_post'], 'transactions' => 'required'],
                'cache' => ['reads' => [], 'writes' => [], 'invalidate' => ['posts:list']],
                'events' => ['emit' => ['post.approved'], 'subscribe' => []],
                'jobs' => ['dispatch' => []],
                'tests' => ['required' => ['contract', 'feature', 'auth']],
                'llm' => ['editable' => true, 'risk_level' => 'medium', 'notes_file' => 'prompts.md'],
            ],
            'workflow' => [
                'name' => 'posts_review',
                'definition' => [
                    'resource' => 'posts',
                    'states' => ['draft', 'pending_review', 'approved'],
                    'transitions' => [
                        'submit' => [
                            'from' => ['draft'],
                            'to' => 'pending_review',
                            'permission' => 'posts.submit',
                            'emit' => ['post.submitted'],
                        ],
                        'approve' => [
                            'from' => ['pending_review'],
                            'to' => 'approved',
                            'permission' => 'posts.approve',
                            'emit' => ['post.approved'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
