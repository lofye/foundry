<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Compiler\CompilationState;
use Foundry\Compiler\Diagnostics\DiagnosticBag;
use Foundry\Compiler\Extensions\AbstractCompilerExtension;
use Foundry\Compiler\Extensions\ExtensionDescriptor;
use Foundry\Compiler\Extensions\ExtensionRegistry;
use Foundry\Compiler\Extensions\PackDefinition;
use Foundry\Config\ConfigValidator;
use Foundry\Pipeline\PipelineExecutionState;
use Foundry\Pipeline\PipelineStageDefinition;
use Foundry\Pipeline\StageInterceptor;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class ConfigValidatorTest extends TestCase
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

    public function test_validate_project_accepts_legacy_aliases_with_upgrade_warnings(): void
    {
        mkdir($this->project->root . '/config', 0777, true);

        file_put_contents($this->project->root . '/config/database.php', <<<'PHP'
<?php
declare(strict_types=1);

return [
    'default' => 'sqlite',
    'sqlite' => [
        'dsn' => 'sqlite::memory:',
    ],
];
PHP);
        file_put_contents($this->project->root . '/config/storage.php', <<<'PHP'
<?php
declare(strict_types=1);

return [
    'default' => 'local',
    'local_root' => 'storage/files',
];
PHP);
        file_put_contents($this->project->root . '/config/ai.php', <<<'PHP'
<?php
declare(strict_types=1);

return [
    'default_provider' => 'static',
];
PHP);

        $report = (new ConfigValidator())->validateProject(Paths::fromCwd($this->project->root));

        $this->assertFalse($report->hasErrors());
        $this->assertSame(0, $report->summary()['error']);
        $this->assertGreaterThanOrEqual(3, $report->summary()['warning']);

        $messages = array_values(array_map(
            static fn($item): string => $item->message,
            $report->items,
        ));

        $this->assertTrue($this->containsMessage($messages, 'normalized into $.connections.sqlite'));
        $this->assertTrue($this->containsMessage($messages, 'normalized into $.root'));
        $this->assertTrue($this->containsMessage($messages, 'normalized into $.default'));
    }

    public function test_validate_project_reports_load_root_schema_and_cross_field_issues(): void
    {
        mkdir($this->project->root . '/config', 0777, true);

        file_put_contents($this->project->root . '/config/cache.php', <<<'PHP'
<?php
declare(strict_types=1);

return 'invalid';
PHP);
        file_put_contents($this->project->root . '/config/queue.php', <<<'PHP'
<?php
declare(strict_types=1);

throw new RuntimeException('boom');
PHP);
        file_put_contents($this->project->root . '/config/database.php', <<<'PHP'
<?php
declare(strict_types=1);

return [
    'default' => 'ghost',
    'connections' => [
        'sqlite' => [
            'dsn' => 'sqlite::memory:',
        ],
    ],
];
PHP);

        $extensions = new ExtensionRegistry([$this->extensionFixture()]);
        $report = (new ConfigValidator())->validateProject(
            Paths::fromCwd($this->project->root),
            discoveredFeatures: [
                'demo' => [
                    'manifest_path' => 'app/features/demo/feature.yaml',
                    'manifest' => [
                        'route' => [
                            'method' => 5,
                            'path' => 'posts',
                        ],
                    ],
                ],
            ],
            discoveredDefinitions: [
                'search_index' => [
                    'posts' => [
                        'path' => 'app/definitions/search/posts.search.yaml',
                        'document' => [
                            'index' => 'posts',
                        ],
                    ],
                ],
            ],
            extensions: $extensions,
        );

        $codes = array_values(array_map(
            static fn($item): string => $item->code,
            $report->items,
        ));
        sort($codes);

        $this->assertContains('FDY1701_CONFIG_FILE_LOAD_FAILED', $codes);
        $this->assertContains('FDY1702_CONFIG_RETURN_TYPE_INVALID', $codes);
        $this->assertContains('FDY1703_CONFIG_SCHEMA_VIOLATION', $codes);
        $this->assertContains('FDY1705_CONFIG_CROSS_FIELD_INVALID', $codes);
        $this->assertContains('config/database.php', $report->toArray()['validated_sources']);
        $this->assertContains('app/features/demo/feature.yaml', $report->toArray()['validated_sources']);
        $this->assertContains('app/definitions/search/posts.search.yaml', $report->toArray()['validated_sources']);
    }

    public function test_validate_project_reports_cross_field_errors_for_auth_cache_queue_and_ai(): void
    {
        mkdir($this->project->root . '/config', 0777, true);

        file_put_contents($this->project->root . '/config/auth.php', <<<'PHP'
<?php
declare(strict_types=1);

return [
    'default' => 'session',
    'strategies' => [
        'bearer' => [
            'header' => 'Authorization',
        ],
    ],
];
PHP);
        file_put_contents($this->project->root . '/config/cache.php', <<<'PHP'
<?php
declare(strict_types=1);

return [
    'default' => 'redis',
    'stores' => [
        'array' => [],
    ],
];
PHP);
        file_put_contents($this->project->root . '/config/queue.php', <<<'PHP'
<?php
declare(strict_types=1);

return [
    'default' => 'redis',
    'drivers' => [
        'sync' => [],
    ],
];
PHP);
        file_put_contents($this->project->root . '/config/ai.php', <<<'PHP'
<?php
declare(strict_types=1);

return [
    'default' => 'openai',
    'providers' => [
        'static' => [
            'driver' => 'static',
            'model' => 'fixture',
        ],
    ],
];
PHP);

        $report = (new ConfigValidator())->validateProject(Paths::fromCwd($this->project->root));
        $messages = array_values(array_map(
            static fn($item): string => $item->message,
            $report->items,
        ));

        $this->assertTrue($report->hasErrors());
        $this->assertTrue($this->containsMessage($messages, 'Auth default strategy session is not configured under $.strategies.'));
        $this->assertTrue($this->containsMessage($messages, 'Cache default store redis is not configured under $.stores.'));
        $this->assertTrue($this->containsMessage($messages, 'Queue default driver redis is not configured under $.drivers.'));
        $this->assertTrue($this->containsMessage($messages, 'AI default provider openai is not configured under $.providers.'));
    }

    private function extensionFixture(): AbstractCompilerExtension
    {
        return new class extends AbstractCompilerExtension {
            public function name(): string
            {
                return 'fixture-ext';
            }

            public function version(): string
            {
                return '1.0.0';
            }

            public function descriptor(): ExtensionDescriptor
            {
                return new ExtensionDescriptor(
                    name: $this->name(),
                    version: $this->version(),
                    frameworkVersionConstraint: '^1',
                    graphVersionConstraint: '^1',
                    providedNodeTypes: ['custom_node'],
                    providedProjectionOutputs: ['fixture.php'],
                );
            }

            public function packs(): array
            {
                return [
                    new PackDefinition(
                        name: 'fixture/pack',
                        version: '1.0.0',
                        extension: $this->name(),
                        providedCapabilities: ['fixture.run'],
                        requiredCapabilities: [],
                        frameworkVersionConstraint: '^1',
                        graphVersionConstraint: '^1',
                    ),
                ];
            }

            public function pipelineStages(): array
            {
                return [new PipelineStageDefinition('fixture_stage', priority: 42)];
            }

            public function pipelineInterceptors(): array
            {
                return [new class implements StageInterceptor {
                    public function id(): string
                    {
                        return 'fixture.interceptor';
                    }

                    public function stage(): string
                    {
                        return 'auth';
                    }

                    public function priority(): int
                    {
                        return 5;
                    }

                    public function handle(PipelineExecutionState $state): void {}

                    public function isDangerous(): bool
                    {
                        return false;
                    }
                }];
            }
        };
    }

    /**
     * @param array<int,string> $messages
     */
    private function containsMessage(array $messages, string $needle): bool
    {
        foreach ($messages as $message) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}
