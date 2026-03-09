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

interface CompilerExtension
{
    public function name(): string;

    public function version(): string;

    public function descriptor(): ExtensionDescriptor;

    /**
     * @return array<int,CompilerPass>
     */
    public function discoveryPasses(): array;

    /**
     * @return array<int,CompilerPass>
     */
    public function normalizePasses(): array;

    /**
     * @return array<int,CompilerPass>
     */
    public function linkPasses(): array;

    /**
     * @return array<int,CompilerPass>
     */
    public function validatePasses(): array;

    /**
     * @return array<int,CompilerPass>
     */
    public function enrichPasses(): array;

    /**
     * @return array<int,CompilerPass>
     */
    public function emitPasses(): array;

    /**
     * @return array<int,CompilerPass>
     */
    public function analyzePasses(): array;

    /**
     * @return array<int,ProjectionEmitter>
     */
    public function projectionEmitters(): array;

    /**
     * @return array<int,PackDefinition>
     */
    public function packs(): array;

    /**
     * @return array<int,MigrationRule>
     */
    public function migrationRules(): array;

    /**
     * @return array<int,SpecFormat>
     */
    public function specFormats(): array;

    /**
     * @return array<int,Codemod>
     */
    public function codemods(): array;

    /**
     * @return array<int,GraphAnalyzer>
     */
    public function graphAnalyzers(): array;

    /**
     * @return array<int,PipelineStageDefinition>
     */
    public function pipelineStages(): array;

    /**
     * @return array<int,StageInterceptor>
     */
    public function pipelineInterceptors(): array;

    public function passPriority(string $phase, CompilerPass $pass): int;

    /**
     * @return array<string,mixed>
     */
    public function describe(): array;
}
