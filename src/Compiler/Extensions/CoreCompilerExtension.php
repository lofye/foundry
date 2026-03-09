<?php
declare(strict_types=1);

namespace Foundry\Compiler\Extensions;

use Foundry\Compiler\Codemod\Codemod;
use Foundry\Compiler\Codemod\FeatureManifestV2Codemod;
use Foundry\Compiler\Migration\FeatureManifestV2Rule;
use Foundry\Compiler\Migration\MigrationRule;
use Foundry\Compiler\Migration\SpecFormat;
use Foundry\Compiler\Projection\CoreProjectionEmitters;
use Foundry\Compiler\Projection\ProjectionEmitter;

final class CoreCompilerExtension extends AbstractCompilerExtension
{
    public function name(): string
    {
        return 'core';
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
            description: 'Foundry core compiler extension with baseline graph projections and feature manifest migration.',
            frameworkVersionConstraint: '*',
            graphVersionConstraint: '^1',
            providedNodeTypes: [
                'feature',
                'route',
                'schema',
                'permission',
                'query',
                'event',
                'job',
                'cache',
                'scheduler',
                'webhook',
                'test',
                'context_manifest',
                'auth',
                'rate_limit',
            ],
            providedPasses: ['discovery', 'normalize', 'link', 'validate', 'enrich', 'analyze', 'emit'],
            providedPacks: ['core.foundation'],
            introducedSpecFormats: ['feature_manifest'],
            providedMigrationRules: ['FDY_MIGRATE_FEATURE_MANIFEST_V2'],
            providedCodemods: ['feature-manifest-v1-to-v2'],
            providedProjectionOutputs: [
                'routes_index.php',
                'feature_index.php',
                'schema_index.php',
                'permission_index.php',
                'query_index.php',
                'event_index.php',
                'job_index.php',
                'cache_index.php',
                'scheduler_index.php',
                'webhook_index.php',
            ],
            providedInspectSurfaces: [
                'graph',
                'node',
                'dependencies',
                'dependents',
                'impact',
                'extensions',
                'packs',
                'compatibility',
                'migrations',
            ],
            providedVerifiers: ['graph', 'compatibility', 'extensions'],
            providedCapabilities: ['compiler.core', 'migration.feature_manifest_v2'],
        );
    }

    /**
     * @return array<int,ProjectionEmitter>
     */
    public function projectionEmitters(): array
    {
        return CoreProjectionEmitters::all();
    }

    /**
     * @return array<int,MigrationRule>
     */
    public function migrationRules(): array
    {
        return [new FeatureManifestV2Rule()];
    }

    /**
     * @return array<int,SpecFormat>
     */
    public function specFormats(): array
    {
        return [
            new SpecFormat(
                name: 'feature_manifest',
                description: 'Feature source-of-truth manifest at app/features/<feature>/feature.yaml.',
                currentVersion: 2,
                supportedVersions: [1, 2],
            ),
        ];
    }

    /**
     * @return array<int,Codemod>
     */
    public function codemods(): array
    {
        return [new FeatureManifestV2Codemod()];
    }

    /**
     * @return array<int,PackDefinition>
     */
    public function packs(): array
    {
        return [
            new PackDefinition(
                name: 'core.foundation',
                version: '1.0.0',
                extension: $this->name(),
                description: 'Core semantic compiler capability pack.',
                providedCapabilities: ['compiler.core', 'graph.runtime_projections'],
                requiredCapabilities: [],
                frameworkVersionConstraint: '*',
                graphVersionConstraint: '^1',
                generators: ['generate feature', 'generate indexes'],
                specFormats: ['feature_manifest'],
                migrationRules: ['FDY_MIGRATE_FEATURE_MANIFEST_V2'],
                verifiers: ['verify graph'],
                docsEmitters: [],
                examples: ['examples/phase0'],
            ),
        ];
    }
}
