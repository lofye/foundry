<?php
declare(strict_types=1);

namespace Foundry\Compiler\Extensions;

use Foundry\Compiler\Codemod\Codemod;
use Foundry\Compiler\Codemod\PhaseOneSpecNormalizeCodemod;
use Foundry\Compiler\CompilerPass;
use Foundry\Compiler\Migration\MigrationRule;
use Foundry\Compiler\Migration\SpecFormat;
use Foundry\Compiler\Passes\PhaseOneSpecPass;
use Foundry\Compiler\Projection\PhaseOneProjectionEmitters;
use Foundry\Compiler\Projection\ProjectionEmitter;

final class PhaseOneCompilerExtension extends AbstractCompilerExtension
{
    public function name(): string
    {
        return 'phase1';
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
            description: 'Foundry Phase 1 generators/spec integration for starter kits, resources, admin, uploads, and listing toolkit.',
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
            providedPasses: ['phase1_specs'],
            providedPacks: [
                'phase1.starter',
                'phase1.resource',
                'phase1.admin',
                'phase1.uploads',
                'phase1.listing',
            ],
            introducedSpecFormats: [
                'starter_spec',
                'resource_spec',
                'admin_resource_spec',
                'upload_profile_spec',
                'listing_config_spec',
            ],
            providedMigrationRules: [],
            providedCodemods: ['phase1-spec-v1-normalize'],
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
        );
    }

    /**
     * @return array<int,CompilerPass>
     */
    public function linkPasses(): array
    {
        return [new PhaseOneSpecPass()];
    }

    public function passPriority(string $phase, CompilerPass $pass): int
    {
        if ($phase === 'link') {
            return 220;
        }

        return parent::passPriority($phase, $pass);
    }

    /**
     * @return array<int,ProjectionEmitter>
     */
    public function projectionEmitters(): array
    {
        return PhaseOneProjectionEmitters::all();
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
            new SpecFormat('starter_spec', 'Starter kit spec files under app/specs/starters/*.starter.yaml', 1, [1]),
            new SpecFormat('resource_spec', 'Resource spec files under app/specs/resources/*.resource.yaml', 1, [1]),
            new SpecFormat('admin_resource_spec', 'Admin resource spec files under app/specs/admin/*.admin.yaml', 1, [1]),
            new SpecFormat('upload_profile_spec', 'Upload profile spec files under app/specs/uploads/*.uploads.yaml', 1, [1]),
            new SpecFormat('listing_config_spec', 'Listing config spec files under app/specs/listing/*.list.yaml', 1, [1]),
        ];
    }

    /**
     * @return array<int,Codemod>
     */
    public function codemods(): array
    {
        return [new PhaseOneSpecNormalizeCodemod()];
    }

    /**
     * @return array<int,PackDefinition>
     */
    public function packs(): array
    {
        return [
            new PackDefinition(
                name: 'phase1.starter',
                version: '1.0.0',
                extension: $this->name(),
                description: 'Starter auth kits and baseline app shell generators.',
                providedCapabilities: ['starter.auth'],
                requiredCapabilities: ['runtime.pipeline', 'compiler.core'],
                frameworkVersionConstraint: '*',
                graphVersionConstraint: '^1',
                generators: ['generate starter server-rendered', 'generate starter api'],
                specFormats: ['starter_spec'],
                migrationRules: [],
                verifiers: ['verify resource'],
                examples: ['examples/phase1/starter'],
            ),
            new PackDefinition(
                name: 'phase1.resource',
                version: '1.0.0',
                extension: $this->name(),
                description: 'Schema-driven CRUD resource generation pack.',
                providedCapabilities: ['resource.crud', 'forms.server_rendered'],
                requiredCapabilities: ['runtime.pipeline', 'compiler.core'],
                frameworkVersionConstraint: '*',
                graphVersionConstraint: '^1',
                generators: ['generate resource <name> --spec=<file>'],
                specFormats: ['resource_spec', 'listing_config_spec'],
                migrationRules: [],
                verifiers: ['verify resource'],
                examples: ['examples/phase1/blog'],
            ),
            new PackDefinition(
                name: 'phase1.admin',
                version: '1.0.0',
                extension: $this->name(),
                description: 'Admin back-office listing and moderation pack.',
                providedCapabilities: ['admin.backoffice'],
                requiredCapabilities: ['resource.crud'],
                frameworkVersionConstraint: '*',
                graphVersionConstraint: '^1',
                generators: ['generate admin-resource <name>'],
                specFormats: ['admin_resource_spec'],
                migrationRules: [],
                verifiers: ['verify resource'],
                examples: ['examples/phase1/admin'],
            ),
            new PackDefinition(
                name: 'phase1.uploads',
                version: '1.0.0',
                extension: $this->name(),
                description: 'Uploads and media attachment generation pack.',
                providedCapabilities: ['uploads.media'],
                requiredCapabilities: ['runtime.pipeline'],
                frameworkVersionConstraint: '*',
                graphVersionConstraint: '^1',
                generators: ['generate uploads avatar', 'generate uploads attachments'],
                specFormats: ['upload_profile_spec'],
                migrationRules: [],
                verifiers: ['verify resource'],
                examples: ['examples/phase1/uploads'],
            ),
            new PackDefinition(
                name: 'phase1.listing',
                version: '1.0.0',
                extension: $this->name(),
                description: 'Search/filter/sort/pagination listing toolkit.',
                providedCapabilities: ['listing.query_toolkit'],
                requiredCapabilities: ['resource.crud'],
                frameworkVersionConstraint: '*',
                graphVersionConstraint: '^1',
                generators: ['generate resource <name> --spec=<file>'],
                specFormats: ['listing_config_spec'],
                migrationRules: [],
                verifiers: ['verify resource'],
                examples: ['examples/phase1/listing'],
            ),
        ];
    }
}
