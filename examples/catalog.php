<?php
declare(strict_types=1);

return [
    'official' => [
        [
            'slug' => 'hello-world',
            'title' => 'Hello World',
            'path' => 'examples/hello-world',
            'kind' => 'focused-app',
            'teaches' => [
                'feature structure',
                'schemas and context manifests',
                'doctor basics',
                'graph and pipeline inspection',
            ],
        ],
        [
            'slug' => 'blog-api',
            'title' => 'API-First',
            'path' => 'examples/blog-api',
            'kind' => 'focused-app',
            'teaches' => [
                'resource-style HTTP features',
                'auth and permissions',
                'graph inspection by command',
                'contract and graph verification',
            ],
        ],
        [
            'slug' => 'extensions-migrations',
            'title' => 'Extension Example',
            'path' => 'examples/extensions-migrations',
            'kind' => 'framework-example',
            'teaches' => [
                'extension registration',
                'pack metadata',
                'migrations',
                'codemod usage',
            ],
        ],
        [
            'slug' => 'workflow-events',
            'title' => 'Workflow And Events',
            'path' => 'examples/workflow-events',
            'kind' => 'focused-app',
            'teaches' => [
                'event emit and subscribe edges',
                'workflow definitions',
                'graph inspection by event and workflow',
                'doctor and verify loops',
            ],
        ],
        [
            'slug' => 'reference-blog',
            'title' => 'Reference Blog',
            'path' => 'examples/reference-blog',
            'kind' => 'reference-kit',
            'teaches' => [
                'full blog planning',
                'admin login flow',
                'RSS integration with spatie/laravel-feed',
                'copy-paste commands, prompts, and starter content',
            ],
        ],
    ],
    'supplemental' => [
        [
            'slug' => 'dashboard',
            'title' => 'Dashboard',
            'path' => 'examples/dashboard',
            'kind' => 'focused-app',
            'teaches' => [
                'authenticated endpoints',
                'profile-style routes',
                'media upload flows',
            ],
        ],
        [
            'slug' => 'ai-pipeline',
            'title' => 'AI Pipeline',
            'path' => 'examples/ai-pipeline',
            'kind' => 'focused-app',
            'teaches' => [
                'AI-oriented feature grouping',
                'multi-step application slices',
                'prompt and schema organization',
            ],
        ],
        [
            'slug' => 'compiler-core',
            'title' => 'Compiler Core',
            'path' => 'examples/compiler-core',
            'kind' => 'framework-example',
            'teaches' => [
                'compile outputs',
                'impact analysis',
                'migration flow',
            ],
        ],
        [
            'slug' => 'architecture-tools',
            'title' => 'Architecture Tools',
            'path' => 'examples/architecture-tools',
            'kind' => 'framework-example',
            'teaches' => [
                'doctor',
                'graph visualize',
                'prompt context',
            ],
        ],
        [
            'slug' => 'execution-pipeline',
            'title' => 'Execution Pipeline',
            'path' => 'examples/execution-pipeline',
            'kind' => 'framework-example',
            'teaches' => [
                'pipeline topology',
                'execution-plan inspection',
            ],
        ],
        [
            'slug' => 'app-scaffolding',
            'title' => 'App Scaffolding',
            'path' => 'examples/app-scaffolding',
            'kind' => 'framework-example',
            'teaches' => [
                'starter generation',
                'resource definitions',
                'admin and upload scaffolding',
            ],
        ],
        [
            'slug' => 'integration-tooling',
            'title' => 'Integration Tooling',
            'path' => 'examples/integration-tooling',
            'kind' => 'framework-example',
            'teaches' => [
                'notifications',
                'API resources',
                'generated docs',
                'test generation notes',
            ],
        ],
    ],
    'thresholds' => [
        'title' => 'Thresholds',
        'position' => 'real-app-reference',
        'summary' => 'Thresholds is the richer end-to-end reference application. Use the smaller examples to learn one idea at a time, then compare against Thresholds for a production-shaped app.',
    ],
];
