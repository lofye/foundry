<?php
declare(strict_types=1);

namespace Foundry\Compiler\Extensions;

use Foundry\Compiler\Codemod\Codemod;
use Foundry\Compiler\Codemod\PhaseTwoSpecNormalizeCodemod;
use Foundry\Compiler\CompilerPass;
use Foundry\Compiler\Migration\MigrationRule;
use Foundry\Compiler\Migration\SpecFormat;
use Foundry\Compiler\Passes\PhaseTwoSpecPass;
use Foundry\Compiler\Projection\PhaseTwoProjectionEmitters;
use Foundry\Compiler\Projection\ProjectionEmitter;

final class PhaseTwoCompilerExtension extends AbstractCompilerExtension
{
    public function name(): string
    {
        return 'phase2';
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
            description: 'Foundry Phase 2 graph-native notifications, API resource/OpenAPI, docs generation, and deep test generation foundations.',
            frameworkVersionConstraint: '*',
            graphVersionConstraint: '^1',
            providedNodeTypes: ['notification', 'api_resource'],
            providedPasses: ['phase2_specs'],
            providedPacks: ['phase2.notifications', 'phase2.api', 'phase2.docs', 'phase2.tests'],
            introducedSpecFormats: ['notification_spec', 'api_resource_spec'],
            providedMigrationRules: [],
            providedCodemods: ['phase2-spec-v1-normalize'],
            providedProjectionOutputs: ['notification_index.php', 'api_resource_index.php'],
            providedInspectSurfaces: ['notification', 'api'],
            providedVerifiers: ['notifications', 'api'],
            providedCapabilities: ['notifications.mail', 'api.resource', 'api.openapi_export', 'docs.graph_generated', 'tests.deep_generation'],
        );
    }

    /**
     * @return array<int,CompilerPass>
     */
    public function linkPasses(): array
    {
        return [new PhaseTwoSpecPass()];
    }

    public function passPriority(string $phase, CompilerPass $pass): int
    {
        if ($phase === 'link') {
            return 240;
        }

        return parent::passPriority($phase, $pass);
    }

    /**
     * @return array<int,ProjectionEmitter>
     */
    public function projectionEmitters(): array
    {
        return PhaseTwoProjectionEmitters::all();
    }

    /**
     * @return array<int,MigrationRule>
     */
    public function migrationRules(): array
    {
        return [];
    }

    /**
     * @return array<int,SpecFormat>
     */
    public function specFormats(): array
    {
        return [
            new SpecFormat('notification_spec', 'Notification spec files under app/specs/notifications/*.notification.yaml', 1, [1]),
            new SpecFormat('api_resource_spec', 'API resource spec files under app/specs/api/*.api-resource.yaml', 1, [1]),
        ];
    }

    /**
     * @return array<int,Codemod>
     */
    public function codemods(): array
    {
        return [new PhaseTwoSpecNormalizeCodemod()];
    }

    /**
     * @return array<int,PackDefinition>
     */
    public function packs(): array
    {
        return [
            new PackDefinition(
                name: 'phase2.notifications',
                version: '1.0.0',
                extension: $this->name(),
                description: 'Graph-native notification definitions and mail template workflows.',
                providedCapabilities: ['notifications.mail'],
                requiredCapabilities: ['compiler.core', 'runtime.pipeline'],
                frameworkVersionConstraint: '*',
                graphVersionConstraint: '^1',
                generators: ['generate notification <name>'],
                specFormats: ['notification_spec'],
                migrationRules: [],
                verifiers: ['verify notifications'],
                examples: ['examples/phase2/notifications'],
            ),
            new PackDefinition(
                name: 'phase2.api',
                version: '1.0.0',
                extension: $this->name(),
                description: 'API resource generation and graph-based OpenAPI export.',
                providedCapabilities: ['api.resource', 'api.openapi_export'],
                requiredCapabilities: ['resource.crud', 'compiler.core', 'runtime.pipeline'],
                frameworkVersionConstraint: '*',
                graphVersionConstraint: '^1',
                generators: ['generate api-resource <name> --spec=<file>', 'export openapi --format=json'],
                specFormats: ['api_resource_spec'],
                migrationRules: [],
                verifiers: ['verify api'],
                examples: ['examples/phase2/api'],
            ),
            new PackDefinition(
                name: 'phase2.docs',
                version: '1.0.0',
                extension: $this->name(),
                description: 'Deterministic documentation generation from the compiled graph.',
                providedCapabilities: ['docs.graph_generated'],
                requiredCapabilities: ['compiler.core'],
                frameworkVersionConstraint: '*',
                graphVersionConstraint: '^1',
                generators: ['generate docs --format=markdown'],
                specFormats: [],
                migrationRules: [],
                verifiers: ['verify graph'],
                examples: ['examples/phase2/docs'],
            ),
            new PackDefinition(
                name: 'phase2.tests',
                version: '1.0.0',
                extension: $this->name(),
                description: 'Graph-aware deep test generation workflows.',
                providedCapabilities: ['tests.deep_generation'],
                requiredCapabilities: ['compiler.core'],
                frameworkVersionConstraint: '*',
                graphVersionConstraint: '^1',
                generators: ['generate tests <target> --mode=deep', 'generate tests --all-missing'],
                specFormats: [],
                migrationRules: [],
                verifiers: ['verify feature'],
                examples: ['examples/phase2/tests'],
            ),
        ];
    }
}
