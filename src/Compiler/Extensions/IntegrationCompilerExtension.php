<?php
declare(strict_types=1);

namespace Foundry\Compiler\Extensions;

use Foundry\Compiler\Codemod\Codemod;
use Foundry\Compiler\Codemod\IntegrationSpecNormalizeCodemod;
use Foundry\Compiler\CompilerPass;
use Foundry\Compiler\Migration\MigrationRule;
use Foundry\Compiler\Migration\SpecFormat;
use Foundry\Compiler\Passes\IntegrationSpecPass;
use Foundry\Compiler\Projection\IntegrationProjectionEmitters;
use Foundry\Compiler\Projection\ProjectionEmitter;

final class IntegrationCompilerExtension extends AbstractCompilerExtension
{
    public function name(): string
    {
        return 'integration';
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
            description: 'Graph-native notifications, API resource/OpenAPI, docs generation, and deep test generation foundations.',
            frameworkVersionConstraint: '*',
            graphVersionConstraint: '^1',
            providedNodeTypes: ['notification', 'api_resource'],
            providedPasses: ['integration_specs'],
            providedPacks: ['integration.notifications', 'integration.api', 'integration.docs', 'integration.tests'],
            introducedSpecFormats: ['notification_spec', 'api_resource_spec'],
            providedMigrationRules: [],
            providedCodemods: ['integration-spec-v1-normalize'],
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
        return [new IntegrationSpecPass()];
    }

    public function passPriority(string $stage, CompilerPass $pass): int
    {
        if ($stage === 'link') {
            return 240;
        }

        return parent::passPriority($stage, $pass);
    }

    /**
     * @return array<int,ProjectionEmitter>
     */
    public function projectionEmitters(): array
    {
        return IntegrationProjectionEmitters::all();
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
        return [new IntegrationSpecNormalizeCodemod()];
    }

    /**
     * @return array<int,PackDefinition>
     */
    public function packs(): array
    {
        return [
            new PackDefinition(
                name: 'integration.notifications',
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
                examples: ['examples/integration-tooling/notifications'],
            ),
            new PackDefinition(
                name: 'integration.api',
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
                examples: ['examples/integration-tooling/api'],
            ),
            new PackDefinition(
                name: 'integration.docs',
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
                examples: ['examples/integration-tooling/docs'],
            ),
            new PackDefinition(
                name: 'integration.tests',
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
                examples: ['examples/integration-tooling/tests'],
            ),
        ];
    }
}
