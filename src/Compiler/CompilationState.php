<?php
declare(strict_types=1);

namespace Foundry\Compiler;

use Foundry\Compiler\Diagnostics\DiagnosticBag;
use Foundry\Compiler\Extensions\ExtensionRegistry;
use Foundry\Support\Paths;

final class CompilationState
{
    /**
     * @var array<string,array<string,mixed>>
     */
    public array $discoveredFeatures = [];

    /**
     * @var array<string,array<string,array<string,mixed>>>
     */
    public array $discoveredDefinitions = [];

    /**
     * @var array<string,mixed>
     */
    public array $analysis = [];

    /**
     * @var array<string,mixed>
     */
    public array $projections = [];

    /**
     * @var array<string,mixed>
     */
    public array $cache = [];

    /**
     * @var array<string,mixed>
     */
    public array $manifest = [];

    /**
     * @var array<string,array<string,mixed>>
     */
    public array $configSchemas = [];

    /**
     * @var array<string,mixed>
     */
    public array $configValidation = [];

    /**
     * @var array<string,string>
     */
    public array $integrityHashes = [];

    /**
     * @param array<string,string> $sourceHashes
     * @param array<string,mixed> $previousManifest
     */
    public function __construct(
        public readonly Paths $paths,
        public readonly BuildLayout $layout,
        public readonly CompileOptions $options,
        public readonly CompilePlan $plan,
        public readonly ExtensionRegistry $extensions,
        public readonly DiagnosticBag $diagnostics,
        public readonly ApplicationGraph $graph,
        public readonly array $sourceHashes,
        public readonly array $previousManifest,
    ) {
    }
}
