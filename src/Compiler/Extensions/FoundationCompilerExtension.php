<?php

declare(strict_types=1);

namespace Foundry\Compiler\Extensions;

use Foundry\Compiler\Codemod\Codemod;
use Foundry\Compiler\Codemod\FoundationDefinitionNormalizeCodemod;
use Foundry\Compiler\CompilerPass;
use Foundry\Compiler\Migration\DefinitionFormat;
use Foundry\Compiler\Migration\MigrationRule;
use Foundry\Compiler\Passes\FoundationDefinitionPass;
use Foundry\Compiler\Projection\FoundationProjectionEmitters;
use Foundry\Compiler\Projection\ProjectionEmitter;

final class FoundationCompilerExtension extends AbstractCompilerExtension
{
    public function name(): string
    {
        return 'foundation';
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
            description: 'Graph-native generators/definition integration for starter kits, resources, admin, uploads, and listing toolkit.',
            frameworkVersionConstraint: '*',
            graphVersionConstraint: '^1',
            providedNodeTypes: [
                'starter_kit',
                'resource',
                'admin_resource',
                'upload_profile',
                'listing_config',
                'form_definition',
            ],
            providedPasses: ['foundation_definitions'],
            providedPacks: [
                'foundation.starter',
                'foundation.resource',
                'foundation.admin',
                'foundation.uploads',
                'foundation.listing',
            ],
            introducedDefinitionFormats: [
                'starter_definition',
                'resource_definition',
                'admin_resource_definition',
                'upload_profile_definition',
                'listing_config_definition',
            ],
            providedMigrationRules: [],
            providedCodemods: ['foundation-definition-v1-normalize'],
            providedProjectionOutputs: [
                'starter_index.php',
                'resource_index.php',
                'admin_resource_index.php',
                'upload_profile_index.php',
                'listing_index.php',
                'form_index.php',
            ],
            providedInspectSurfaces: ['resource'],
            providedVerifiers: ['resource'],
            providedCapabilities: [
                'starter.auth',
                'resource.crud',
                'forms.server_rendered',
                'admin.backoffice',
                'uploads.media',
                'listing.query_toolkit',
            ],
            requiredExtensions: ['core'],
        );
    }

    /**
     * @return array<int,CompilerPass>
     */
    public function linkPasses(): array
    {
        return [new FoundationDefinitionPass()];
    }

    public function passPriority(string $stage, CompilerPass $pass): int
    {
        if ($stage === 'link') {
            return 220;
        }

        return parent::passPriority($stage, $pass);
    }

    /**
     * @return array<int,ProjectionEmitter>
     */
    public function projectionEmitters(): array
    {
        return FoundationProjectionEmitters::all();
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
            new DefinitionFormat('starter_definition', 'Starter kit definition files under app/definitions/starters/*.starter.yaml', 1, [1]),
            new DefinitionFormat('resource_definition', 'Resource definition files under app/definitions/resources/*.resource.yaml', 1, [1]),
            new DefinitionFormat('admin_resource_definition', 'Admin resource definition files under app/definitions/admin/*.admin.yaml', 1, [1]),
            new DefinitionFormat('upload_profile_definition', 'Upload profile definition files under app/definitions/uploads/*.uploads.yaml', 1, [1]),
            new DefinitionFormat('listing_config_definition', 'Listing config definition files under app/definitions/listing/*.list.yaml', 1, [1]),
        ];
    }

    /**
     * @return array<int,Codemod>
     */
    public function codemods(): array
    {
        return [new FoundationDefinitionNormalizeCodemod()];
    }

    /**
     * @return array<int,PackDefinition>
     */
    public function packs(): array
    {
        return [
            new PackDefinition(
                name: 'foundation.starter',
                version: '1.0.0',
                extension: $this->name(),
                description: 'Starter auth kits and baseline app shell generators.',
                providedCapabilities: ['starter.auth'],
                requiredCapabilities: ['runtime.pipeline', 'compiler.core'],
                inspectSurfaces: ['extensions', 'packs'],
                frameworkVersionConstraint: '*',
                graphVersionConstraint: '^1',
                generators: ['generate starter server-rendered', 'generate starter api'],
                definitionFormats: ['starter_definition'],
                migrationRules: [],
                verifiers: ['verify resource'],
                examples: ['examples/app-scaffolding/starter'],
            ),
            new PackDefinition(
                name: 'foundation.resource',
                version: '1.0.0',
                extension: $this->name(),
                description: 'Schema-driven CRUD resource generation pack.',
                providedCapabilities: ['resource.crud', 'forms.server_rendered'],
                requiredCapabilities: ['runtime.pipeline', 'compiler.core'],
                inspectSurfaces: ['resource', 'extensions'],
                frameworkVersionConstraint: '*',
                graphVersionConstraint: '^1',
                generators: ['generate resource <name> --definition=<file>'],
                definitionFormats: ['resource_definition', 'listing_config_definition'],
                migrationRules: [],
                verifiers: ['verify resource'],
                examples: ['examples/app-scaffolding/blog'],
            ),
            new PackDefinition(
                name: 'foundation.admin',
                version: '1.0.0',
                extension: $this->name(),
                description: 'Admin back-office listing and moderation pack.',
                providedCapabilities: ['admin.backoffice'],
                requiredCapabilities: ['resource.crud'],
                dependencies: ['foundation.resource'],
                inspectSurfaces: ['resource', 'extensions'],
                frameworkVersionConstraint: '*',
                graphVersionConstraint: '^1',
                generators: ['generate admin-resource <name>'],
                definitionFormats: ['admin_resource_definition'],
                migrationRules: [],
                verifiers: ['verify resource'],
                examples: ['examples/app-scaffolding/admin'],
            ),
            new PackDefinition(
                name: 'foundation.uploads',
                version: '1.0.0',
                extension: $this->name(),
                description: 'Uploads and media attachment generation pack.',
                providedCapabilities: ['uploads.media'],
                requiredCapabilities: ['runtime.pipeline'],
                inspectSurfaces: ['resource', 'extensions'],
                frameworkVersionConstraint: '*',
                graphVersionConstraint: '^1',
                generators: ['generate uploads avatar', 'generate uploads attachments'],
                definitionFormats: ['upload_profile_definition'],
                migrationRules: [],
                verifiers: ['verify resource'],
                examples: ['examples/app-scaffolding/uploads'],
            ),
            new PackDefinition(
                name: 'foundation.listing',
                version: '1.0.0',
                extension: $this->name(),
                description: 'Search/filter/sort/pagination listing toolkit.',
                providedCapabilities: ['listing.query_toolkit'],
                requiredCapabilities: ['resource.crud'],
                dependencies: ['foundation.resource'],
                inspectSurfaces: ['resource', 'extensions'],
                frameworkVersionConstraint: '*',
                graphVersionConstraint: '^1',
                generators: ['generate resource <name> --definition=<file>'],
                definitionFormats: ['listing_config_definition'],
                migrationRules: [],
                verifiers: ['verify resource'],
                examples: ['examples/app-scaffolding/listing'],
            ),
        ];
    }
}
