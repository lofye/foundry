<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Generate\GeneratePolicyEngine;
use Foundry\Generate\GenerationPlan;
use Foundry\Generate\Intent;
use Foundry\Support\FoundryError;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class GeneratePolicyEngineTest extends TestCase
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

    public function test_evaluate_returns_backward_compatible_pass_when_no_policy_file_exists(): void
    {
        $result = $this->engine()->evaluate(
            $this->plan([[
                'type' => 'create_file',
                'path' => 'app/features/comments/feature.yaml',
                'summary' => 'Create feature manifest.',
                'explain_node_id' => 'feature:comments',
            ]]),
            new Intent(raw: 'Create comments', mode: 'new'),
        );

        $this->assertFalse($result['loaded']);
        $this->assertSame('pass', $result['status']);
        $this->assertFalse($result['blocking']);
        $this->assertSame([], $result['matched_rule_ids']);
        $this->assertSame([], $result['warnings']);
        $this->assertSame([], $result['violations']);
    }

    public function test_valid_policy_loads_and_evaluates_deterministically(): void
    {
        $this->writePolicy([
            'version' => 1,
            'rules' => [
                [
                    'id' => 'protect-features',
                    'type' => 'deny',
                    'description' => 'Prevent feature file creation during this run.',
                    'match' => [
                        'actions' => ['create_file'],
                        'paths' => ['app/features/**'],
                    ],
                ],
                [
                    'id' => 'warn-large-plan',
                    'type' => 'warn',
                    'description' => 'Warn when the plan changes more than one file.',
                    'limit' => [
                        'kind' => 'file_count',
                        'max' => 1,
                    ],
                ],
            ],
        ]);

        $plan = $this->plan([
            [
                'type' => 'create_file',
                'path' => 'app/features/comments/feature.yaml',
                'summary' => 'Create feature manifest.',
                'explain_node_id' => 'feature:comments',
            ],
            [
                'type' => 'add_test',
                'path' => 'app/features/comments/tests/comments_feature_test.php',
                'summary' => 'Add feature test.',
                'explain_node_id' => 'feature:comments',
            ],
        ]);
        $intent = new Intent(raw: 'Create comments', mode: 'new');

        $first = $this->engine()->evaluate($plan, $intent);
        $second = $this->engine()->evaluate($plan, $intent);

        $this->assertSame($first, $second);
        $this->assertTrue($first['loaded']);
        $this->assertSame('deny', $first['status']);
        $this->assertSame(['protect-features', 'warn-large-plan'], $first['matched_rule_ids']);
        $this->assertCount(1, $first['warnings']);
        $this->assertCount(1, $first['violations']);
    }

    public function test_malformed_policy_fails_clearly(): void
    {
        $this->writeRawPolicy("{\n  invalid\n");

        $this->expectException(FoundryError::class);

        try {
            $this->engine()->evaluate($this->plan([]), new Intent(raw: 'Create comments', mode: 'new'));
        } catch (FoundryError $error) {
            $this->assertSame('GENERATE_POLICY_INVALID', $error->errorCode);
            $this->assertSame('.foundry/policies/generate.json', $error->details['path']);
            throw $error;
        }
    }

    public function test_invalid_policy_schema_fields_fail_clearly(): void
    {
        $this->writePolicy([
            'version' => 1,
            'rules' => [[
                'id' => 'bad-fields',
                'type' => 'warn',
                'description' => 'Invalid criteria field shape.',
                'match' => [
                    'paths' => ['app/features/**'],
                    'actions' => ['create_file'],
                    'features' => ['comments'],
                    'modules' => ['comments'],
                    'graph_node_types' => ['feature'],
                    'risk_levels' => ['HIGH'],
                    'modes' => ['new'],
                ],
                'limit' => [
                    'kind' => 'unknown_limit',
                    'max' => 1,
                ],
            ]],
        ]);

        $this->expectException(FoundryError::class);

        try {
            $this->engine()->evaluate(
                $this->plan([[
                    'type' => 'create_file',
                    'path' => 'app/features/comments/feature.yaml',
                    'summary' => 'Create feature manifest.',
                    'explain_node_id' => 'feature:comments',
                ]]),
                new Intent(raw: 'Create comments', mode: 'new'),
            );
        } catch (FoundryError $error) {
            $this->assertSame('GENERATE_POLICY_INVALID', $error->errorCode);
            $this->assertSame('bad-fields', $error->details['rule_id']);
            throw $error;
        }
    }

    public function test_duplicate_rule_ids_are_rejected(): void
    {
        $this->writePolicy([
            'version' => 1,
            'rules' => [
                [
                    'id' => 'duplicate',
                    'type' => 'warn',
                    'description' => 'Warn once.',
                    'match' => ['actions' => ['create_file']],
                ],
                [
                    'id' => 'duplicate',
                    'type' => 'deny',
                    'description' => 'Deny once.',
                    'match' => ['actions' => ['create_file']],
                ],
            ],
        ]);

        $this->expectException(FoundryError::class);

        try {
            $this->engine()->evaluate(
                $this->plan([[
                    'type' => 'create_file',
                    'path' => 'app/features/comments/feature.yaml',
                    'summary' => 'Create feature manifest.',
                    'explain_node_id' => 'feature:comments',
                ]]),
                new Intent(raw: 'Create comments', mode: 'new'),
            );
        } catch (FoundryError $error) {
            $this->assertSame('GENERATE_POLICY_INVALID', $error->errorCode);
            $this->assertSame('duplicate', $error->details['rule_id']);
            throw $error;
        }
    }

    public function test_invalid_criteria_field_shape_is_rejected(): void
    {
        $this->writePolicy([
            'version' => 1,
            'rules' => [[
                'id' => 'bad-paths',
                'type' => 'warn',
                'description' => 'Invalid criteria field shape.',
                'match' => [
                    'actions' => ['create_file'],
                    'paths' => ['app/features/**'],
                    'modes' => ['new'],
                    'risk_levels' => ['LOW'],
                    'features' => ['comments'],
                    'modules' => ['comments'],
                    'graph_node_types' => ['feature'],
                ],
            ]],
        ]);

        $policyPath = $this->project->root . '/.foundry/policies/generate.json';
        $decoded = json_decode((string) file_get_contents($policyPath), true, 512, JSON_THROW_ON_ERROR);
        $decoded['rules'][0]['match']['paths'] = ['nested' => ['unexpected']];
        file_put_contents($policyPath, json_encode($decoded, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);

        $this->expectException(FoundryError::class);

        try {
            $this->engine()->evaluate(
                $this->plan([[
                    'type' => 'create_file',
                    'path' => 'app/features/comments/feature.yaml',
                    'summary' => 'Create feature manifest.',
                    'explain_node_id' => 'feature:comments',
                ]]),
                new Intent(raw: 'Create comments', mode: 'new'),
            );
        } catch (FoundryError $error) {
            $this->assertSame('GENERATE_POLICY_INVALID', $error->errorCode);
            $this->assertSame('paths', $error->details['criteria_field']);
            throw $error;
        }
    }

    public function test_require_rule_enforces_required_matching_actions(): void
    {
        $this->writePolicy([
            'version' => 1,
            'rules' => [[
                'id' => 'tests-required',
                'type' => 'require',
                'description' => 'New feature files require tests.',
                'match' => [
                    'actions' => ['create_file'],
                    'paths' => ['app/features/**'],
                ],
                'require' => [
                    'actions' => ['add_test'],
                    'paths' => ['app/features/**/tests/**'],
                ],
            ]],
        ]);

        $withoutTests = $this->engine()->evaluate(
            $this->plan([[
                'type' => 'create_file',
                'path' => 'app/features/comments/feature.yaml',
                'summary' => 'Create feature manifest.',
                'explain_node_id' => 'feature:comments',
            ]]),
            new Intent(raw: 'Create comments', mode: 'new'),
        );
        $withTests = $this->engine()->evaluate(
            $this->plan([
                [
                    'type' => 'create_file',
                    'path' => 'app/features/comments/feature.yaml',
                    'summary' => 'Create feature manifest.',
                    'explain_node_id' => 'feature:comments',
                ],
                [
                    'type' => 'add_test',
                    'path' => 'app/features/comments/tests/comments_feature_test.php',
                    'summary' => 'Add feature test.',
                    'explain_node_id' => 'feature:comments',
                ],
            ]),
            new Intent(raw: 'Create comments', mode: 'new'),
        );

        $this->assertSame('deny', $withoutTests['status']);
        $this->assertSame('tests-required', $withoutTests['violations'][0]['rule_id']);
        $this->assertSame('pass', $withTests['status']);
    }

    public function test_limit_rule_enforces_plan_scope_deterministically(): void
    {
        $this->writePolicy([
            'version' => 1,
            'rules' => [[
                'id' => 'limit-files',
                'type' => 'limit',
                'description' => 'Keep plans to one file.',
                'limit' => [
                    'kind' => 'file_count',
                    'max' => 1,
                ],
            ]],
        ]);

        $result = $this->engine()->evaluate(
            $this->plan([
                [
                    'type' => 'create_file',
                    'path' => 'app/features/comments/feature.yaml',
                    'summary' => 'Create feature manifest.',
                    'explain_node_id' => 'feature:comments',
                ],
                [
                    'type' => 'add_test',
                    'path' => 'app/features/comments/tests/comments_feature_test.php',
                    'summary' => 'Add feature test.',
                    'explain_node_id' => 'feature:comments',
                ],
            ]),
            new Intent(raw: 'Create comments', mode: 'new'),
        );

        $this->assertSame('deny', $result['status']);
        $this->assertSame('limit-files', $result['violations'][0]['rule_id']);
        $this->assertSame(2, $result['violations'][0]['details']['observed']);
        $this->assertSame('file_count', $result['violations'][0]['details']['limit']['kind']);
    }

    public function test_matching_supports_action_path_mode_risk_feature_and_graph_node_type_criteria(): void
    {
        $this->writePolicy([
            'version' => 1,
            'rules' => [[
                'id' => 'protect-high-risk-feature-deletes',
                'type' => 'deny',
                'description' => 'Block high-risk deletes in the comments feature.',
                'match' => [
                    'actions' => ['delete_file'],
                    'paths' => ['app/features/comments/**'],
                    'modes' => ['modify'],
                    'risk_levels' => ['HIGH'],
                    'features' => ['comments'],
                    'graph_node_types' => ['feature'],
                ],
            ]],
        ]);

        $matching = $this->engine()->evaluate(
            $this->plan([[
                'type' => 'delete_file',
                'path' => 'app/features/comments/legacy.txt',
                'summary' => 'Delete legacy file.',
                'explain_node_id' => 'feature:comments',
            ]]),
            new Intent(raw: 'Refine comments', mode: 'modify', target: 'comments'),
        );
        $nonMatching = $this->engine()->evaluate(
            $this->plan([[
                'type' => 'delete_file',
                'path' => 'app/features/comments/legacy.txt',
                'summary' => 'Delete legacy file.',
                'explain_node_id' => 'feature:comments',
            ]]),
            new Intent(raw: 'Create comments', mode: 'new'),
        );

        $this->assertSame('deny', $matching['status']);
        $this->assertSame('protect-high-risk-feature-deletes', $matching['violations'][0]['rule_id']);
        $this->assertSame('pass', $nonMatching['status']);
    }

    public function test_override_metadata_and_affected_actions_are_reported_when_explicit_override_is_requested(): void
    {
        $this->writePolicy([
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

        $result = $this->engine()->evaluate(
            $this->plan([[
                'type' => 'create_file',
                'path' => 'app/features/comments/feature.yaml',
                'summary' => 'Create feature manifest.',
                'explain_node_id' => 'feature:comments',
            ]]),
            new Intent(raw: 'Create comments', mode: 'new', allowPolicyViolations: true),
            overrideRequested: true,
            overrideSource: 'flag',
        );

        $this->assertSame('deny', $result['status']);
        $this->assertFalse($result['blocking']);
        $this->assertTrue($result['override_available']);
        $this->assertTrue($result['override_requested']);
        $this->assertTrue($result['override_used']);
        $this->assertSame('flag', $result['override_source']);
        $this->assertSame('app/features/comments/feature.yaml', $result['affected_files'][0]);
        $this->assertSame(0, $result['affected_actions'][0]['index']);
    }

    private function engine(): GeneratePolicyEngine
    {
        return new GeneratePolicyEngine(new Paths($this->project->root));
    }

    /**
     * @param list<array<string,mixed>> $actions
     */
    private function plan(array $actions): GenerationPlan
    {
        $affectedFiles = array_values(array_unique(array_filter(array_map(
            static fn(array $action): string => trim((string) ($action['path'] ?? '')),
            $actions,
        ))));
        sort($affectedFiles);

        return new GenerationPlan(
            actions: $actions,
            affectedFiles: $affectedFiles,
            risks: [],
            validations: ['compile_graph'],
            origin: 'core',
            generatorId: 'core.feature.generate',
            metadata: ['feature' => 'comments'],
        );
    }

    /**
     * @param array<string,mixed> $policy
     */
    private function writePolicy(array $policy): void
    {
        $this->writeRawPolicy(json_encode($policy, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
    }

    private function writeRawPolicy(string $policy): void
    {
        $dir = $this->project->root . '/.foundry/policies';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($dir . '/generate.json', $policy);
    }
}
