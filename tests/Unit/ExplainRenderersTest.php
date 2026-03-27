<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Explain\ExplanationPlan;
use Foundry\Explain\Renderers\JsonExplanationRenderer;
use Foundry\Explain\Renderers\MarkdownExplanationRenderer;
use Foundry\Explain\Renderers\TextExplanationRenderer;
use PHPUnit\Framework\TestCase;

final class ExplainRenderersTest extends TestCase
{
    public function test_renderers_expand_deep_plan_into_story_shaped_output(): void
    {
        $plan = new ExplanationPlan(
            subject: [
                'id' => 'thresholds.create',
                'kind' => 'route',
                'label' => 'thresholds.create',
            ],
            summary: [
                'text' => 'Creates a threshold and triggers downstream workflows.',
                'deterministic' => true,
                'deep' => true,
            ],
            responsibilities: [
                'items' => [
                    'Handle POST /thresholds requests',
                    'Dispatch the thresholds feature action',
                ],
            ],
            executionFlow: [
                'entries' => [
                    ['kind' => 'request', 'label' => 'request'],
                    ['kind' => 'guard', 'label' => 'auth guard', 'guard' => ['stage' => 'auth']],
                    ['kind' => 'guard', 'label' => 'permission guard (thresholds.create)', 'guard' => ['stage' => 'permissions', 'config' => ['permission' => 'thresholds.create']]],
                    ['kind' => 'action', 'label' => 'thresholds feature action', 'action' => ['feature' => 'thresholds', 'label' => 'thresholds']],
                    ['kind' => 'event', 'label' => 'threshold.created', 'name' => 'threshold.created'],
                    ['kind' => 'workflow', 'label' => 'streak.update', 'workflow' => ['resource' => 'streak.update']],
                    ['kind' => 'job', 'label' => 'notification.dispatch', 'job' => ['name' => 'notification.dispatch']],
                ],
                'stages' => [
                    ['kind' => 'pipeline_stage', 'label' => 'auth'],
                    ['kind' => 'pipeline_stage', 'label' => 'permissions'],
                    ['kind' => 'pipeline_stage', 'label' => 'action'],
                ],
                'guards' => [
                    ['id' => 'guard:auth', 'type' => 'authentication', 'stage' => 'auth'],
                    ['id' => 'guard:permission', 'type' => 'permission', 'stage' => 'permissions', 'config' => ['permission' => 'thresholds.create']],
                ],
                'action' => ['id' => 'feature:thresholds', 'kind' => 'feature', 'label' => 'thresholds', 'feature' => 'thresholds'],
                'events' => [
                    ['id' => 'event:threshold.created', 'kind' => 'event', 'label' => 'threshold.created'],
                ],
                'workflows' => [
                    ['id' => 'workflow:streak.update', 'kind' => 'workflow', 'label' => 'streak.update'],
                ],
                'jobs' => [
                    ['id' => 'job:notification.dispatch', 'kind' => 'job', 'label' => 'notification.dispatch'],
                ],
            ],
            dependencies: [
                'items' => [
                    ['kind' => 'feature', 'label' => 'account'],
                    ['kind' => 'schema', 'label' => 'threshold'],
                ],
            ],
            dependents: [
                'items' => [
                    ['kind' => 'route', 'label' => 'POST /thresholds'],
                    ['kind' => 'command', 'label' => 'thresholds:create'],
                ],
            ],
            emits: [
                'items' => [
                    ['kind' => 'event', 'label' => 'threshold.created'],
                ],
            ],
            triggers: [
                'items' => [
                    ['kind' => 'workflow', 'label' => 'streak.update'],
                    ['kind' => 'job', 'label' => 'notification.dispatch'],
                ],
            ],
            permissions: [
                'required' => ['thresholds.create'],
                'enforced_by' => [
                    ['guard' => 'guard:permission', 'stage' => 'permissions', 'permission' => 'thresholds.create'],
                ],
                'defined_in' => [
                    ['permission' => 'thresholds.create', 'source' => 'feature:thresholds'],
                ],
                'missing' => ['thresholds.create'],
            ],
            schemaInteraction: [
                'items' => [
                    ['kind' => 'schema', 'label' => 'app/features/thresholds/input.schema.json'],
                ],
                'reads' => [
                    ['kind' => 'schema', 'label' => 'threshold'],
                ],
                'writes' => [
                    ['kind' => 'schema', 'label' => 'threshold'],
                ],
                'fields' => [
                    ['name' => 'title', 'type' => 'string'],
                    ['name' => 'category', 'type' => 'string'],
                ],
                'subject' => [
                    'kind' => 'schema',
                    'path' => 'app/features/thresholds/input.schema.json',
                ],
            ],
            graphRelationships: [
                'inbound' => [
                    ['kind' => 'route', 'label' => 'POST /thresholds'],
                ],
                'outbound' => [
                    ['kind' => 'event', 'label' => 'threshold.created'],
                ],
                'lateral' => [
                    ['kind' => 'schema', 'label' => 'threshold'],
                ],
            ],
            diagnostics: [
                'summary' => ['error' => 1, 'warning' => 1, 'info' => 0, 'total' => 2],
                'items' => [
                    [
                        'severity' => 'error',
                        'message' => 'Missing permission mapping.',
                        'code' => 'FDY1001',
                        'suggested_fix' => 'Add thresholds.create to the permission map.',
                    ],
                    [
                        'severity' => 'warning',
                        'message' => 'Event emitted but not handled.',
                        'code' => 'FDY1002',
                        'suggested_fix' => 'Register a workflow or job for threshold.created.',
                    ],
                ],
            ],
            relatedCommands: [
                'foundry inspect pipeline --json',
                'foundry doctor',
            ],
            relatedDocs: [
                ['title' => 'Thresholds', 'path' => '/docs/features/thresholds'],
                ['title' => 'Workflow Notes'],
            ],
            suggestedFixes: [
                'Add thresholds.create to the permission map.',
                'Register a workflow or job for threshold.created.',
            ],
            sections: [
                [
                    'id' => 'impact',
                    'title' => 'Impact',
                    'items' => [
                        'risk' => 'medium',
                        'affected_features' => ['thresholds'],
                    ],
                ],
                [
                    'id' => 'contributor_notes',
                    'title' => 'Contributor Notes',
                    'items' => [
                        'source' => 'fixture',
                    ],
                ],
            ],
            sectionOrder: [
                'subject',
                'summary',
                'responsibilities',
                'execution_flow',
                'dependencies',
                'dependents',
                'emits',
                'triggers',
                'permissions',
                'schema_interaction',
                'graph_relationships',
                'related_commands',
                'related_docs',
                'diagnostics',
                'suggested_fixes',
                'impact',
                'contributor_notes',
            ],
            metadata: [
                'options' => [
                    'deep' => true,
                ],
            ],
        );

        $text = (new TextExplanationRenderer())->render($plan);
        $markdown = (new MarkdownExplanationRenderer())->render($plan);
        $json = (new JsonExplanationRenderer())->render($plan);

        $this->assertStringContainsString('Subject', $text);
        $this->assertStringContainsString('Execution Flow (Detailed)', $text);
        $this->assertStringContainsString('Responsibilities', $text);
        $this->assertStringContainsString('Depends On', $text);
        $this->assertStringContainsString('Used By', $text);
        $this->assertStringContainsString('Emits', $text);
        $this->assertStringContainsString('Triggers', $text);
        $this->assertStringContainsString('Permissions', $text);
        $this->assertStringContainsString('Schema Interaction', $text);
        $this->assertStringContainsString('Graph Relationships (Expanded)', $text);
        $this->assertStringContainsString('Related Commands', $text);
        $this->assertStringContainsString('Related Docs', $text);
        $this->assertStringContainsString('Diagnostics', $text);
        $this->assertStringContainsString('Suggested Fixes', $text);
        $this->assertStringContainsString('Impact', $text);
        $this->assertStringContainsString('Contributor Notes', $text);
        $this->assertStringContainsString('Stage 3: permission guard (thresholds.create)', $text);
        $this->assertStringContainsString('required: thresholds.create', $text);
        $this->assertStringContainsString('feature:account', $text);
        $this->assertStringContainsString('workflow:streak.update', $text);
        $this->assertStringContainsString('field: title (string)', $text);
        $this->assertStringContainsString('ERROR Missing permission mapping.', $text);
        $this->assertStringContainsString('affected_features: thresholds', $text);

        $this->assertStringContainsString('## thresholds.create', $markdown);
        $this->assertStringContainsString('### Summary', $markdown);
        $this->assertStringContainsString('### Responsibilities', $markdown);
        $this->assertStringContainsString('### Execution Flow (Detailed)', $markdown);
        $this->assertStringContainsString('### Dependencies', $markdown);
        $this->assertStringContainsString('### Used By', $markdown);
        $this->assertStringContainsString('### Emits', $markdown);
        $this->assertStringContainsString('### Triggers', $markdown);
        $this->assertStringContainsString('### Permissions', $markdown);
        $this->assertStringContainsString('### Schema Interaction', $markdown);
        $this->assertStringContainsString('### Graph Relationships', $markdown);
        $this->assertStringContainsString('### Related Commands', $markdown);
        $this->assertStringContainsString('### Related Docs', $markdown);
        $this->assertStringContainsString('### Diagnostics', $markdown);
        $this->assertStringContainsString('### Suggested Fixes', $markdown);
        $this->assertStringContainsString('### Impact', $markdown);
        $this->assertStringContainsString('### Contributor Notes', $markdown);
        $this->assertStringContainsString('- /docs/features/thresholds', $markdown);
        $this->assertStringContainsString('- ERROR: Missing permission mapping.', $markdown);
        $this->assertStringContainsString('"executionFlow"', $json);
        $this->assertStringContainsString('"relationships"', $json);
        $this->assertStringContainsString('"relatedCommands"', $json);
        $this->assertStringContainsString('"relatedDocs"', $json);
        $this->assertStringContainsString('"suggestedFixes"', $json);
    }

    public function test_renderers_handle_minimal_non_deep_plan(): void
    {
        $plan = new ExplanationPlan(
            subject: [
                'id' => 'command:doctor',
                'kind' => 'command',
                'label' => 'doctor',
            ],
            summary: [
                'text' => 'Doctor inspects graph health.',
                'deterministic' => true,
                'deep' => false,
            ],
            responsibilities: [
                'items' => [],
            ],
            executionFlow: [
                'entries' => [
                    ['label' => 'load graph'],
                    ['label' => 'run diagnostics'],
                ],
                'stages' => [],
                'guards' => [],
                'action' => null,
                'events' => [],
                'workflows' => [],
                'jobs' => [],
            ],
            dependencies: ['items' => []],
            dependents: ['items' => []],
            emits: ['items' => []],
            triggers: ['items' => []],
            permissions: [
                'required' => [],
                'enforced_by' => [],
                'defined_in' => [],
                'missing' => [],
            ],
            schemaInteraction: [
                'items' => [],
                'reads' => [],
                'writes' => [],
                'fields' => [],
                'subject' => null,
            ],
            graphRelationships: [
                'inbound' => [],
                'outbound' => [],
                'lateral' => [],
            ],
            diagnostics: [
                'summary' => ['error' => 0, 'warning' => 0, 'info' => 0, 'total' => 0],
                'items' => [],
            ],
            relatedCommands: [],
            relatedDocs: [],
            suggestedFixes: [],
            sections: [
                [
                    'id' => 'impact',
                    'title' => 'Impact',
                    'items' => [
                        'risk' => 'low',
                        'affected_features' => ['publish_post'],
                    ],
                ],
                [
                    'id' => 'contributor_notes',
                    'title' => 'Contributor Notes',
                    'items' => [
                        'source' => 'fixture',
                    ],
                ],
            ],
            sectionOrder: [
                'subject',
                'summary',
                'execution_flow',
                'diagnostics',
                'impact',
                'contributor_notes',
            ],
            metadata: [
                'options' => [
                    'deep' => false,
                ],
            ],
        );

        $text = (new TextExplanationRenderer())->render($plan);
        $markdown = (new MarkdownExplanationRenderer())->render($plan);

        $this->assertStringContainsString("  load graph\n  -> run diagnostics", $text);
        $this->assertStringContainsString('Impact', $text);
        $this->assertStringContainsString('affected_features: publish_post', $text);
        $this->assertStringContainsString('Contributor Notes', $text);
        $this->assertStringContainsString('OK No issues detected', $text);
        $this->assertStringNotContainsString('Graph Relationships (Expanded)', $text);

        $this->assertStringContainsString('- load graph', $markdown);
        $this->assertStringContainsString('- run diagnostics', $markdown);
        $this->assertStringContainsString('### Impact', $markdown);
        $this->assertStringContainsString('### Contributor Notes', $markdown);
        $this->assertStringContainsString("### Diagnostics\nNo issues detected.", $markdown);
        $this->assertStringNotContainsString('### Related Docs', $markdown);
    }
}
