<?php
declare(strict_types=1);

namespace Foundry\Compiler\Extensions;

use Foundry\Compiler\Analysis\GraphAnalyzer;
use Foundry\Compiler\Codemod\Codemod;
use Foundry\Compiler\CompilerPass;
use Foundry\Compiler\Migration\MigrationRule;
use Foundry\Compiler\Migration\SpecFormat;
use Foundry\Compiler\Projection\ProjectionEmitter;
use Foundry\Pipeline\PipelineStageDefinition;
use Foundry\Pipeline\StageInterceptor;

abstract class AbstractCompilerExtension implements CompilerExtension
{
    public function descriptor(): ExtensionDescriptor
    {
        return new ExtensionDescriptor(
            name: $this->name(),
            version: $this->version(),
        );
    }

    /** @return array<int,CompilerPass> */
    public function discoveryPasses(): array
    {
        return [];
    }

    /** @return array<int,CompilerPass> */
    public function normalizePasses(): array
    {
        return [];
    }

    /** @return array<int,CompilerPass> */
    public function linkPasses(): array
    {
        return [];
    }

    /** @return array<int,CompilerPass> */
    public function validatePasses(): array
    {
        return [];
    }

    /** @return array<int,CompilerPass> */
    public function enrichPasses(): array
    {
        return [];
    }

    /** @return array<int,CompilerPass> */
    public function emitPasses(): array
    {
        return [];
    }

    /** @return array<int,CompilerPass> */
    public function analyzePasses(): array
    {
        return [];
    }

    /** @return array<int,ProjectionEmitter> */
    public function projectionEmitters(): array
    {
        return [];
    }

    /** @return array<int,PackDefinition> */
    public function packs(): array
    {
        return [];
    }

    /** @return array<int,MigrationRule> */
    public function migrationRules(): array
    {
        return [];
    }

    /** @return array<int,SpecFormat> */
    public function specFormats(): array
    {
        return [];
    }

    /** @return array<int,Codemod> */
    public function codemods(): array
    {
        return [];
    }

    /** @return array<int,GraphAnalyzer> */
    public function graphAnalyzers(): array
    {
        return [];
    }

    /** @return array<int,PipelineStageDefinition> */
    public function pipelineStages(): array
    {
        return [];
    }

    /** @return array<int,StageInterceptor> */
    public function pipelineInterceptors(): array
    {
        return [];
    }

    public function passPriority(string $phase, CompilerPass $pass): int
    {
        return 100;
    }

    /** @return array<string,mixed> */
    public function describe(): array
    {
        $descriptor = $this->descriptor()->toArray();

        return [
            'name' => $this->name(),
            'version' => $this->version(),
            'description' => (string) ($descriptor['description'] ?? ''),
            'framework_version_constraint' => (string) ($descriptor['framework_version_constraint'] ?? '*'),
            'graph_version_constraint' => (string) ($descriptor['graph_version_constraint'] ?? '*'),
            'discovery_passes' => count($this->discoveryPasses()),
            'normalize_passes' => count($this->normalizePasses()),
            'link_passes' => count($this->linkPasses()),
            'validate_passes' => count($this->validatePasses()),
            'enrich_passes' => count($this->enrichPasses()),
            'emit_passes' => count($this->emitPasses()),
            'analyze_passes' => count($this->analyzePasses()),
            'projection_emitters' => array_values(array_map(
                static fn (ProjectionEmitter $emitter): string => $emitter->id(),
                $this->projectionEmitters(),
            )),
            'migration_rules' => array_values(array_map(
                static fn (MigrationRule $rule): string => $rule->id(),
                $this->migrationRules(),
            )),
            'packs' => array_values(array_map(
                static fn (PackDefinition $pack): string => $pack->name,
                $this->packs(),
            )),
            'spec_formats' => array_values(array_map(
                static fn (SpecFormat $format): string => $format->name,
                $this->specFormats(),
            )),
            'codemods' => array_values(array_map(
                static fn (Codemod $codemod): string => $codemod->id(),
                $this->codemods(),
            )),
            'graph_analyzers' => array_values(array_map(
                static fn (GraphAnalyzer $analyzer): string => $analyzer->id(),
                $this->graphAnalyzers(),
            )),
            'pipeline_stages' => array_values(array_map(
                static fn (PipelineStageDefinition $stage): string => $stage->name,
                $this->pipelineStages(),
            )),
            'pipeline_interceptors' => array_values(array_map(
                static fn (StageInterceptor $interceptor): string => $interceptor->id(),
                $this->pipelineInterceptors(),
            )),
            'provides' => $descriptor['provides'] ?? [],
        ];
    }
}
