<?php
declare(strict_types=1);

namespace Foundry\Compiler;

use Foundry\Compiler\Analysis\ImpactAnalyzer;
use Foundry\Compiler\Diagnostics\DiagnosticBag;
use Foundry\Compiler\Extensions\ExtensionRegistry;
use Foundry\Compiler\Passes\AnalyzePass;
use Foundry\Compiler\Passes\DiscoveryPass;
use Foundry\Compiler\Passes\EmitPass;
use Foundry\Compiler\Passes\EnrichPass;
use Foundry\Compiler\Passes\LinkPass;
use Foundry\Compiler\Passes\NormalizePass;
use Foundry\Compiler\Passes\ValidatePass;
use Foundry\Support\Json;
use Foundry\Support\Paths;

final class GraphCompiler
{
    public const GRAPH_VERSION = 1;

    private readonly BuildLayout $layout;
    private readonly SourceScanner $sourceScanner;
    private readonly CompilePlanner $planner;
    private readonly ImpactAnalyzer $impactAnalyzer;

    public function __construct(
        private readonly Paths $paths,
        private readonly ?ExtensionRegistry $extensions = null,
    ) {
        $this->layout = new BuildLayout($paths);
        $this->sourceScanner = new SourceScanner($paths);
        $this->planner = new CompilePlanner();
        $this->impactAnalyzer = new ImpactAnalyzer($paths);
    }

    public function compile(CompileOptions $options = new CompileOptions()): CompileResult
    {
        $frameworkVersion = $this->detectedFrameworkVersion();

        $currentFeatures = array_map('basename', glob($this->paths->features() . '/*', GLOB_ONLYDIR) ?: []);
        sort($currentFeatures);

        $sourceFiles = $this->sourceScanner->sourceFiles();
        $sourceHashes = $this->sourceScanner->hashFiles($sourceFiles);
        $sourceHash = $this->sourceScanner->aggregateHash($sourceHashes);

        $previousManifest = $this->readJson($this->layout->compileManifestPath()) ?? [];
        $previousGraph = $this->loadPreviousGraph();

        $plan = $this->planner->plan(
            options: $options,
            previousManifest: $previousManifest,
            currentSourceHashes: $sourceHashes,
            currentFeatures: $currentFeatures,
            hasPreviousGraph: $previousGraph !== null,
            scanner: $this->sourceScanner,
            frameworkVersion: $frameworkVersion,
        );

        if ($plan->noChanges && $previousGraph !== null) {
            $diagnostics = new DiagnosticBag();
            $diagnostics->info(
                code: 'FDY0001_NO_CHANGES',
                category: 'graph',
                message: 'No source changes detected; reusing existing build artifacts.',
                pass: 'compile',
            );

            $integrity = $this->readJson($this->layout->integrityHashesPath()) ?? [];

            return new CompileResult(
                graph: $previousGraph,
                diagnostics: $diagnostics,
                plan: $plan,
                manifest: $previousManifest,
                integrityHashes: array_map('strval', $integrity),
                projections: [],
                writtenFiles: [],
            );
        }

        $graph = $this->newGraph($frameworkVersion, $sourceHash);

        if ($plan->incremental && !$plan->fallbackToFull && $previousGraph !== null) {
            foreach ($previousGraph->nodesByType('feature') as $featureNode) {
                $feature = (string) ($featureNode->payload()['feature'] ?? '');
                if ($feature === '' || in_array($feature, $plan->selectedFeatures, true)) {
                    continue;
                }

                $graph->addNode($featureNode);
            }
        }

        $diagnostics = new DiagnosticBag();
        $extensions = $this->extensionRegistry();
        $compatibility = $extensions->compatibilityReport($frameworkVersion, self::GRAPH_VERSION);
        foreach ($compatibility->diagnostics as $row) {
            if (!is_array($row)) {
                continue;
            }

            $severity = (string) ($row['severity'] ?? 'warning');
            $code = (string) ($row['code'] ?? 'FDY7999_EXTENSION_COMPATIBILITY');
            $category = (string) ($row['category'] ?? 'extensions');
            $message = (string) ($row['message'] ?? 'Extension compatibility issue detected.');

            if ($severity === 'error') {
                $diagnostics->error($code, $category, $message, pass: 'extensions.compatibility');
            } elseif ($severity === 'info') {
                $diagnostics->info($code, $category, $message, pass: 'extensions.compatibility');
            } else {
                $diagnostics->warning($code, $category, $message, pass: 'extensions.compatibility');
            }
        }

        $state = new CompilationState(
            paths: $this->paths,
            layout: $this->layout,
            options: $options,
            plan: $plan,
            extensions: $extensions,
            diagnostics: $diagnostics,
            graph: $graph,
            sourceHashes: $sourceHashes,
            previousManifest: $previousManifest,
        );
        $state->analysis['compatibility'] = $compatibility->toArray();

        $passes = $this->buildPassRows($state);
        foreach ($passes as $row) {
            $pass = $row['pass'];
            $extension = $row['extension'];
            $stage = $row['stage'];

            try {
                $pass->run($state);
            } catch (\Throwable $error) {
                if ($extension === null) {
                    throw $error;
                }

                $state->diagnostics->error(
                    code: 'FDY7020_EXTENSION_GRAPH_INTEGRATION_FAILED',
                    category: 'extensions',
                    message: sprintf(
                        'Extension %s failed during %s with %s: %s',
                        $extension,
                        $stage,
                        get_class($pass),
                        $error->getMessage(),
                    ),
                    pass: 'extensions.' . $stage,
                );
            }
        }

        $writtenFiles = array_values(array_map(
            'strval',
            (array) ($state->analysis['written_files'] ?? []),
        ));

        return new CompileResult(
            graph: $state->graph,
            diagnostics: $state->diagnostics,
            plan: $state->plan,
            manifest: $state->manifest,
            integrityHashes: $state->integrityHashes,
            projections: $state->projections,
            writtenFiles: $writtenFiles,
        );
    }

    public function loadGraph(): ?ApplicationGraph
    {
        return $this->loadPreviousGraph();
    }

    public function impactAnalyzer(): ImpactAnalyzer
    {
        return $this->impactAnalyzer;
    }

    public function buildLayout(): BuildLayout
    {
        return $this->layout;
    }

    public function frameworkVersion(): string
    {
        return $this->detectedFrameworkVersion();
    }

    public function extensionRegistry(): ExtensionRegistry
    {
        return $this->extensions ?? ExtensionRegistry::forPaths($this->paths);
    }

    /**
     * @return array<int,array{pass:CompilerPass,stage:string,extension:?string}>
     */
    private function buildPassRows(CompilationState $state): array
    {
        $extensions = $state->extensions;

        $passes = [];

        $passes[] = ['pass' => new DiscoveryPass(), 'stage' => 'discovery', 'extension' => null];
        $passes = array_merge($passes, $this->extensionStagePassRows($extensions->all(), 'discovery'));

        $passes[] = ['pass' => new NormalizePass(), 'stage' => 'normalize', 'extension' => null];
        $passes = array_merge($passes, $this->extensionStagePassRows($extensions->all(), 'normalize'));

        $passes[] = ['pass' => new LinkPass(), 'stage' => 'link', 'extension' => null];
        $passes = array_merge($passes, $this->extensionStagePassRows($extensions->all(), 'link'));

        $passes[] = ['pass' => new ValidatePass(), 'stage' => 'validate', 'extension' => null];
        $passes = array_merge($passes, $this->extensionStagePassRows($extensions->all(), 'validate'));

        $passes[] = ['pass' => new EnrichPass(), 'stage' => 'enrich', 'extension' => null];
        $passes = array_merge($passes, $this->extensionStagePassRows($extensions->all(), 'enrich'));

        $passes[] = ['pass' => new AnalyzePass($this->impactAnalyzer), 'stage' => 'analyze', 'extension' => null];
        $passes = array_merge($passes, $this->extensionStagePassRows($extensions->all(), 'analyze'));

        $passes[] = ['pass' => new EmitPass(), 'stage' => 'emit', 'extension' => null];
        $passes = array_merge($passes, $this->extensionStagePassRows($extensions->all(), 'emit'));

        return $passes;
    }

    /**
     * @param array<int,\Foundry\Compiler\Extensions\CompilerExtension> $extensions
     * @return array<int,array{pass:CompilerPass,stage:string,extension:string}>
     */
    private function extensionStagePassRows(array $extensions, string $stage): array
    {
        $rows = [];
        foreach (array_values($extensions) as $loadIndex => $extension) {
            $passes = match ($stage) {
                'discovery' => $extension->discoveryPasses(),
                'normalize' => $extension->normalizePasses(),
                'link' => $extension->linkPasses(),
                'validate' => $extension->validatePasses(),
                'enrich' => $extension->enrichPasses(),
                'analyze' => $extension->analyzePasses(),
                'emit' => $extension->emitPasses(),
                default => [],
            };

            foreach ($passes as $pass) {
                $rows[] = [
                    'extension' => $extension->name(),
                    'stage' => $stage,
                    'load_index' => $loadIndex,
                    'priority' => $extension->passPriority($stage, $pass),
                    'pass' => $pass,
                ];
            }
        }

        usort(
            $rows,
            static fn (array $a, array $b): int => ((int) ($a['priority'] ?? 0) <=> (int) ($b['priority'] ?? 0))
                ?: ((int) ($a['load_index'] ?? PHP_INT_MAX) <=> (int) ($b['load_index'] ?? PHP_INT_MAX))
                ?: strcmp((string) ($a['extension'] ?? ''), (string) ($b['extension'] ?? ''))
                ?: strcmp(get_class($a['pass']), get_class($b['pass'])),
        );

        return array_values(array_map(
            static fn (array $row): array => [
                'pass' => $row['pass'],
                'stage' => (string) $row['stage'],
                'extension' => (string) $row['extension'],
            ],
            $rows,
        ));
    }

    private function newGraph(string $frameworkVersion, string $sourceHash): ApplicationGraph
    {
        return new ApplicationGraph(
            graphVersion: self::GRAPH_VERSION,
            frameworkVersion: $frameworkVersion,
            compiledAt: gmdate(DATE_ATOM),
            sourceHash: $sourceHash,
            metadata: [],
        );
    }

    private function loadPreviousGraph(): ?ApplicationGraph
    {
        $data = $this->readJson($this->layout->graphJsonPath());
        if ($data === null) {
            return null;
        }

        return ApplicationGraph::fromArray($data);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return null;
        }

        try {
            return Json::decodeAssoc($content);
        } catch (\Throwable) {
            return null;
        }
    }

    private function detectedFrameworkVersion(): string
    {
        $composerPath = $this->paths->frameworkJoin('composer.json');
        if (!is_file($composerPath)) {
            return 'dev-main';
        }

        $content = file_get_contents($composerPath);
        if ($content === false) {
            return 'dev-main';
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return 'dev-main';
        }

        $version = $decoded['version'] ?? null;

        return is_string($version) && $version !== '' ? $version : 'dev-main';
    }
}
