<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Compiler\GraphCompiler;
use Foundry\Compiler\GraphVerifier;
use Foundry\Generation\FeatureGenerator;
use Foundry\Generation\WorkflowGenerator;
use Foundry\Pro\Generation\AIGenerationService;
use Foundry\Support\FoundryError;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use Foundry\Verification\ContractsVerifier;
use Foundry\Verification\WorkflowVerifier;
use PHPUnit\Framework\TestCase;

final class AIGenerationServiceTest extends TestCase
{
    private TempProject $project;
    private string $cwd;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->cwd = getcwd() ?: '.';
        chdir($this->project->root);
        $this->createFeature('publish_post', 'POST', '/posts');
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        $this->project->cleanup();
    }

    public function test_generate_supports_deterministic_dry_runs(): void
    {
        $result = $this->service()->generate('Add bookmark support to posts', [
            'deterministic' => true,
            'dry_run' => true,
            'feature_context' => true,
        ]);

        $this->assertSame(0, $result['status']);
        $this->assertTrue($result['payload']['ok']);
        $this->assertTrue($result['payload']['dry_run']);
        $this->assertTrue($result['payload']['deterministic']);
        $this->assertSame('deterministic', $result['payload']['provider']['mode']);
        $this->assertSame('bookmark_post', $result['payload']['plan']['feature']['feature']);
        $this->assertSame(['publish_post'], $result['payload']['context']['selected_features']);
        $this->assertContains(
            $this->project->root . '/app/features/bookmark_post/feature.yaml',
            $result['payload']['predicted_files'],
        );
        $this->assertSame(0, $result['payload']['preflight']['diagnostics']['summary']['error']);
    }

    public function test_generate_can_use_static_provider_output_during_dry_run(): void
    {
        mkdir($this->project->root . '/config', 0777, true);
        file_put_contents($this->project->root . '/config/ai.php', <<<'PHP'
<?php
declare(strict_types=1);

return [
    'default' => 'fixture',
    'providers' => [
        'fixture' => [
            'driver' => 'static',
            'parsed' => [
                'feature' => [
                    'feature' => 'Review Post',
                    'description' => '',
                    'route' => [
                        'method' => 'TRACE',
                        'path' => 'review',
                    ],
                    'auth' => [
                        'required' => false,
                        'strategies' => ['bearer', 'bearer', 'session'],
                        'permissions' => ['posts.review', 'posts.review'],
                    ],
                ],
                'workflow' => [
                    'name' => 'Posts Approval',
                    'definition' => [
                        'resource' => 'posts',
                        'states' => ['approved', 'pending_review', 'pending_review'],
                        'transitions' => [
                            'approve' => [
                                'from' => ['pending_review'],
                                'to' => 'approved',
                            ],
                        ],
                    ],
                ],
                'explanation' => ' Provider-backed plan ',
            ],
            'input_tokens' => 13,
            'output_tokens' => 21,
            'cost_estimate' => 1.25,
            'metadata' => ['fixture' => true],
        ],
    ],
];
PHP);

        $result = $this->service()->generate('Add post approval workflow', ['dry_run' => true]);

        $this->assertSame(0, $result['status']);
        $this->assertSame('provider', $result['payload']['provider']['mode']);
        $this->assertSame('fixture', $result['payload']['provider']['provider']);
        $this->assertSame('foundry-generator', $result['payload']['provider']['model']);
        $this->assertSame(13, $result['payload']['provider']['input_tokens']);
        $this->assertSame(21, $result['payload']['provider']['output_tokens']);
        $this->assertSame('review_post', $result['payload']['plan']['feature']['feature']);
        $this->assertSame('/generated', $result['payload']['plan']['feature']['route']['path']);
        $this->assertSame('POST', $result['payload']['plan']['feature']['route']['method']);
        $this->assertSame(['product'], $result['payload']['plan']['feature']['owners']);
        $this->assertSame(['bearer', 'session'], $result['payload']['plan']['feature']['auth']['strategies']);
        $this->assertSame('posts_approval', $result['payload']['plan']['workflow']['name']);
        $this->assertSame(['approved', 'pending_review'], $result['payload']['plan']['workflow']['definition']['states']);
        $this->assertSame(['feature', 'workflow', 'explanation'], $result['payload']['plan']['trace']['provider_fields']);
        $this->assertSame('Provider-backed plan', $result['payload']['plan']['explanation']);
    }

    public function test_generate_can_apply_a_deterministic_plan_and_emit_files(): void
    {
        $result = $this->service()->generate('Add bookmark support to posts', [
            'deterministic' => true,
            'force' => true,
        ]);

        $this->assertSame(0, $result['status']);
        $this->assertFalse($result['payload']['dry_run']);
        $this->assertSame('bookmark_post', $result['payload']['plan']['feature']['feature']);
        $this->assertFileExists($this->project->root . '/app/features/bookmark_post/feature.yaml');
        $this->assertFileExists($this->project->root . '/app/features/bookmark_post/tests/bookmark_post_contract_test.php');
        $this->assertContains(
            $this->project->root . '/app/features/bookmark_post/feature.yaml',
            $result['payload']['generated']['files'],
        );
        $this->assertTrue($result['payload']['verification']['graph']['ok']);
        $this->assertTrue($result['payload']['verification']['contracts']['ok']);
    }

    public function test_generate_fails_when_no_provider_is_configured_for_non_deterministic_runs(): void
    {
        $this->expectException(FoundryError::class);
        $this->expectExceptionMessage('No AI provider is configured for generation.');

        $this->service()->generate('Add bookmark support to posts');
    }

    private function service(): AIGenerationService
    {
        $paths = Paths::fromCwd($this->project->root);
        $compiler = new GraphCompiler($paths);

        return new AIGenerationService(
            $paths,
            $compiler,
            new GraphVerifier($paths, $compiler->buildLayout()),
            new FeatureGenerator($paths),
            new WorkflowGenerator($paths, new FeatureGenerator($paths)),
            new ContractsVerifier($paths),
            new WorkflowVerifier($compiler),
        );
    }

    private function createFeature(string $feature, string $method, string $path): void
    {
        $base = $this->project->root . '/app/features/' . $feature;
        mkdir($base . '/tests', 0777, true);

        file_put_contents($base . '/feature.yaml', <<<YAML
version: 1
feature: {$feature}
kind: http
description: test
route:
  method: {$method}
  path: {$path}
input:
  schema: app/features/{$feature}/input.schema.json
output:
  schema: app/features/{$feature}/output.schema.json
auth:
  required: true
  strategies: [bearer]
  permissions: [posts.create]
database:
  reads: []
  writes: []
  transactions: required
  queries: []
cache:
  invalidate: []
events:
  emit: []
jobs:
  dispatch: []
tests:
  required: [contract, feature, auth]
YAML);
        file_put_contents($base . '/input.schema.json', '{"type":"object"}');
        file_put_contents($base . '/output.schema.json', '{"type":"object"}');
        file_put_contents($base . '/action.php', '<?php declare(strict_types=1);');
        file_put_contents($base . '/cache.yaml', "version: 1\nentries: []\n");
        file_put_contents($base . '/events.yaml', "version: 1\nemit: []\nsubscribe: []\n");
        file_put_contents($base . '/jobs.yaml', "version: 1\ndispatch: []\n");
        file_put_contents($base . '/permissions.yaml', "version: 1\npermissions: [posts.create]\nrules: {}\n");
        file_put_contents($base . '/context.manifest.json', '{"version":1,"feature":"' . $feature . '","kind":"http"}');
        file_put_contents($base . '/tests/' . $feature . '_contract_test.php', '<?php declare(strict_types=1);');
        file_put_contents($base . '/tests/' . $feature . '_feature_test.php', '<?php declare(strict_types=1);');
        file_put_contents($base . '/tests/' . $feature . '_auth_test.php', '<?php declare(strict_types=1);');
    }
}
