<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Confidence\ConfidenceEngine;
use Foundry\Explain\ExplainModel;
use Foundry\Generate\GenerationContextPacket;
use Foundry\Generate\GenerationPlan;
use Foundry\Generate\Intent;
use PHPUnit\Framework\TestCase;

final class ConfidenceEngineTest extends TestCase
{
    public function test_band_thresholds_are_stable(): void
    {
        $engine = new ConfidenceEngine();

        $this->assertSame('very_high', $engine->bandForScore(0.95));
        $this->assertSame('high', $engine->bandForScore(0.82));
        $this->assertSame('medium', $engine->bandForScore(0.65));
        $this->assertSame('low', $engine->bandForScore(0.40));
        $this->assertSame('very_low', $engine->bandForScore(0.39));
    }

    public function test_explain_confidence_is_deterministic_for_same_model(): void
    {
        $engine = new ConfidenceEngine();
        $model = $this->featureModel();

        $first = $engine->explain($model);
        $second = $engine->explain($model);

        $this->assertSame($first, $second);
        $this->assertArrayHasKey('factors', $first);
        $this->assertArrayHasKey('warnings', $first);
    }

    public function test_explain_confidence_drops_when_evidence_is_sparse(): void
    {
        $engine = new ConfidenceEngine();
        $confidence = $engine->explain($this->systemRootModel());

        $this->assertContains($confidence['band'], ['medium', 'low']);
        $this->assertNotEmpty($confidence['warnings']);
    }

    public function test_plan_confidence_drops_for_large_risky_plan(): void
    {
        $engine = new ConfidenceEngine();
        $context = new GenerationContextPacket(
            intent: new Intent(
                raw: 'Refactor everything',
                mode: 'modify',
                target: 'feature:publish_post',
                allowRisky: true,
            ),
            model: $this->systemRootModel()->withConfidence($engine->explain($this->systemRootModel())),
            targets: [[
                'requested' => 'publish_post',
                'resolved' => 'feature:publish_post',
                'subject' => ['id' => 'feature:publish_post', 'kind' => 'feature', 'origin' => 'core', 'extension' => null],
            ]],
            graphRelationships: ['inbound' => [], 'outbound' => [], 'lateral' => []],
            constraints: ['Keep changes deterministic.'],
            docs: [],
            validationSteps: ['compile_graph', 'verify_graph', 'verify_contracts'],
            availableGenerators: [['id' => 'core.feature.modify']],
            installedPacks: [],
            missingCapabilities: [],
            suggestedPacks: [],
        );
        $plan = new GenerationPlan(
            actions: [
                ['type' => 'update_file', 'path' => 'app/features/a/feature.yaml', 'explain_node_id' => 'feature:a', 'origin' => 'core', 'extension' => null],
                ['type' => 'update_file', 'path' => 'app/features/b/feature.yaml', 'explain_node_id' => 'feature:b', 'origin' => 'core', 'extension' => null],
                ['type' => 'update_file', 'path' => 'app/features/c/feature.yaml', 'explain_node_id' => 'feature:c', 'origin' => 'core', 'extension' => null],
                ['type' => 'update_docs', 'path' => 'docs/architecture-tools.md', 'explain_node_id' => 'doc:architecture-tools', 'origin' => 'core', 'extension' => null],
                ['type' => 'update_graph', 'path' => '.foundry/build/graph.json', 'explain_node_id' => 'graph:root', 'origin' => 'core', 'extension' => null],
            ],
            affectedFiles: [
                'app/features/a/feature.yaml',
                'app/features/b/feature.yaml',
                'app/features/c/feature.yaml',
                'docs/architecture-tools.md',
                '.foundry/build/graph.json',
            ],
            risks: ['Touches multiple subsystems.', 'Requires risky override.'],
            validations: ['compile_graph'],
            origin: 'core',
            generatorId: 'core.feature.modify',
            metadata: ['feature' => 'publish_post'],
        );

        $confidence = $engine->plan($context, $plan);

        $this->assertContains($confidence['band'], ['low', 'medium']);
        $this->assertNotEmpty($confidence['warnings']);
    }

    public function test_outcome_confidence_is_high_for_fully_verified_change(): void
    {
        $engine = new ConfidenceEngine();
        $model = $this->featureModel();
        $context = new GenerationContextPacket(
            intent: new Intent(raw: 'Add comments', mode: 'new'),
            model: $model->withConfidence($engine->explain($model)),
            targets: [[
                'requested' => null,
                'resolved' => 'feature:publish_post',
                'subject' => ['id' => 'feature:publish_post', 'kind' => 'feature', 'origin' => 'core', 'extension' => null],
            ]],
            graphRelationships: ['inbound' => [['id' => 'feature:publish_post']], 'outbound' => [], 'lateral' => []],
            constraints: ['Stay deterministic.'],
            docs: [['id' => 'docs:generate']],
            validationSteps: ['compile_graph', 'doctor', 'verify_graph', 'verify_contracts'],
            availableGenerators: [['id' => 'core.feature.new']],
            installedPacks: [],
            missingCapabilities: [],
            suggestedPacks: [],
        );
        $plan = new GenerationPlan(
            actions: [[
                'type' => 'create_file',
                'path' => 'app/features/comment_posts/feature.yaml',
                'explain_node_id' => 'feature:publish_post',
                'origin' => 'core',
                'extension' => null,
            ]],
            affectedFiles: ['app/features/comment_posts/feature.yaml'],
            risks: [],
            validations: ['compile_graph', 'verify_graph', 'verify_contracts', 'verify_feature'],
            origin: 'core',
            generatorId: 'core.feature.new',
            metadata: ['feature' => 'comment_posts'],
        );
        $plan = $plan->withConfidence($engine->plan($context, $plan));

        $confidence = $engine->outcome(
            new Intent(raw: 'Add comments', mode: 'new'),
            $plan,
            [[
                'type' => 'create_file',
                'path' => 'app/features/comment_posts/feature.yaml',
                'status' => 'written',
            ]],
            [
                'compile_graph' => ['status' => 0],
                'doctor' => ['status' => 0],
                'verify_graph' => ['status' => 0],
                'verify_contracts' => ['status' => 0],
                'verify_feature' => ['status' => 0],
                'ok' => true,
            ],
            [
                'summary' => ['added' => 2, 'removed' => 0, 'modified' => 1],
                'added' => [['type' => 'schema', 'id' => 'comment']],
                'removed' => [],
                'modified' => [['type' => 'route', 'id' => 'POST /comments']],
            ],
        );

        $this->assertContains($confidence['band'], ['high', 'very_high']);
        $this->assertGreaterThanOrEqual(0.82, $confidence['score']);
    }

    private function featureModel(): ExplainModel
    {
        return new ExplainModel(
            subject: [
                'id' => 'feature:publish_post',
                'kind' => 'feature',
                'label' => 'publish_post',
                'origin' => 'core',
                'extension' => null,
            ],
            graph: [
                'node_ids' => ['feature:publish_post', 'route:POST /posts'],
                'subject_node' => ['id' => 'feature:publish_post'],
                'neighbors' => [
                    'inbound' => [['id' => 'schema:post_input']],
                    'outbound' => [['id' => 'route:POST /posts']],
                    'lateral' => [],
                ],
            ],
            execution: [
                'entries' => [['stage' => 'auth'], ['stage' => 'action']],
                'stages' => [['id' => 'auth'], ['id' => 'action']],
                'action' => ['feature' => 'publish_post'],
                'workflows' => [],
                'jobs' => [['id' => 'job:notify_followers']],
            ],
            guards: ['items' => [['id' => 'guard:posts.create']]],
            events: ['emits' => [['id' => 'event:post.created']], 'subscriptions' => [], 'emitters' => [], 'subscribers' => []],
            schemas: ['subject' => ['id' => 'schema:post'], 'items' => [['id' => 'schema:post']], 'reads' => [], 'writes' => [], 'fields' => []],
            relationships: ['dependsOn' => ['items' => [['id' => 'schema:post']]], 'usedBy' => ['items' => []], 'graph' => ['inbound' => [['id' => 'schema:post']], 'outbound' => [['id' => 'route:POST /posts']], 'lateral' => []]],
            diagnostics: ['summary' => ['error' => 0, 'warning' => 0, 'info' => 1, 'total' => 1], 'items' => []],
            docs: ['related' => [['id' => 'docs:architecture-tools']]],
            impact: ['features' => ['publish_post']],
            commands: ['subject' => ['id' => 'command:explain'], 'related' => [['id' => 'command:explain', 'signature' => 'explain']]],
            metadata: ['target' => ['selector' => 'publish_post']],
            extensions: [],
        );
    }

    private function systemRootModel(): ExplainModel
    {
        return new ExplainModel(
            subject: [
                'id' => 'system:root',
                'kind' => 'system',
                'label' => 'system',
                'origin' => 'core',
                'extension' => null,
            ],
            graph: ['node_ids' => [], 'subject_node' => null, 'neighbors' => ['inbound' => [], 'outbound' => [], 'lateral' => []]],
            execution: ['entries' => [], 'stages' => [], 'action' => null, 'workflows' => [], 'jobs' => []],
            guards: ['items' => []],
            events: ['emits' => [], 'subscriptions' => [], 'emitters' => [], 'subscribers' => []],
            schemas: ['subject' => null, 'items' => [], 'reads' => [], 'writes' => [], 'fields' => []],
            relationships: ['dependsOn' => ['items' => []], 'usedBy' => ['items' => []], 'graph' => ['inbound' => [], 'outbound' => [], 'lateral' => []]],
            diagnostics: ['summary' => ['error' => 0, 'warning' => 0, 'info' => 0, 'total' => 0], 'items' => []],
            docs: ['related' => []],
            impact: [],
            commands: ['subject' => null, 'related' => []],
            metadata: ['target' => ['selector' => 'system:root']],
            extensions: [],
        );
    }
}
