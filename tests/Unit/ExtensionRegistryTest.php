<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Compiler\Codemod\Codemod;
use Foundry\Compiler\Codemod\CodemodResult;
use Foundry\Compiler\CompilationState;
use Foundry\Compiler\CompilerPass;
use Foundry\Compiler\Extensions\AbstractCompilerExtension;
use Foundry\Compiler\Extensions\ExtensionDescriptor;
use Foundry\Compiler\Extensions\ExtensionRegistry;
use Foundry\Compiler\Extensions\PackDefinition;
use Foundry\Compiler\Migration\DefinitionFormat;
use Foundry\Compiler\Migration\MigrationRule;
use Foundry\Compiler\Projection\GenericProjectionEmitter;
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
