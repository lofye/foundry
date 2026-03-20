<?php
declare(strict_types=1);

namespace Foundry\Compiler\Extensions;

use Foundry\Compiler\Codemod\Codemod;
use Foundry\Compiler\Codemod\IntegrationDefinitionNormalizeCodemod;
use Foundry\Compiler\CompilerPass;
use Foundry\Compiler\Migration\MigrationRule;
use Foundry\Compiler\Migration\DefinitionFormat;
use Foundry\Compiler\Passes\IntegrationDefinitionPass;
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
            providedPasses: ['integration_definitions'],
            providedPacks: ['integration.notifications', 'integration.api', 'integration.docs', 'integration.tests'],
            introducedDefinitionFormats: ['notification_definition', 'api_resource_definition'],
            providedMigrationRules: [],
            providedCodemods: ['integration-definition-v1-normalize'],
            providedProjectionOutputs: ['notification_index.php', 'api_resource_index.php'],
            providedInspectSurfaces: ['notification', 'api'],
            providedVerifiers: ['notifications', 'api'],
            providedCapabilities: ['notifications.mail', 'api.resource', 'api.openapi_export', 'docs.graph_generated', 'tests.deep_generation'],
            requiredExtensions: ['core'],
        );
    }

    /**
     * @return array<int,CompilerPass>
     */
    public function linkPasses(): array
    {
        return [new IntegrationDefinitionPass()];
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
     * @return array<int,DefinitionFormat>
     */
    public function definitionFormats(): array
    {
        return [
            new DefinitionFormat('notification_definition', 'Notification definition files under app/definitions/notifications/*.notification.yaml', 1, [1]),
            new DefinitionFormat('api_resource_definition', 'API resource definition files under app/definitions/api/*.api-resource.yaml', 1, [1]),
        ];
    }

    /**
     * @return array<int,Codemod>
     */
    public function codemods(): array
    {
        return [new IntegrationDefinitionNormalizeCodemod()];
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
                inspectSurfaces: ['notification', 'extensions'],
                frameworkVersionConstraint: '*',
                graphVersionConstraint: '^1',
                generators: ['generate notification <name>'],
                definitionFormats: ['notification_definition'],
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
                dependencies: ['foundation.resource'],
                inspectSurfaces: ['api', 'extensions'],
                frameworkVersionConstraint: '*',
                graphVersionConstraint: '^1',
                generators: ['generate api-resource <name> --definition=<file>', 'export openapi --format=json'],
                definitionFormats: ['api_resource_definition'],
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
                inspectSurfaces: ['graph', 'extensions'],
                frameworkVersionConstraint: '*',
                graphVersionConstraint: '^1',
                generators: ['generate docs --format=markdown'],
                definitionFormats: [],
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
                inspectSurfaces: ['impact', 'extensions'],
                frameworkVersionConstraint: '*',
                graphVersionConstraint: '^1',
                generators: ['generate tests <target> --mode=deep', 'generate tests --all-missing'],
                definitionFormats: [],
                migrationRules: [],
                verifiers: ['verify feature'],
                examples: ['examples/integration-tooling/tests'],
            ),
        ];
    }
}
