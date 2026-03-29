<?php

declare(strict_types=1);

return [
    'canonical' => [
        [
            'slug' => 'hello-world',
            'title' => 'Hello World',
            'path' => 'examples/hello-world',
            'kind' => 'canonical',
            'teaches' => [
                'feature structure',
                'schemas and context manifests',
                'doctor basics',
                'graph and pipeline inspection',
            ],
        ],
        [
            'slug' => 'blog-api',
            'title' => 'Blog API',
            'path' => 'examples/blog-api',
            'kind' => 'canonical',
            'teaches' => [
                'route-per-feature HTTP design',
                'public versus protected endpoints',
                'route-param inspection',
                'event-backed write flows',
            ],
        ],
        [
            'slug' => 'workflow-events',
            'title' => 'Workflow And Events',
            'path' => 'examples/workflow-events',
            'kind' => 'canonical',
            'teaches' => [
                'event emit and subscribe edges',
                'workflow definitions',
                'graph inspection by event and workflow',
                'doctor and verify loops',
            ],
        ],
    ],
    'reference' => [
        [
            'slug' => 'extensions-migrations',
            'title' => 'Extensions And Migrations',
            'path' => 'examples/extensions-migrations',
            'kind' => 'reference',
            'teaches' => [
                'extension registration',
                'pack metadata',
                'definition migrations',
                'codemod dry runs',
            ],
        ],
        [
            'slug' => 'reference-blog',
            'title' => 'Reference Blog',
            'path' => 'examples/reference-blog',
            'kind' => 'reference',
            'teaches' => [
                'full blog planning',
                'admin login flow',
                'RSS integration with spatie/laravel-feed',
                'copy-paste commands, prompts, and starter content',
            ],
        ],
    ],
    'framework' => [
        [
            'slug' => 'compiler-core',
            'title' => 'Compiler Core',
            'path' => 'examples/compiler-core',
            'kind' => 'framework',
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
            'kind' => 'framework',
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
            'kind' => 'framework',
            'teaches' => [
                'pipeline topology',
                'execution-plan inspection',
            ],
        ],
        [
            'slug' => 'app-scaffolding',
            'title' => 'App Scaffolding',
            'path' => 'examples/app-scaffolding',
            'kind' => 'framework',
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
            'kind' => 'framework',
            'teaches' => [
                'notifications',
                'API resources',
                'generated docs',
                'test generation notes',
            ],
        ],
    ],
];
