<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Compiler\Analysis\AnalyzerContext;
use Foundry\Compiler\Analysis\GraphAnalyzer;
use Foundry\Compiler\Codemod\Codemod;
use Foundry\Compiler\Codemod\CodemodResult;
use Foundry\Compiler\CompilationState;
use Foundry\Compiler\CompilerPass;
use Foundry\Compiler\Diagnostics\DiagnosticBag;
use Foundry\Compiler\Extensions\AbstractCompilerExtension;
use Foundry\Compiler\Extensions\ExtensionDescriptor;
use Foundry\Compiler\Extensions\ExtensionRegistry;
use Foundry\Compiler\Extensions\PackDefinition;
use Foundry\Doctor\DoctorCheck;
use Foundry\Doctor\DoctorContext;
use Foundry\Compiler\Migration\DefinitionFormat;
use Foundry\Compiler\Migration\MigrationRule;
use Foundry\Compiler\Projection\GenericProjectionEmitter;
use Foundry\Pipeline\PipelineExecutionState;
use Foundry\Pipeline\PipelineStageDefinition;
use Foundry\Pipeline\StageInterceptor;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class ExtensionRegistryTest extends TestCase
{
    public function test_extensions_are_registered_and_sorted_deterministically(): void
    {
        $ruleA = new class implements MigrationRule {
            public function id(): string
            {
                return 'A_RULE';
            }
            public function description(): string
            {
                return 'A';
            }
            public function sourceType(): string
            {
                return 'feature_manifest';
            }
            public function fromVersion(): int
            {
                return 1;
            }
            public function toVersion(): int
            {
                return 2;
            }
            public function applies(string $path, array $document): bool
            {
                return false;
            }
            public function migrate(string $path, array $document): array
            {
                return $document;
            }
        };

        $ruleB = new class implements MigrationRule {
            public function id(): string
            {
                return 'B_RULE';
            }
            public function description(): string
            {
                return 'B';
            }
            public function sourceType(): string
            {
                return 'feature_manifest';
            }
            public function fromVersion(): int
            {
                return 2;
            }
            public function toVersion(): int
            {
                return 3;
            }
            public function applies(string $path, array $document): bool
            {
                return false;
            }
            public function migrate(string $path, array $document): array
            {
                return $document;
            }
        };

        $codemod = new class implements Codemod {
            public function id(): string
            {
                return 'a-codemod';
            }
            public function description(): string
            {
                return 'A codemod';
            }
            public function sourceType(): string
            {
                return 'feature_manifest';
            }
            public function run(Paths $paths, bool $write = false, ?string $path = null): CodemodResult
            {
                return new CodemodResult($this->id(), $write, [], [], $path);
            }
        };

        $passHighPriority = new class implements CompilerPass {
            public function name(): string
            {
                return 'pass.high';
            }
            public function run(CompilationState $state): void {}
        };
        $passLowPriority = new class implements CompilerPass {
            public function name(): string
            {
                return 'pass.low';
            }
            public function run(CompilationState $state): void {}
        };

        $extensionB = new class ($ruleB, $passLowPriority) extends AbstractCompilerExtension {
            public function __construct(
                private readonly MigrationRule $rule,
                private readonly CompilerPass $pass,
            ) {}
            public function name(): string
            {
                return 'b-ext';
            }
            public function version(): string
            {
                return '1.0.0';
            }
            public function projectionEmitters(): array
            {
                return [new GenericProjectionEmitter('z-projection', 'z.php', null, static fn(): array => [])];
            }
            public function migrationRules(): array
            {
                return [$this->rule];
            }
            public function enrichPasses(): array
            {
                return [$this->pass];
            }
            public function passPriority(string $stage, CompilerPass $pass): int
            {
                return 200;
            }
            public function descriptor(): ExtensionDescriptor
            {
                return new ExtensionDescriptor(
                    name: $this->name(),
                    version: $this->version(),
                    frameworkVersionConstraint: '^1',
                    graphVersionConstraint: '^1',
                    requiredExtensions: ['a-ext'],
                    providedNodeTypes: ['custom_node_a'],
                    providedProjectionOutputs: ['z.php'],
                );
            }
            public function packs(): array
            {
                return [new PackDefinition(
                    name: 'z-pack',
                    version: '1.0.0',
                    extension: $this->name(),
                    providedCapabilities: ['cap.z'],
                    requiredCapabilities: ['cap.a'],
                    dependencies: ['a-pack'],
                    graphVersionConstraint: '^1',
                    frameworkVersionConstraint: '^1',
                )];
            }
        };

        $extensionA = new class ($ruleA, $codemod, $passHighPriority) extends AbstractCompilerExtension {
            public function __construct(
                private readonly MigrationRule $rule,
                private readonly Codemod $codemod,
                private readonly CompilerPass $pass,
            ) {}
            public function name(): string
            {
                return 'a-ext';
            }
            public function version(): string
            {
                return '1.0.0';
            }
            public function projectionEmitters(): array
            {
                return [new GenericProjectionEmitter('a-projection', 'a.php', null, static fn(): array => [])];
            }
            public function migrationRules(): array
            {
                return [$this->rule];
            }
            public function codemods(): array
            {
                return [$this->codemod];
            }
            public function definitionFormats(): array
            {
                return [new DefinitionFormat('feature_manifest', 'Feature manifest', 2, [1, 2])];
            }
            public function enrichPasses(): array
            {
                return [$this->pass];
            }
            public function passPriority(string $stage, CompilerPass $pass): int
            {
                return 10;
            }
            public function descriptor(): ExtensionDescriptor
            {
                return new ExtensionDescriptor(
                    name: $this->name(),
                    version: $this->version(),
                    frameworkVersionConstraint: '^1',
                    graphVersionConstraint: '^1',
                    providedNodeTypes: ['custom_node_a'],
                    providedProjectionOutputs: ['a.php'],
                );
            }
            public function packs(): array
            {
                return [new PackDefinition(
                    name: 'a-pack',
                    version: '1.0.0',
                    extension: $this->name(),
                    providedCapabilities: ['cap.a'],
                    requiredCapabilities: [],
                    graphVersionConstraint: '^1',
                    frameworkVersionConstraint: '^1',
                )];
            }
        };

        $registry = new ExtensionRegistry([$extensionB, $extensionA]);

        $rows = $registry->inspectRows();
        $this->assertSame('a-ext', $rows[0]['name']);
        $this->assertSame('b-ext', $rows[1]['name']);
        $this->assertTrue($rows[0]['enabled']);
        $this->assertSame('runtime_enabled', $rows[0]['lifecycle']['current_stage']);
        $this->assertSame(['a-ext', 'b-ext'], $registry->loadOrder());

        $emitters = $registry->projectionEmitters();
        $this->assertSame('a-projection', $emitters[0]->id());
        $this->assertSame('z-projection', $emitters[1]->id());

        $rules = $registry->migrationRules();
        $this->assertSame('A_RULE', $rules[0]->id());
        $this->assertSame('B_RULE', $rules[1]->id());

        $this->assertSame('a-pack', $registry->packRegistry()->all()[0]->name);
        $this->assertSame('feature_manifest', $registry->definitionFormats()[0]->name);
        $this->assertSame('a-codemod', $registry->codemods()[0]->id());

        $passes = $registry->passesForStage('enrich');
        $this->assertSame('pass.high', $passes[0]->name());
        $this->assertSame('pass.low', $passes[1]->name());

        $report = $registry->compatibilityReport('1.2.0', 1);
        $this->assertFalse($report->ok);
        $codes = array_values(array_map(static fn(array $row): string => (string) ($row['code'] ?? ''), $report->diagnostics));
        $this->assertContains('FDY7006_CONFLICTING_NODE_PROVIDER', $codes);
        $this->assertSame(['a-ext', 'b-ext'], $report->loadOrder);
        $this->assertNotEmpty($report->lifecycle);
    }

    public function test_registry_loads_extensions_from_explicit_registration_file(): void
    {
        $project = new TempProject();
        try {
            file_put_contents($project->root . '/foundry.extensions.php', <<<'PHP'
<?php
declare(strict_types=1);

return [
    \Foundry\Extensions\Demo\DemoCapabilityExtension::class,
];
PHP);

            $registry = ExtensionRegistry::forPaths(Paths::fromCwd($project->root));
            $this->assertNotNull($registry->extension('foundry.demo'));
            $this->assertTrue($registry->packRegistry()->has('demo.notes'));
            $this->assertContains('foundry.extensions.php', $registry->registrationSources());
            $this->assertNotEmpty($registry->graphAnalyzers());
            $this->assertContains('foundry.demo', $registry->loadOrder());
        } finally {
            $project->cleanup();
        }
    }

    public function test_registry_reports_invalid_registered_extension_classes(): void
    {
        $project = new TempProject();
        try {
            file_put_contents($project->root . '/foundry.extensions.php', <<<'PHP'
<?php
declare(strict_types=1);

return ['Missing\\Extension\\ClassName'];
PHP);

            $registry = ExtensionRegistry::forPaths(Paths::fromCwd($project->root));
            $codes = array_values(array_map(
                static fn(array $row): string => (string) ($row['code'] ?? ''),
                $registry->diagnostics(),
            ));

            $this->assertContains('FDY7011_EXTENSION_CLASS_NOT_FOUND', $codes);
            $this->assertCount(4, $registry->all());
        } finally {
            $project->cleanup();
        }
    }

    public function test_registry_reports_non_array_registration_payloads(): void
    {
        $project = new TempProject();
        try {
            file_put_contents($project->root . '/foundry.extensions.php', <<<'PHP'
<?php
declare(strict_types=1);

return 'invalid';
PHP);

            $registry = ExtensionRegistry::forPaths(Paths::fromCwd($project->root));
            $codes = array_values(array_map(
                static fn(array $row): string => (string) ($row['code'] ?? ''),
                $registry->diagnostics(),
            ));

            $this->assertContains('FDY7010_EXTENSION_REGISTRATION_INVALID', $codes);
        } finally {
            $project->cleanup();
        }
    }

    public function test_registry_loads_active_local_packs_in_pack_name_order(): void
    {
        $project = new TempProject();
        try {
            $this->copyDirectory(dirname(__DIR__) . '/Fixtures/Packs/foundry-blog', $project->root . '/.foundry/packs/foundry/blog/1.0.0');
            $this->copyDirectory(dirname(__DIR__) . '/Fixtures/Packs/acme-zeta', $project->root . '/.foundry/packs/acme/zeta/1.0.0');
            @mkdir($project->root . '/.foundry/packs', 0777, true);
            file_put_contents($project->root . '/.foundry/packs/installed.json', json_encode([
                'foundry/blog' => [
                    'active_version' => '1.0.0',
                    'installed_versions' => ['1.0.0'],
                ],
                'acme/zeta' => [
                    'active_version' => '1.0.0',
                    'installed_versions' => ['1.0.0'],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            $registry = ExtensionRegistry::forPaths(Paths::fromCwd($project->root));

            $this->assertTrue($registry->packRegistry()->has('acme/zeta'));
            $this->assertTrue($registry->packRegistry()->has('foundry/blog'));
            $this->assertContains('.foundry/packs/installed.json', $registry->registrationSources());

            $rows = $registry->inspectRows();
            $acme = array_find($rows, static fn(array $row): bool => (string) ($row['name'] ?? '') === 'pack.acme.zeta');
            $foundry = array_find($rows, static fn(array $row): bool => (string) ($row['name'] ?? '') === 'pack.foundry.blog');

            $this->assertIsArray($acme);
            $this->assertIsArray($foundry);
            $this->assertLessThan((int) $foundry['load_order'], (int) $acme['load_order']);
        } finally {
            $project->cleanup();
        }
    }

    public function test_registry_disables_extensions_with_missing_dependencies_and_pack_conflicts(): void
    {
        $first = new class extends AbstractCompilerExtension {
            public function name(): string
            {
                return 'alpha';
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
                );
            }
            public function packs(): array
            {
                return [
                    new PackDefinition(
                        name: 'alpha.pack',
                        version: '1.0.0',
                        extension: $this->name(),
                        conflictsWith: ['beta.pack'],
                        frameworkVersionConstraint: '^1',
                        graphVersionConstraint: '^1',
                    ),
                ];
            }
        };

        $missingDependency = new class extends AbstractCompilerExtension {
            public function name(): string
            {
                return 'missing-ext';
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
                    requiredExtensions: ['not-installed'],
                );
            }
        };

        $conflicting = new class extends AbstractCompilerExtension {
            public function name(): string
            {
                return 'beta';
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
                );
            }
            public function packs(): array
            {
                return [
                    new PackDefinition(
                        name: 'beta.pack',
                        version: '1.0.0',
                        extension: $this->name(),
                        frameworkVersionConstraint: '^1',
                        graphVersionConstraint: '^1',
                    ),
                ];
            }
        };

        $registry = new ExtensionRegistry([$first, $missingDependency, $conflicting]);

        $this->assertSame(['alpha'], $registry->loadOrder());
        $this->assertCount(1, $registry->all());

        $missingRow = $registry->inspectRow('missing-ext');
        $this->assertFalse($missingRow['enabled']);
        $missingCodes = array_values(array_map(static fn(array $row): string => (string) ($row['code'] ?? ''), $missingRow['diagnostics']));
        $this->assertContains('FDY7014_EXTENSION_DEPENDENCY_MISSING', $missingCodes);

        $conflictingRow = $registry->inspectRow('beta');
        $this->assertFalse($conflictingRow['enabled']);
        $conflictCodes = array_values(array_map(static fn(array $row): string => (string) ($row['code'] ?? ''), $conflictingRow['diagnostics']));
        $this->assertContains('FDY7022_PACK_CONFLICT', $conflictCodes);
    }

    public function test_registry_exposes_analyzers_checks_pipeline_components_and_stage_passes(): void
    {
        $analyzerA = new class implements GraphAnalyzer {
            public function id(): string
            {
                return 'analyzer.a';
            }
            public function description(): string
            {
                return 'Analyzer A';
            }
            public function analyze(\Foundry\Compiler\ApplicationGraph $graph, AnalyzerContext $context, DiagnosticBag $diagnostics): array
            {
                return [];
            }
        };
        $analyzerB = new class implements GraphAnalyzer {
            public function id(): string
            {
                return 'analyzer.b';
            }
            public function description(): string
            {
                return 'Analyzer B';
            }
            public function analyze(\Foundry\Compiler\ApplicationGraph $graph, AnalyzerContext $context, DiagnosticBag $diagnostics): array
            {
                return [];
            }
        };
        $checkA = new class implements DoctorCheck {
            public function id(): string
            {
                return 'doctor.a';
            }
            public function description(): string
            {
                return 'Doctor A';
            }
            public function check(DoctorContext $context, DiagnosticBag $diagnostics): array
            {
                return [];
            }
        };
        $checkB = new class implements DoctorCheck {
            public function id(): string
            {
                return 'doctor.b';
            }
            public function description(): string
            {
                return 'Doctor B';
            }
            public function check(DoctorContext $context, DiagnosticBag $diagnostics): array
            {
                return [];
            }
        };
        $interceptorA = new class implements StageInterceptor {
            public function id(): string
            {
                return 'interceptor.a';
            }
            public function stage(): string
            {
                return 'auth';
            }
            public function priority(): int
            {
                return 20;
            }
            public function handle(PipelineExecutionState $state): void {}
            public function isDangerous(): bool
            {
                return false;
            }
        };
        $interceptorB = new class implements StageInterceptor {
            public function id(): string
            {
                return 'interceptor.b';
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
                return true;
            }
        };
        $discoveryPass = new class implements CompilerPass {
            public function name(): string
            {
                return 'pass.discovery';
            }
            public function run(CompilationState $state): void {}
        };
        $emitPass = new class implements CompilerPass {
            public function name(): string
            {
                return 'pass.emit';
            }
            public function run(CompilationState $state): void {}
        };

        $alpha = $this->makeExtension(
            'alpha-ext',
            analyzers: [$analyzerB],
            checks: [$checkB],
            stages: [new PipelineStageDefinition('late', priority: 200)],
            interceptors: [$interceptorA],
            discoveryPasses: [$discoveryPass],
            emitPasses: [$emitPass],
        );
        $beta = $this->makeExtension(
            'beta-ext',
            analyzers: [$analyzerA],
            checks: [$checkA],
            stages: [new PipelineStageDefinition('early', priority: 10)],
            interceptors: [$interceptorB],
        );

        $registry = new ExtensionRegistry([$alpha, $beta]);

        $this->assertSame(['analyzer.a', 'analyzer.b'], array_map(
            static fn(GraphAnalyzer $analyzer): string => $analyzer->id(),
            $registry->graphAnalyzers(),
        ));
        $this->assertSame(['doctor.a', 'doctor.b'], array_map(
            static fn(DoctorCheck $check): string => $check->id(),
            $registry->doctorChecks(),
        ));
        $this->assertSame(['early', 'late'], array_map(
            static fn(PipelineStageDefinition $stage): string => $stage->name,
            $registry->pipelineStages(),
        ));
        $this->assertSame(['interceptor.b', 'interceptor.a'], array_map(
            static fn(StageInterceptor $interceptor): string => $interceptor->id(),
            $registry->pipelineInterceptors(),
        ));
        $this->assertSame(['pass.discovery'], array_map(
            static fn(CompilerPass $pass): string => $pass->name(),
            $registry->passesForStage('discovery'),
        ));
        $this->assertSame(['pass.emit'], array_map(
            static fn(CompilerPass $pass): string => $pass->name(),
            $registry->passesForStage('emit'),
        ));
        $this->assertSame([], $registry->passesForStage('unknown'));
        $this->assertSame(['alpha-ext', 'beta-ext'], $registry->collect(
            static fn($extension): array => [$extension->name()],
        ));
        $this->assertSame(['alpha-ext', 'beta-ext'], array_map(
            static fn($extension): string => $extension->name(),
            $registry->loadedExtensions(),
        ));
    }

    public function test_registry_disables_duplicate_ids_missing_pack_dependencies_and_dependency_cycles(): void
    {
        $stable = $this->makeExtension(
            'alpha-ext',
            packs: [$this->pack('alpha-pack', 'alpha-ext')],
        );
        $duplicate = $this->makeExtension(
            'alpha-ext',
            version: '2.0.0',
            packs: [$this->pack('duplicate-pack', 'alpha-ext')],
        );
        $packMissingDependency = $this->makeExtension(
            'pack-missing-ext',
            packs: [$this->pack('pack-dependent', 'pack-missing-ext', ['ghost-pack'])],
        );
        $cycleA = $this->makeExtension(
            'cycle-a',
            descriptor: ['requiredExtensions' => ['cycle-b']],
            packs: [$this->pack('cycle-a-pack', 'cycle-a')],
        );
        $cycleB = $this->makeExtension(
            'cycle-b',
            descriptor: ['requiredExtensions' => ['cycle-a']],
            packs: [$this->pack('cycle-b-pack', 'cycle-b')],
        );

        $registry = new ExtensionRegistry([
            $stable,
            $duplicate,
            $packMissingDependency,
            $cycleA,
            $cycleB,
        ]);

        $codes = array_values(array_map(
            static fn(array $row): string => (string) ($row['code'] ?? ''),
            $registry->diagnostics(),
        ));
        sort($codes);

        $this->assertContains('FDY7005_DUPLICATE_EXTENSION_ID', $codes);
        $this->assertContains('FDY7018_PACK_DEPENDENCY_MISSING', $codes);
        $this->assertContains('FDY7019_EXTENSION_DEPENDENCY_CYCLE', $codes);
        $this->assertSame(['alpha-ext'], $registry->loadOrder());
        $this->assertSame(['alpha-pack'], array_map(
            static fn(PackDefinition $pack): string => $pack->name,
            $registry->packs(),
        ));
        $this->assertTrue((bool) $registry->inspectRow('alpha-ext')['enabled']);
        $this->assertFalse((bool) $registry->inspectRow('pack-missing-ext')['enabled']);
        $this->assertSame('runtime_enabled', $registry->lifecycleRows()[0]['lifecycle']['current_stage']);
    }

    private function makeExtension(
        string $name,
        string $version = '1.0.0',
        array $descriptor = [],
        array $packs = [],
        array $analyzers = [],
        array $checks = [],
        array $stages = [],
        array $interceptors = [],
        array $discoveryPasses = [],
        array $emitPasses = [],
    ): AbstractCompilerExtension {
        return new class (
            $name,
            $version,
            $descriptor,
            $packs,
            $analyzers,
            $checks,
            $stages,
            $interceptors,
            $discoveryPasses,
            $emitPasses,
        ) extends AbstractCompilerExtension {
            public function __construct(
                private readonly string $nameValue,
                private readonly string $versionValue,
                private readonly array $descriptorOverrides,
                private readonly array $packsValue,
                private readonly array $analyzersValue,
                private readonly array $checksValue,
                private readonly array $stagesValue,
                private readonly array $interceptorsValue,
                private readonly array $discoveryPassesValue,
                private readonly array $emitPassesValue,
            ) {}

            public function name(): string
            {
                return $this->nameValue;
            }

            public function version(): string
            {
                return $this->versionValue;
            }

            public function packs(): array
            {
                return $this->packsValue;
            }

            public function graphAnalyzers(): array
            {
                return $this->analyzersValue;
            }

            public function doctorChecks(): array
            {
                return $this->checksValue;
            }

            public function pipelineStages(): array
            {
                return $this->stagesValue;
            }

            public function pipelineInterceptors(): array
            {
                return $this->interceptorsValue;
            }

            public function discoveryPasses(): array
            {
                return $this->discoveryPassesValue;
            }

            public function emitPasses(): array
            {
                return $this->emitPassesValue;
            }

            public function descriptor(): ExtensionDescriptor
            {
                return new ExtensionDescriptor(
                    name: $this->nameValue,
                    version: $this->versionValue,
                    frameworkVersionConstraint: '^1',
                    graphVersionConstraint: '^1',
                    requiredExtensions: $this->descriptorOverrides['requiredExtensions'] ?? [],
                    optionalExtensions: $this->descriptorOverrides['optionalExtensions'] ?? [],
                    conflictsWithExtensions: $this->descriptorOverrides['conflictsWithExtensions'] ?? [],
                    providedNodeTypes: $this->descriptorOverrides['providedNodeTypes'] ?? [],
                    providedProjectionOutputs: $this->descriptorOverrides['providedProjectionOutputs'] ?? [],
                );
            }
        };
    }

    /**
     * @param array<int,string> $dependencies
     */
    private function pack(string $name, string $extension, array $dependencies = []): PackDefinition
    {
        return new PackDefinition(
            name: $name,
            version: '1.0.0',
            extension: $extension,
            providedCapabilities: [$name . '.capability'],
            requiredCapabilities: [],
            dependencies: $dependencies,
            graphVersionConstraint: '^1',
            frameworkVersionConstraint: '^1',
        );
    }

    private function copyDirectory(string $source, string $target): void
    {
        @mkdir($target, 0777, true);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof \SplFileInfo) {
                continue;
            }

            $pathname = $fileInfo->getPathname();
            $relative = substr($pathname, strlen(rtrim($source, '/') . '/'));
            $destination = $target . '/' . $relative;

            if ($fileInfo->isDir()) {
                @mkdir($destination, 0777, true);
                continue;
            }

            @mkdir(dirname($destination), 0777, true);
            copy($pathname, $destination);
        }
    }
}
