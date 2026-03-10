<?php
declare(strict_types=1);

namespace Foundry\Extensions\Demo;

use Foundry\Compiler\CompilerPass;
use Foundry\Compiler\Extensions\AbstractCompilerExtension;
use Foundry\Compiler\Extensions\ExtensionDescriptor;
use Foundry\Compiler\Extensions\PackDefinition;

final class DemoCapabilityExtension extends AbstractCompilerExtension
{
    public function name(): string
    {
        return 'foundry.demo';
    }

    public function version(): string
    {
        return '0.1.0';
    }

    public function descriptor(): ExtensionDescriptor
    {
        return new ExtensionDescriptor(
            name: $this->name(),
            version: $this->version(),
            description: 'Minimal demo extension used to validate pack/capability registration.',
            frameworkVersionConstraint: '*',
            graphVersionConstraint: '^1',
            providedNodeTypes: ['demo_note'],
            providedPasses: ['enrich'],
            providedPacks: ['demo.notes'],
            introducedSpecFormats: [],
            providedMigrationRules: [],
            providedCodemods: [],
            providedProjectionOutputs: [],
            providedInspectSurfaces: ['extensions', 'packs'],
            providedVerifiers: ['extensions', 'compatibility'],
            providedCapabilities: ['demo.notes.annotate'],
        );
    }

    /**
     * @return array<int,CompilerPass>
     */
    public function enrichPasses(): array
    {
        return [new DemoExtensionPass()];
    }

    /**
     * @return array<int,PackDefinition>
     */
    public function packs(): array
    {
        return [
            new PackDefinition(
                name: 'demo.notes',
                version: '0.1.0',
                extension: $this->name(),
                description: 'Demo pack providing notes annotation capability.',
                providedCapabilities: ['demo.notes.annotate'],
                requiredCapabilities: ['compiler.core'],
                frameworkVersionConstraint: '*',
                graphVersionConstraint: '^1',
                generators: ['demo-note-generator'],
                specFormats: [],
                migrationRules: [],
                verifiers: ['verify compatibility'],
                docsEmitters: [],
                examples: ['examples/extensions-migrations/demo_extension'],
            ),
        ];
    }

    public function passPriority(string $stage, CompilerPass $pass): int
    {
        return $stage === 'enrich' ? 250 : 100;
    }
}
