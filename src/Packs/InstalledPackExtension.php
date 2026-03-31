<?php

declare(strict_types=1);

namespace Foundry\Packs;

use Foundry\Compiler\Analysis\GraphAnalyzer;
use Foundry\Compiler\Codemod\Codemod;
use Foundry\Compiler\CompilerPass;
use Foundry\Compiler\Extensions\AbstractCompilerExtension;
use Foundry\Compiler\Extensions\CompilerExtension;
use Foundry\Compiler\Extensions\ExtensionDescriptor;
use Foundry\Compiler\Extensions\PackDefinition;
use Foundry\Compiler\Migration\DefinitionFormat;
use Foundry\Compiler\Migration\MigrationRule;
use Foundry\Compiler\Projection\ProjectionEmitter;
use Foundry\Doctor\DoctorCheck;
use Foundry\Pipeline\PipelineStageDefinition;
use Foundry\Pipeline\StageInterceptor;

final class InstalledPackExtension extends AbstractCompilerExtension
{
    public function __construct(
        private readonly PackManifest $manifest,
        private readonly PackContext $context,
        private readonly ?CompilerExtension $inner = null,
        private readonly array $source = [],
    ) {}

    public function name(): string
    {
        return 'pack.' . str_replace('/', '.', $this->manifest->name);
    }

    public function version(): string
    {
        return $this->manifest->version;
    }

    public function descriptor(): ExtensionDescriptor
    {
        $inner = $this->inner?->descriptor();

        return new ExtensionDescriptor(
            name: $this->name(),
            version: $this->version(),
            description: $this->manifest->description,
            frameworkVersionConstraint: $inner?->frameworkVersionConstraint ?? '*',
            graphVersionConstraint: $inner?->graphVersionConstraint ?? '*',
            providedNodeTypes: $inner?->providedNodeTypes ?? [],
            providedPasses: $this->providedPasses(),
            providedPacks: [$this->manifest->name],
            introducedDefinitionFormats: $inner?->introducedDefinitionFormats ?? [],
            providedMigrationRules: $inner?->providedMigrationRules ?? [],
            providedCodemods: $inner?->providedCodemods ?? [],
            providedProjectionOutputs: $inner?->providedProjectionOutputs ?? [],
            providedInspectSurfaces: $this->mergeStrings($inner?->providedInspectSurfaces ?? [], ['packs']),
            providedVerifiers: $inner?->providedVerifiers ?? [],
            providedCapabilities: $this->mergeStrings($inner?->providedCapabilities ?? [], $this->manifest->capabilities),
            requiredExtensions: $inner?->requiredExtensions ?? [],
            optionalExtensions: $inner?->optionalExtensions ?? [],
            conflictsWithExtensions: $inner?->conflictsWithExtensions ?? [],
        );
    }

    public function discoveryPasses(): array
    {
        return $this->inner?->discoveryPasses() ?? [];
    }

    public function normalizePasses(): array
    {
        return $this->inner?->normalizePasses() ?? [];
    }

    public function linkPasses(): array
    {
        return $this->inner?->linkPasses() ?? [];
    }

    public function validatePasses(): array
    {
        return $this->inner?->validatePasses() ?? [];
    }

    public function enrichPasses(): array
    {
        return $this->inner?->enrichPasses() ?? [];
    }

    public function emitPasses(): array
    {
        return $this->inner?->emitPasses() ?? [];
    }

    public function analyzePasses(): array
    {
        return $this->inner?->analyzePasses() ?? [];
    }

    public function projectionEmitters(): array
    {
        return $this->inner?->projectionEmitters() ?? [];
    }

    public function packs(): array
    {
        return [
            new PackDefinition(
                name: $this->manifest->name,
                version: $this->manifest->version,
                extension: $this->name(),
                description: $this->manifest->description,
                providedCapabilities: $this->manifest->capabilities,
                generators: $this->context->contributions()['generators'] ?? [],
                frameworkVersionConstraint: $this->inner?->descriptor()->frameworkVersionConstraint ?? '*',
                graphVersionConstraint: $this->inner?->descriptor()->graphVersionConstraint ?? '*',
            ),
        ];
    }

    public function migrationRules(): array
    {
        return $this->inner?->migrationRules() ?? [];
    }

    public function definitionFormats(): array
    {
        return $this->inner?->definitionFormats() ?? [];
    }

    public function codemods(): array
    {
        return $this->inner?->codemods() ?? [];
    }

    public function graphAnalyzers(): array
    {
        return $this->inner?->graphAnalyzers() ?? [];
    }

    public function doctorChecks(): array
    {
        return $this->inner?->doctorChecks() ?? [];
    }

    public function pipelineStages(): array
    {
        return $this->inner?->pipelineStages() ?? [];
    }

    public function pipelineInterceptors(): array
    {
        return $this->inner?->pipelineInterceptors() ?? [];
    }

    public function passPriority(string $stage, CompilerPass $pass): int
    {
        return $this->inner?->passPriority($stage, $pass) ?? parent::passPriority($stage, $pass);
    }

    public function describe(): array
    {
        $description = $this->inner?->describe() ?? parent::describe();
        $description['name'] = $this->name();
        $description['version'] = $this->version();
        $description['description'] = $this->manifest->description;
        $description['packs'] = [$this->manifest->name];
        $description['pack_manifest'] = $this->manifest->toArray();
        $description['declared_contributions'] = $this->context->contributions();
        $description['pack_source'] = $this->source;

        return $description;
    }

    /**
     * @return array<int,string>
     */
    private function providedPasses(): array
    {
        $passes = [];
        foreach ([
            'discovery' => $this->discoveryPasses(),
            'normalize' => $this->normalizePasses(),
            'link' => $this->linkPasses(),
            'validate' => $this->validatePasses(),
            'enrich' => $this->enrichPasses(),
            'analyze' => $this->analyzePasses(),
            'emit' => $this->emitPasses(),
        ] as $stage => $registered) {
            if ($registered !== []) {
                $passes[] = $stage;
            }
        }

        sort($passes);

        return $passes;
    }

    /**
     * @param array<int,string> $left
     * @param array<int,string> $right
     * @return array<int,string>
     */
    private function mergeStrings(array $left, array $right): array
    {
        $values = array_values(array_unique(array_merge(
            array_values(array_filter(array_map('strval', $left), static fn(string $value): bool => $value !== '')),
            array_values(array_filter(array_map('strval', $right), static fn(string $value): bool => $value !== '')),
        )));
        sort($values);

        return $values;
    }
}
