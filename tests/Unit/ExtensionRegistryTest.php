<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Compiler\Codemod\Codemod;
use Foundry\Compiler\Codemod\CodemodResult;
use Foundry\Compiler\CompilerPass;
use Foundry\Compiler\CompilationState;
use Foundry\Compiler\Extensions\AbstractCompilerExtension;
use Foundry\Compiler\Extensions\ExtensionDescriptor;
use Foundry\Compiler\Extensions\ExtensionRegistry;
use Foundry\Compiler\Extensions\PackDefinition;
use Foundry\Compiler\Migration\MigrationRule;
use Foundry\Compiler\Migration\SpecFormat;
use Foundry\Compiler\Projection\GenericProjectionEmitter;
use Foundry\Support\FoundryError;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class ExtensionRegistryTest extends TestCase
{
    public function test_extensions_are_registered_and_sorted_deterministically(): void
    {
        $ruleA = new class implements MigrationRule {
            public function id(): string { return 'A_RULE'; }
            public function description(): string { return 'A'; }
            public function sourceType(): string { return 'feature_manifest'; }
            public function fromVersion(): int { return 1; }
            public function toVersion(): int { return 2; }
            public function applies(string $path, array $document): bool { return false; }
            public function migrate(string $path, array $document): array { return $document; }
        };

        $ruleB = new class implements MigrationRule {
            public function id(): string { return 'B_RULE'; }
            public function description(): string { return 'B'; }
            public function sourceType(): string { return 'feature_manifest'; }
            public function fromVersion(): int { return 2; }
            public function toVersion(): int { return 3; }
            public function applies(string $path, array $document): bool { return false; }
            public function migrate(string $path, array $document): array { return $document; }
        };

        $codemod = new class implements Codemod {
            public function id(): string { return 'a-codemod'; }
            public function description(): string { return 'A codemod'; }
            public function sourceType(): string { return 'feature_manifest'; }
            public function run(Paths $paths, bool $write = false, ?string $path = null): CodemodResult
            {
                return new CodemodResult($this->id(), $write, [], [], $path);
            }
        };

        $passHighPriority = new class implements CompilerPass {
            public function name(): string { return 'pass.high'; }
            public function run(CompilationState $state): void {}
        };
        $passLowPriority = new class implements CompilerPass {
            public function name(): string { return 'pass.low'; }
            public function run(CompilationState $state): void {}
        };

        $extensionB = new class($ruleB, $passLowPriority) extends AbstractCompilerExtension {
            public function __construct(
                private readonly MigrationRule $rule,
                private readonly CompilerPass $pass,
            ) {}
            public function name(): string { return 'b-ext'; }
            public function version(): string { return '1.0.0'; }
            public function projectionEmitters(): array {
                return [new GenericProjectionEmitter('z-projection', 'z.php', null, static fn (): array => [])];
            }
            public function migrationRules(): array { return [$this->rule]; }
            public function enrichPasses(): array { return [$this->pass]; }
            public function passPriority(string $phase, CompilerPass $pass): int { return 200; }
            public function descriptor(): ExtensionDescriptor
            {
                return new ExtensionDescriptor(
                    name: $this->name(),
                    version: $this->version(),
                    frameworkVersionConstraint: '^1',
                    graphVersionConstraint: '^1',
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
                    graphVersionConstraint: '^1',
                    frameworkVersionConstraint: '^1',
                )];
            }
        };

        $extensionA = new class($ruleA, $codemod, $passHighPriority) extends AbstractCompilerExtension {
            public function __construct(
                private readonly MigrationRule $rule,
                private readonly Codemod $codemod,
                private readonly CompilerPass $pass,
            ) {}
            public function name(): string { return 'a-ext'; }
            public function version(): string { return '1.0.0'; }
            public function projectionEmitters(): array {
                return [new GenericProjectionEmitter('a-projection', 'a.php', null, static fn (): array => [])];
            }
            public function migrationRules(): array { return [$this->rule]; }
            public function codemods(): array { return [$this->codemod]; }
            public function specFormats(): array
            {
                return [new SpecFormat('feature_manifest', 'Feature manifest', 2, [1, 2])];
            }
            public function enrichPasses(): array { return [$this->pass]; }
            public function passPriority(string $phase, CompilerPass $pass): int { return 10; }
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

        $emitters = $registry->projectionEmitters();
        $this->assertSame('a-projection', $emitters[0]->id());
        $this->assertSame('z-projection', $emitters[1]->id());

        $rules = $registry->migrationRules();
        $this->assertSame('A_RULE', $rules[0]->id());
        $this->assertSame('B_RULE', $rules[1]->id());

        $this->assertSame('a-pack', $registry->packRegistry()->all()[0]->name);
        $this->assertSame('feature_manifest', $registry->specFormats()[0]->name);
        $this->assertSame('a-codemod', $registry->codemods()[0]->id());

        $passes = $registry->passesForPhase('enrich');
        $this->assertSame('pass.high', $passes[0]->name());
        $this->assertSame('pass.low', $passes[1]->name());

        $report = $registry->compatibilityReport('1.2.0', 1);
        $this->assertFalse($report->ok);
        $codes = array_values(array_map(static fn (array $row): string => (string) ($row['code'] ?? ''), $report->diagnostics));
        $this->assertContains('FDY7006_CONFLICTING_NODE_PROVIDER', $codes);
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
        } finally {
            $project->cleanup();
        }
    }

    public function test_registry_rejects_invalid_registered_extension_classes(): void
    {
        $project = new TempProject();
        try {
            file_put_contents($project->root . '/foundry.extensions.php', <<<'PHP'
<?php
declare(strict_types=1);

return ['Missing\\Extension\\ClassName'];
PHP);

            $this->expectException(FoundryError::class);
            ExtensionRegistry::forPaths(Paths::fromCwd($project->root));
        } finally {
            $project->cleanup();
        }
    }

    public function test_registry_rejects_non_array_registration_payloads(): void
    {
        $project = new TempProject();
        try {
            file_put_contents($project->root . '/foundry.extensions.php', <<<'PHP'
<?php
declare(strict_types=1);

return 'invalid';
PHP);

            $this->expectException(FoundryError::class);
            ExtensionRegistry::forPaths(Paths::fromCwd($project->root));
        } finally {
            $project->cleanup();
        }
    }
}
