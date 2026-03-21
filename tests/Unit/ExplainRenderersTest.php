<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Explain\ExplanationPlan;
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
                'kind' => 'route_action',
                'label' => 'thresholds.create',
            ],
            summary: [
                'text' => 'Creates a threshold and triggers downstream workflows.',
                'deterministic' => true,
                'deep' => true,
            ],
            sections: [
                [
                    'id' => 'contracts',
                    'title' => 'Contracts',
                    'items' => [
                        'description' => 'Create threshold records.',
                        'route' => ['method' => 'POST', 'path' => '/thresholds'],
                        'input_schema' => ['path' => 'app/features/thresholds/input.schema.json'],
                        'output_schema' => ['schema' => 'app/features/thresholds/output.schema.json'],
                        'permissions' => ['thresholds.create'],
                    ],
                ],
                [
                    'id' => 'route',
                    'title' => 'Route',
                    'items' => [
                        'signature' => 'POST /thresholds',
                        'feature' => 'thresholds',
                        'schemas' => [
                            'input' => 'app/features/thresholds/input.schema.json',
                            'output' => 'app/features/thresholds/output.schema.json',
                        ],
                    ],
                ],
                [
                    'id' => 'workflow',
                    'title' => 'Workflow',
                    'items' => [
                        'states' => ['draft', 'active'],
                        'transitions' => [
                            'promote' => [
                                'emit' => ['threshold.promoted'],
                            ],
                        ],
                    ],
                ],
                [
                    'id' => 'event',
                    'title' => 'Event',
                    'items' => [
                        'emitters' => ['thresholds'],
                        'subscribers' => ['streak.update'],
                        'workflows' => [
                            ['resource' => 'streak.update'],
                        ],
                    ],
                ],
                [
                    'id' => 'extension',
                    'title' => 'Extension',
                    'items' => [
                        'version' => '1.0.0',
                        'description' => 'Adds auth-aware explain notes.',
                        'packs' => ['auth.pack'],
                        'provides' => [
                            'capabilities' => ['auth.permissions'],
                        ],
                    ],
                ],
                [
                    'id' => 'command',
                    'title' => 'Command',
                    'items' => [
                        'usage' => 'explain <target>',
                        'stability' => 'experimental',
                        'availability' => 'pro',
                        'classification' => 'extension_api',
                    ],
                ],
                [
                    'id' => 'job',
                    'title' => 'Job',
                    'items' => [
                        'features' => ['thresholds'],
                        'definitions' => [
                            'thresholds' => ['queue' => 'default'],
                        ],
                    ],
                ],
                [
                    'id' => 'schema',
                    'title' => 'Schema',
                    'items' => [
                        'path' => 'app/features/thresholds/input.schema.json',
                        'role' => 'input',
                        'feature' => 'thresholds',
                        'document' => [
                            'properties' => [
                                'title' => ['type' => 'string'],
                                'category' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
                [
                    'id' => 'pipeline_stage',
                    'title' => 'Pipeline Stage',
                    'items' => [
                        'order' => ['request', 'auth', 'permissions', 'action'],
                    ],
                ],
                [
                    'id' => 'impact',
                    'title' => 'Impact',
                    'items' => [
                        'risk' => 'medium',
                        'affected_features' => ['thresholds'],
                        'affected_routes' => ['POST /thresholds'],
                        'affected_events' => ['threshold.created'],
                        'affected_jobs' => ['notification.dispatch'],
                        'affected_projections' => ['event_index.php'],
                    ],
                ],
                [
                    'id' => 'notes',
                    'title' => 'Notes',
                    'items' => [
                        'owner' => 'core',
                        'flags' => ['deterministic', 'graph-derived'],
                        'links' => [
                            ['label' => 'Threshold docs'],
                            ['name' => 'Workflow docs'],
                        ],
                    ],
                ],
            ],
            relationships: [
                'depends_on' => [
                    ['kind' => 'feature', 'label' => 'account', 'edge_type' => 'feature_dependency'],
                    ['kind' => 'schema', 'label' => 'threshold', 'edge_type' => 'feature_to_input_schema'],
                ],
                'depended_on_by' => [
                    ['kind' => 'route', 'label' => 'POST /thresholds', 'edge_type' => 'route_to_feature'],
                    ['kind' => 'execution_plan', 'label' => 'execution_plan:thresholds', 'edge_type' => 'route_to_execution_plan'],
                ],
                'neighbors' => [
                    ['kind' => 'feature', 'label' => 'account', 'edge_type' => 'feature_dependency'],
                    ['kind' => 'schema', 'label' => 'threshold', 'edge_type' => 'feature_to_input_schema'],
                    ['kind' => 'extension', 'label' => 'auth.explain', 'edge_type' => 'extension_support'],
                ],
            ],
            executionFlow: [
                'route' => 'POST /thresholds',
                'guards' => [
                    ['type' => 'auth', 'stage' => 'auth', 'strategy' => 'session'],
                    ['type' => 'permission', 'stage' => 'permissions', 'permission' => 'thresholds.create'],
                ],
                'stages' => ['request normalization', 'auth', 'permissions', 'action'],
                'pipeline' => ['feature' => 'thresholds'],
                'events' => [
                    ['name' => 'threshold.created'],
                ],
                'workflows' => [
                    ['resource' => 'streak.update'],
                ],
                'jobs' => [
                    ['name' => 'notification.dispatch'],
                ],
            ],
            diagnostics: [
                'summary' => ['error' => 1, 'warning' => 1, 'info' => 0, 'total' => 2],
                'items' => [
                    [
                        'severity' => 'error',
                        'message' => 'Missing permission mapping.',
                        'code' => 'FDY1001',
                        'why_it_matters' => 'Requests will fail authorization.',
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
                'php vendor/bin/foundry inspect pipeline --json',
                'php vendor/bin/foundry doctor --json',
            ],
            relatedDocs: [
                ['title' => 'Thresholds', 'path' => '/docs/features/thresholds'],
                ['title' => 'Workflow Notes'],
            ],
            metadata: [
                'options' => [
                    'deep' => true,
                ],
            ],
        );

        $text = (new TextExplanationRenderer())->render($plan);
        $markdown = (new MarkdownExplanationRenderer())->render($plan);

        $this->assertStringContainsString('Subject', $text);
        $this->assertStringContainsString('Execution Flow (Detailed)', $text);
        $this->assertStringContainsString('Responsibilities', $text);
        $this->assertStringContainsString('Route', $text);
        $this->assertStringContainsString('Logic', $text);
        $this->assertStringContainsString('Event', $text);
        $this->assertStringContainsString('Provides', $text);
        $this->assertStringContainsString('Command', $text);
        $this->assertStringContainsString('Job', $text);
        $this->assertStringContainsString('Schema Interaction', $text);
        $this->assertStringContainsString('Pipeline Stage', $text);
        $this->assertStringContainsString('Impact', $text);
        $this->assertStringContainsString('Notes', $text);
        $this->assertStringContainsString('Depends On', $text);
        $this->assertStringContainsString('Used By', $text);
        $this->assertStringContainsString('Emits', $text);
        $this->assertStringContainsString('Triggers', $text);
        $this->assertStringContainsString('Graph Relationships (Expanded)', $text);
        $this->assertStringContainsString('Diagnostics', $text);
        $this->assertStringContainsString('Suggested Fixes', $text);
        $this->assertStringContainsString('Related Commands', $text);
        $this->assertStringContainsString('Related Docs', $text);
        $this->assertStringContainsString('input schema: app/features/thresholds/input.schema.json', $text);
        $this->assertStringContainsString('output schema: app/features/thresholds/output.schema.json', $text);
        $this->assertStringContainsString('capabilities: auth.permissions', $text);
        $this->assertStringContainsString('affected features: thresholds', $text);
        $this->assertStringContainsString('links: Threshold docs, Workflow docs', $text);

        $this->assertStringContainsString('## thresholds.create', $markdown);
        $this->assertStringContainsString('### Execution Flow (Detailed)', $markdown);
        $this->assertStringContainsString('### Responsibilities', $markdown);
        $this->assertStringContainsString('### Route', $markdown);
        $this->assertStringContainsString('### Logic', $markdown);
        $this->assertStringContainsString('### Event', $markdown);
        $this->assertStringContainsString('### Provides', $markdown);
        $this->assertStringContainsString('### Command', $markdown);
        $this->assertStringContainsString('### Job', $markdown);
        $this->assertStringContainsString('### Schema Interaction', $markdown);
        $this->assertStringContainsString('### Pipeline Stage', $markdown);
        $this->assertStringContainsString('### Impact', $markdown);
        $this->assertStringContainsString('### Graph Relationships', $markdown);
        $this->assertStringContainsString('### Diagnostics', $markdown);
        $this->assertStringContainsString('### Suggested Fixes', $markdown);
        $this->assertStringContainsString('### Related Commands', $markdown);
        $this->assertStringContainsString('### Related Docs', $markdown);
        $this->assertStringContainsString('[Thresholds](/docs/features/thresholds)', $markdown);
        $this->assertStringContainsString('- WARNING: Event emitted but not handled.', $markdown);
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
            relationships: [
                'depends_on' => [],
                'depended_on_by' => [],
                'neighbors' => [],
            ],
            executionFlow: [
                'steps' => ['load graph', 'run diagnostics'],
            ],
            diagnostics: [
                'summary' => ['error' => 0, 'warning' => 0, 'info' => 0, 'total' => 0],
                'items' => [],
            ],
            relatedCommands: [],
            relatedDocs: [],
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
        $this->assertStringContainsString('affected features: publish_post', $text);
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
