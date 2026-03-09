<?php
declare(strict_types=1);

namespace Foundry\Compiler\Extensions;

use Foundry\Compiler\Analysis\Analyzers\AuthAnalyzer;
use Foundry\Compiler\Analysis\Analyzers\CacheTopologyAnalyzer;
use Foundry\Compiler\Analysis\Analyzers\DeadCodeAnalyzer;
use Foundry\Compiler\Analysis\Analyzers\DependencyAnalyzer;
use Foundry\Compiler\Analysis\Analyzers\PipelineAnalyzer;
use Foundry\Compiler\Analysis\Analyzers\SchemaIntegrityAnalyzer;
use Foundry\Compiler\Analysis\Analyzers\TestCoverageAnalyzer;
use Foundry\Compiler\Analysis\GraphAnalyzer;
use Foundry\Compiler\Codemod\Codemod;
use Foundry\Compiler\Codemod\FeatureManifestV2Codemod;
use Foundry\Compiler\CompilerPass;
use Foundry\Compiler\Migration\FeatureManifestV2Rule;
use Foundry\Compiler\Migration\MigrationRule;
use Foundry\Compiler\Migration\SpecFormat;
use Foundry\Compiler\Passes\PipelinePass;
use Foundry\Compiler\Projection\CoreProjectionEmitters;
use Foundry\Compiler\Projection\ProjectionEmitter;
use Foundry\Pipeline\Interceptors\RequestTraceInterceptor;
use Foundry\Pipeline\Interceptors\ResponseTraceInterceptor;
use Foundry\Pipeline\StageInterceptor;

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
                'pipeline_stage',
                'guard',
                'interceptor',
                'execution_plan',
            ],
            providedPasses: ['discovery', 'normalize', 'link', 'pipeline', 'validate', 'enrich', 'analyze', 'emit'],
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
                'pipeline_index.php',
                'guard_index.php',
                'execution_plan_index.php',
                'interceptor_index.php',
            ],
            providedInspectSurfaces: [
                'graph',
                'node',
                'dependencies',
                'dependents',
                'impact',
                'doctor',
                'graph.visualize',
                'prompt',
                'extensions',
                'packs',
                'compatibility',
                'migrations',
                'pipeline',
                'execution-plan',
                'guards',
                'interceptors',
            ],
            providedVerifiers: ['graph', 'compatibility', 'extensions', 'pipeline'],
            providedCapabilities: [
                'compiler.core',
                'analysis.doctor',
                'visualization.graph',
                'prompt.graph_context',
                'migration.feature_manifest_v2',
                'runtime.pipeline',
            ],
        );
    }

    /**
     * @return array<int,CompilerPass>
     */
    public function linkPasses(): array
    {
        return [
            new PipelinePass(),
        ];
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
     * @return array<int,GraphAnalyzer>
     */
    public function graphAnalyzers(): array
    {
        return [
            new DependencyAnalyzer(),
            new AuthAnalyzer(),
            new SchemaIntegrityAnalyzer(),
            new DeadCodeAnalyzer(),
            new CacheTopologyAnalyzer(),
            new TestCoverageAnalyzer(),
            new PipelineAnalyzer(),
        ];
    }

    /**
     * @return array<int,StageInterceptor>
     */
    public function pipelineInterceptors(): array
    {
        return [
            new RequestTraceInterceptor(),
            new ResponseTraceInterceptor(),
        ];
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
                providedCapabilities: ['compiler.core', 'graph.runtime_projections', 'runtime.pipeline'],
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
