<?php
declare(strict_types=1);

namespace Foundry\Compiler\Extensions;

use Foundry\Compiler\Analysis\GraphAnalyzer;
use Foundry\Compiler\Codemod\Codemod;
use Foundry\Compiler\CompilerPass;
use Foundry\Compiler\Migration\MigrationRule;
use Foundry\Compiler\Migration\DefinitionFormat;
use Foundry\Doctor\DoctorCheck;
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

    /** @return array<int,DefinitionFormat> */
    public function definitionFormats(): array
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

    /** @return array<int,DoctorCheck> */
    public function doctorChecks(): array
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

    public function passPriority(string $stage, CompilerPass $pass): int
    {
        return 100;
    }

    /** @return array<string,mixed> */
    public function describe(): array
    {
        $descriptor = $this->descriptor()->toArray();
        $packs = $this->packs();

        return [
            'name' => $this->name(),
            'version' => $this->version(),
            'description' => (string) ($descriptor['description'] ?? ''),
            'framework_version_constraint' => (string) ($descriptor['framework_version_constraint'] ?? '*'),
            'graph_version_constraint' => (string) ($descriptor['graph_version_constraint'] ?? '*'),
            'dependencies' => $descriptor['dependencies'] ?? [],
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
                $packs,
            )),
            'definition_formats' => array_values(array_map(
                static fn (DefinitionFormat $format): string => $format->name,
                $this->definitionFormats(),
            )),
            'codemods' => array_values(array_map(
                static fn (Codemod $codemod): string => $codemod->id(),
                $this->codemods(),
            )),
            'graph_analyzers' => array_values(array_map(
                static fn (GraphAnalyzer $analyzer): string => $analyzer->id(),
                $this->graphAnalyzers(),
            )),
            'doctor_checks' => array_values(array_map(
                static fn (DoctorCheck $check): string => $check->id(),
                $this->doctorChecks(),
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
            'hooks' => [
                'graph' => [
                    'passes' => [
                        'discovery' => count($this->discoveryPasses()),
                        'normalize' => count($this->normalizePasses()),
                        'link' => count($this->linkPasses()),
                        'validate' => count($this->validatePasses()),
                        'enrich' => count($this->enrichPasses()),
                        'analyze' => count($this->analyzePasses()),
                        'emit' => count($this->emitPasses()),
                    ],
                    'projection_emitters' => array_values(array_map(
                        static fn (ProjectionEmitter $emitter): string => $emitter->id(),
                        $this->projectionEmitters(),
                    )),
                ],
                'diagnostics' => [
                    'graph_analyzers' => array_values(array_map(
                        static fn (GraphAnalyzer $analyzer): string => $analyzer->id(),
                        $this->graphAnalyzers(),
                    )),
                    'doctor_checks' => array_values(array_map(
                        static fn (DoctorCheck $check): string => $check->id(),
                        $this->doctorChecks(),
                    )),
                    'migration_rules' => array_values(array_map(
                        static fn (MigrationRule $rule): string => $rule->id(),
                        $this->migrationRules(),
                    )),
                    'codemods' => array_values(array_map(
                        static fn (Codemod $codemod): string => $codemod->id(),
                        $this->codemods(),
                    )),
                ],
                'cli' => [
                    'inspect_surfaces' => $this->packHookValues($packs, 'inspectSurfaces', (array) (($descriptor['provides'] ?? [])['inspect_surfaces'] ?? [])),
                    'verifiers' => $this->packHookValues($packs, 'verifiers', (array) (($descriptor['provides'] ?? [])['verifiers'] ?? [])),
                    'generators' => $this->packHookValues($packs, 'generators'),
                    'docs_emitters' => $this->packHookValues($packs, 'docsEmitters'),
                ],
                'runtime' => [
                    'pipeline_stages' => array_values(array_map(
                        static fn (PipelineStageDefinition $stage): string => $stage->name,
                        $this->pipelineStages(),
                    )),
                    'pipeline_interceptors' => array_values(array_map(
                        static fn (StageInterceptor $interceptor): string => $interceptor->id(),
                        $this->pipelineInterceptors(),
                    )),
                ],
            ],
        ];
    }

    /**
     * @param array<int,PackDefinition> $packs
     * @param array<int,string> $seed
     * @return array<int,string>
     */
    private function packHookValues(array $packs, string $property, array $seed = []): array
    {
        $values = $seed;
        foreach ($packs as $pack) {
            foreach ((array) ($pack->{$property} ?? []) as $value) {
                $value = trim((string) $value);
                if ($value !== '') {
                    $values[] = $value;
                }
            }
        }

        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }
}
