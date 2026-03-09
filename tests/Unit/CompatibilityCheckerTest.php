<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Compiler\Extensions\AbstractCompilerExtension;
use Foundry\Compiler\Extensions\CompatibilityChecker;
use Foundry\Compiler\Extensions\ExtensionDescriptor;
use Foundry\Compiler\Extensions\ExtensionRegistry;
use Foundry\Compiler\Extensions\PackDefinition;
use Foundry\Compiler\Migration\SpecFormat;
use PHPUnit\Framework\TestCase;

final class CompatibilityCheckerTest extends TestCase
{
    public function test_checker_reports_extension_pack_and_spec_conflicts(): void
    {
        $extensionA = new class extends AbstractCompilerExtension {
            public function name(): string { return 'a-ext'; }
            public function version(): string { return '1.0.0'; }
            public function descriptor(): ExtensionDescriptor
            {
                return new ExtensionDescriptor(
                    name: $this->name(),
                    version: $this->version(),
                    frameworkVersionConstraint: '^2',
                    graphVersionConstraint: '^2',
                    providedNodeTypes: ['shared.node'],
                    providedProjectionOutputs: ['shared.php'],
                );
            }
            public function packs(): array
            {
                return [
                    new PackDefinition(
                        name: 'pack-a',
                        version: '1.0.0',
                        extension: $this->name(),
                        providedCapabilities: ['cap.a'],
                        requiredCapabilities: ['cap.missing'],
                        frameworkVersionConstraint: '^2',
                        graphVersionConstraint: '^2',
                    ),
                ];
            }
            public function specFormats(): array
            {
                return [new SpecFormat('shared_format', 'Shared', 1, [1])];
            }
        };

        $extensionB = new class extends AbstractCompilerExtension {
            public function name(): string { return 'b-ext'; }
            public function version(): string { return '1.0.0'; }
            public function descriptor(): ExtensionDescriptor
            {
                return new ExtensionDescriptor(
                    name: $this->name(),
                    version: $this->version(),
                    frameworkVersionConstraint: '^1',
                    graphVersionConstraint: '^1',
                    providedNodeTypes: ['shared.node'],
                    providedProjectionOutputs: ['shared.php'],
                );
            }
            public function packs(): array
            {
                return [
                    new PackDefinition(
                        name: 'pack-b',
                        version: '1.0.0',
                        extension: $this->name(),
                        providedCapabilities: ['cap.b'],
                        requiredCapabilities: ['cap.a'],
                        frameworkVersionConstraint: '^1',
                        graphVersionConstraint: '^1',
                    ),
                ];
            }
            public function specFormats(): array
            {
                return [new SpecFormat('shared_format', 'Shared', 2, [2])];
            }
        };

        $registry = new ExtensionRegistry([$extensionA, $extensionB]);
        $checker = new CompatibilityChecker($registry, $registry->packRegistry());
        $report = $checker->check('1.2.0', 1);

        $this->assertFalse($report->ok);
        $codes = array_values(array_map(static fn (array $row): string => (string) ($row['code'] ?? ''), $report->diagnostics));
        $this->assertContains('FDY7001_INCOMPATIBLE_EXTENSION_VERSION', $codes);
        $this->assertContains('FDY7002_INCOMPATIBLE_GRAPH_VERSION', $codes);
        $this->assertContains('FDY7006_CONFLICTING_NODE_PROVIDER', $codes);
        $this->assertContains('FDY7007_CONFLICTING_PROJECTION_PROVIDER', $codes);
        $this->assertContains('FDY7008_INCOMPATIBLE_PACK_VERSION', $codes);
        $this->assertContains('FDY7009_PACK_CAPABILITY_MISSING', $codes);
        $this->assertContains('FDY7003_UNSUPPORTED_SPEC_VERSION', $codes);
    }

    public function test_checker_returns_ok_for_core_extension_defaults(): void
    {
        $registry = new ExtensionRegistry([
            new \Foundry\Compiler\Extensions\CoreCompilerExtension(),
        ]);

        $checker = new CompatibilityChecker($registry, $registry->packRegistry());
        $report = $checker->check('1.0.0', 1);

        $this->assertTrue($report->ok);
        $this->assertSame([], $report->diagnostics);
        $this->assertSame(1, $report->versionMatrix['graph_version']);
    }
}
