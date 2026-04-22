<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Generate\GenerationPlan;
use Foundry\Generate\Intent;
use Foundry\Generate\PlanValidator;
use Foundry\Support\FoundryError;
use PHPUnit\Framework\TestCase;

final class GenerationPlanAndValidatorTest extends TestCase
{
    public function test_merge_combines_unique_files_and_generators(): void
    {
        $first = $this->plan(
            generatorId: 'core.feature.a',
            actions: [[
                'type' => 'create_file',
                'path' => 'app/features/comments/feature.yaml',
                'summary' => 'Create manifest.',
                'explain_node_id' => 'feature:comments',
            ]],
            affectedFiles: ['app/features/comments/feature.yaml'],
            risks: ['Creates manifest.'],
            validations: ['verify_graph', 'compile_graph'],
            metadata: ['feature' => 'comments'],
        );
        $second = $this->plan(
            generatorId: 'core.feature.b',
            actions: [[
                'type' => 'add_test',
                'path' => 'app/features/comments/tests/comments_feature_test.php',
                'summary' => 'Create feature test.',
                'explain_node_id' => 'feature:comments',
            ]],
            affectedFiles: ['app/features/comments/tests/comments_feature_test.php'],
            risks: ['Creates tests.'],
            validations: ['verify_feature', 'verify_graph'],
            metadata: ['description' => 'Merged plan'],
        );

        $merged = GenerationPlan::merge([$first, $second]);

        $this->assertSame('core.feature.a+core.feature.b', $merged->generatorId);
        $this->assertSame(
            [
                'app/features/comments/feature.yaml',
                'app/features/comments/tests/comments_feature_test.php',
            ],
            $merged->affectedFiles,
        );
        $this->assertSame(['Creates manifest.', 'Creates tests.'], $merged->risks);
        $this->assertSame(['compile_graph', 'verify_feature', 'verify_graph'], $merged->validations);
        $this->assertSame(['core.feature.a', 'core.feature.b'], $merged->metadata['merged_generators']);
        $this->assertSame('comments', $merged->metadata['feature']);
        $this->assertSame('Merged plan', $merged->metadata['description']);
    }

    public function test_merge_throws_for_empty_plan_list(): void
    {
        $this->expectException(FoundryError::class);
        $this->expectExceptionMessage('At least one generation plan is required.');

        try {
            GenerationPlan::merge([]);
        } catch (FoundryError $error) {
            $this->assertSame('GENERATE_PLAN_EMPTY', $error->errorCode);
            throw $error;
        }
    }

    public function test_merge_throws_for_origin_or_extension_conflicts(): void
    {
        $this->expectException(FoundryError::class);

        try {
            GenerationPlan::merge([
                $this->plan(generatorId: 'core.feature', origin: 'core'),
                $this->plan(generatorId: 'pack.feature', origin: 'pack', extension: 'foundry/blog'),
            ]);
        } catch (FoundryError $error) {
            $this->assertSame('GENERATE_GENERATOR_CONFLICT', $error->errorCode);
            throw $error;
        }
    }

    public function test_merge_throws_for_duplicate_actions(): void
    {
        $action = [
            'type' => 'create_file',
            'path' => 'app/features/comments/feature.yaml',
            'summary' => 'Create manifest.',
            'explain_node_id' => 'feature:comments',
        ];

        $this->expectException(FoundryError::class);

        try {
            GenerationPlan::merge([
                $this->plan(generatorId: 'one', actions: [$action]),
                $this->plan(generatorId: 'two', actions: [$action]),
            ]);
        } catch (FoundryError $error) {
            $this->assertSame('GENERATE_PLAN_CONFLICT', $error->errorCode);
            throw $error;
        }
    }

    public function test_with_confidence_and_to_array_preserve_plan_shape(): void
    {
        $plan = $this->plan(
            generatorId: 'core.feature',
            actions: [[
                'type' => 'create_file',
                'path' => 'app/features/comments/feature.yaml',
                'summary' => 'Create manifest.',
                'explain_node_id' => 'feature:comments',
            ]],
            affectedFiles: ['app/features/comments/feature.yaml'],
            metadata: ['feature' => 'comments'],
        );

        $withConfidence = $plan->withConfidence(['band' => 'high', 'score' => 0.91]);

        $this->assertSame([], $plan->confidence);
        $this->assertSame(['band' => 'high', 'score' => 0.91], $withConfidence->confidence);
        $this->assertSame(
            [
                'actions',
                'affected_files',
                'risks',
                'validations',
                'origin',
                'generator_id',
                'extension',
                'confidence',
                'metadata',
            ],
            array_keys($withConfidence->toArray()),
        );
    }

    public function test_validator_rejects_invalid_origin_pack_extension_duplicates_traceability_and_delete_safety(): void
    {
        $validator = new PlanValidator();
        $intent = new Intent(raw: 'Create comments', mode: 'new');

        $cases = [
            [
                'plan' => $this->plan(generatorId: 'bad.origin', origin: 'custom'),
                'code' => 'GENERATE_PLAN_INVALID',
            ],
            [
                'plan' => $this->plan(generatorId: 'pack.noext', origin: 'pack', extension: ''),
                'code' => 'GENERATE_PLAN_INVALID',
            ],
            [
                'plan' => $this->plan(
                    generatorId: 'bad.type',
                    actions: [[
                        'type' => 'rename_file',
                        'path' => 'app/features/comments/feature.yaml',
                        'summary' => 'Rename file.',
                        'explain_node_id' => 'feature:comments',
                    ]],
                ),
                'code' => 'GENERATE_PLAN_INVALID',
            ],
            [
                'plan' => $this->plan(
                    generatorId: 'dup.actions',
                    actions: [
                        [
                            'type' => 'create_file',
                            'path' => 'app/features/comments/feature.yaml',
                            'summary' => 'Create file.',
                            'explain_node_id' => 'feature:comments',
                        ],
                        [
                            'type' => 'create_file',
                            'path' => 'app/features/comments/feature.yaml',
                            'summary' => 'Create file again.',
                            'explain_node_id' => 'feature:comments',
                        ],
                    ],
                ),
                'code' => 'GENERATE_PLAN_INVALID',
            ],
            [
                'plan' => $this->plan(
                    generatorId: 'missing.trace',
                    actions: [[
                        'type' => 'create_file',
                        'path' => 'app/features/comments/feature.yaml',
                        'summary' => 'Create file.',
                    ]],
                ),
                'code' => 'GENERATE_PLAN_INVALID',
            ],
            [
                'plan' => $this->plan(
                    generatorId: 'unsafe.delete',
                    actions: [[
                        'type' => 'delete_file',
                        'path' => 'app/features/comments/feature.yaml',
                        'summary' => 'Delete file.',
                        'explain_node_id' => 'feature:comments',
                    ]],
                ),
                'code' => 'GENERATE_UNSAFE_OPERATION',
            ],
        ];

        foreach ($cases as $case) {
            try {
                $validator->validate($case['plan'], $intent);
                self::fail('Expected validation failure.');
            } catch (FoundryError $error) {
                $this->assertSame($case['code'], $error->errorCode);
            }
        }
    }

    public function test_validator_allows_delete_when_risky_execution_is_explicitly_permitted(): void
    {
        $validator = new PlanValidator();
        $plan = $this->plan(
            generatorId: 'allowed.delete',
            actions: [[
                'type' => 'delete_file',
                'path' => 'app/features/comments/feature.yaml',
                'summary' => 'Delete file.',
                'explain_node_id' => 'feature:comments',
            ]],
        );

        $validator->validate($plan, new Intent(raw: 'Delete comments', mode: 'modify', allowRisky: true));
        $validator->validate($plan, new Intent(raw: 'Delete comments', mode: 'modify', interactive: true), true);

        $this->addToAssertionCount(1);
    }

    /**
     * @param array<int,array<string,mixed>> $actions
     * @param array<int,string> $affectedFiles
     * @param array<int,string> $risks
     * @param array<int,string> $validations
     * @param array<string,mixed> $metadata
     */
    private function plan(
        string $generatorId,
        string $origin = 'core',
        ?string $extension = null,
        array $actions = [],
        array $affectedFiles = [],
        array $risks = [],
        array $validations = ['compile_graph'],
        array $metadata = [],
    ): GenerationPlan {
        return new GenerationPlan(
            actions: $actions,
            affectedFiles: $affectedFiles,
            risks: $risks,
            validations: $validations,
            origin: $origin,
            generatorId: $generatorId,
            extension: $extension,
            metadata: $metadata,
        );
    }
}
