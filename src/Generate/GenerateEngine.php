<?php

declare(strict_types=1);

namespace Foundry\Generate;

use Foundry\CLI\Application;
use Foundry\Compiler\ApplicationGraph;
use Foundry\Compiler\CompileOptions;
use Foundry\Compiler\Extensions\ExtensionRegistry;
use Foundry\Compiler\GraphCompiler;
use Foundry\Confidence\ConfidenceEngine;
use Foundry\Explain\Diff\ExplainDiffService;
use Foundry\Explain\ExplainModel;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainOrigin;
use Foundry\Explain\ExplainResponse;
use Foundry\Explain\ExplainSupport;
use Foundry\Explain\ExplainTarget;
use Foundry\Explain\Snapshot\ExplainSnapshotService;
use Foundry\Generation\ContextManifestGenerator;
use Foundry\Generation\FeatureGenerator;
use Foundry\Generation\TestGenerator;
use Foundry\Packs\PackManager;
use Foundry\Pro\ArchitectureExplainer;
use Foundry\Support\ApiSurfaceRegistry;
use Foundry\Support\FoundryError;
use Foundry\Support\Paths;
use Foundry\Support\Yaml;

final class GenerateEngine
{
    private readonly PackManager $packManager;
    private readonly CodeWriter $codeWriter;
    private readonly ApiSurfaceRegistry $apiSurfaceRegistry;
    private readonly ExplainSnapshotService $snapshotService;
    private readonly ExplainDiffService $diffService;
    private readonly ConfidenceEngine $confidenceEngine;

    public function __construct(
        private readonly Paths $paths,
        ?PackManager $packManager = null,
        ?CodeWriter $codeWriter = null,
        ?ApiSurfaceRegistry $apiSurfaceRegistry = null,
        ?ExplainSnapshotService $snapshotService = null,
        ?ExplainDiffService $diffService = null,
    ) {
        $this->packManager = $packManager ?? new PackManager($paths);
        $this->codeWriter = $codeWriter ?? new CodeWriter();
        $this->apiSurfaceRegistry = $apiSurfaceRegistry ?? new ApiSurfaceRegistry();
        $this->snapshotService = $snapshotService ?? new ExplainSnapshotService($paths, $this->apiSurfaceRegistry);
        $this->diffService = $diffService ?? new ExplainDiffService($paths, $this->snapshotService);
        $this->confidenceEngine = new ConfidenceEngine();
    }

    /**
     * @return array<string,mixed>
     */
    public function run(Intent $intent): array
    {
        $initialExtensions = ExtensionRegistry::forPaths($this->paths);
        $requirementResolver = new PackRequirementResolver();
        $initialRequirements = $requirementResolver->resolve($intent, $initialExtensions->packRegistry());
        $preSnapshot = null;
        if (!$intent->dryRun) {
            $initialCompiler = new GraphCompiler($this->paths, $initialExtensions);
            $initialCompile = $initialCompiler->compile(new CompileOptions(emit: true));
            $preSnapshot = $this->snapshotService->capture(
                'pre-generate',
                $initialCompile->graph,
                $initialExtensions,
                GeneratorRegistry::forExtensions($initialExtensions),
                $this->resolveTarget($intent, $initialCompile->graph, $initialExtensions),
            );
        }

        $packsInstalled = [];
        $packSnapshots = $intent->dryRun
            ? []
            : $this->codeWriter->snapshot([$this->paths->join('.foundry/packs/installed.json')]);
        $iterationSnapshots = $intent->dryRun
            ? []
            : $this->codeWriter->snapshot([
                $this->snapshotService->snapshotPath('post-generate'),
                $this->diffService->lastDiffPath(),
            ]);
        $fileSnapshots = [];

        try {
            if ($initialRequirements['suggested_packs'] !== []) {
                if ($intent->dryRun || !$intent->allowPackInstall) {
                    throw new FoundryError(
                        'GENERATE_PACK_INSTALL_REQUIRED',
                        'validation',
                        [
                            'missing_capabilities' => $initialRequirements['missing_capabilities'],
                            'suggested_packs' => $initialRequirements['suggested_packs'],
                        ],
                        'Required packs are not installed. Re-run with --allow-pack-install or install them first.',
                    );
                }

                foreach ($initialRequirements['suggested_packs'] as $pack) {
                    $packsInstalled[] = $this->packManager->install($pack);
                }
            }

            $extensions = ExtensionRegistry::forPaths($this->paths);
            $compiler = new GraphCompiler($this->paths, $extensions);
            $compile = $compiler->compile(new CompileOptions(emit: true));

            if ($compile->diagnostics->hasErrors() && $intent->mode !== 'repair' && !$intent->allowRisky) {
                throw new FoundryError(
                    'GENERATE_PRECONDITION_FAILED',
                    'validation',
                    ['compile' => $compile->toArray()],
                    'The current graph has errors. Repair the system first or re-run with --allow-risky.',
                );
            }

            $target = $this->resolveTarget($intent, $compile->graph, $extensions);
            $model = $this->buildExplainModel($compiler, $extensions, $compile->graph, $target);
            $generatorRegistry = GeneratorRegistry::forExtensions($extensions);
            $requirements = $requirementResolver->resolve($intent, $extensions->packRegistry());
            $context = new GenerationContextPacket(
                intent: $intent,
                model: $model,
                targets: [
                    [
                        'requested' => $intent->target,
                        'resolved' => $target,
                        'subject' => [
                            'id' => $model->subject['id'] ?? 'system:root',
                            'kind' => $model->subject['kind'] ?? 'system',
                            'origin' => $model->subject['origin'] ?? 'core',
                            'extension' => $model->subject['extension'] ?? null,
                        ],
                    ],
                ],
                graphRelationships: is_array($model->relationships['graph'] ?? null) ? $model->relationships['graph'] : [],
                constraints: $this->constraintsFor($intent, $model),
                docs: array_values(array_filter((array) ($model->docs['related'] ?? []), 'is_array')),
                validationSteps: ['compile_graph', 'doctor', 'verify_graph', 'verify_contracts'],
                availableGenerators: array_values(array_map(
                    static fn(RegisteredGenerator $generator): array => $generator->toArray(),
                    $generatorRegistry->all(),
                )),
                installedPacks: $extensions->packRegistry()->inspectRows(),
                missingCapabilities: $requirements['missing_capabilities'],
                suggestedPacks: $requirements['suggested_packs'],
            );

            $plan = (new GenerationPlanner($generatorRegistry))->plan($context);
            (new PlanValidator())->validate($plan, $intent);
            $plan = $plan->withConfidence($this->confidenceEngine->plan($context, $plan));

            if ($intent->dryRun) {
                $outcomeConfidence = $this->confidenceEngine->outcome(
                    $intent,
                    $plan,
                    [],
                    ['skipped' => true, 'ok' => true],
                );

                return $this->buildPayload(
                    intent: $intent,
                    plan: $plan,
                    actionsTaken: [],
                    verificationResults: ['skipped' => true, 'ok' => true],
                    outcomeConfidence: $outcomeConfidence,
                    errors: [],
                    context: $context,
                    packsInstalled: $packsInstalled,
                );
            }

            $fileSnapshots = $this->codeWriter->snapshot($this->absolutePaths($plan->affectedFiles));
            $actionsTaken = $this->executePlan($plan, $intent);
            $verificationResults = $intent->skipVerify
                ? ['skipped' => true, 'ok' => true]
                : $this->runVerification($plan);

            if (($verificationResults['ok'] ?? false) !== true) {
                throw new FoundryError(
                    'GENERATE_VERIFICATION_FAILED',
                    'validation',
                    [
                        'plan' => $plan->toArray(),
                        'verification_results' => $verificationResults,
                    ],
                    'Generation was rolled back because verification failed.',
                );
            }

            $postExtensions = ExtensionRegistry::forPaths($this->paths);
            $postCompiler = new GraphCompiler($this->paths, $postExtensions);
            $postCompile = $postCompiler->compile(new CompileOptions(emit: true));
            $postTarget = $this->postGenerateTarget($plan, $context, $postCompile->graph);
            $postSnapshot = $this->snapshotService->capture(
                'post-generate',
                $postCompile->graph,
                $postExtensions,
                GeneratorRegistry::forExtensions($postExtensions),
                $postTarget,
            );
            $architectureDiff = $preSnapshot !== null
                ? $this->diffService->compare($preSnapshot, $postSnapshot)
                : null;
            if ($architectureDiff !== null) {
                $this->diffService->storeLast($architectureDiff);
            }

            $postExplain = null;
            $postExplainRendered = null;
            if ($intent->explainAfter) {
                $response = $this->buildExplainResponse($postCompiler, $postExtensions, $postCompile->graph, $postTarget);
                $postExplain = $response?->toArray();
                $postExplainRendered = $response?->rendered;
            }

            $outcomeConfidence = $this->confidenceEngine->outcome(
                $intent,
                $plan,
                $actionsTaken,
                $verificationResults,
                $architectureDiff,
                $packsInstalled,
            );

            return $this->buildPayload(
                intent: $intent,
                plan: $plan,
                actionsTaken: $actionsTaken,
                verificationResults: $verificationResults,
                outcomeConfidence: $outcomeConfidence,
                errors: [],
                context: $context,
                packsInstalled: $packsInstalled,
                snapshots: [
                    'pre' => $this->relativePath($this->snapshotService->snapshotPath('pre-generate')),
                    'post' => $this->relativePath($this->snapshotService->snapshotPath('post-generate')),
                    'diff' => $this->relativePath($this->diffService->lastDiffPath()),
                ],
                architectureDiff: $architectureDiff,
                postExplain: $postExplain,
                postExplainRendered: $postExplainRendered,
            );
        } catch (\Throwable $error) {
            $this->restoreGenerateState($packSnapshots, $fileSnapshots, $iterationSnapshots);

            throw $error;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function buildPayload(
        Intent $intent,
        GenerationPlan $plan,
        array $actionsTaken,
        array $verificationResults,
        array $outcomeConfidence,
        array $errors,
        GenerationContextPacket $context,
        array $packsInstalled,
        array $snapshots = [],
        ?array $architectureDiff = null,
        ?array $postExplain = null,
        ?string $postExplainRendered = null,
    ): array {
        $packsUsed = $plan->extension !== null ? [$plan->extension] : [];

        return [
            'ok' => true,
            'intent' => $intent->raw,
            'mode' => $intent->mode,
            'plan' => $plan->toArray(),
            'plan_confidence' => $plan->confidence,
            'outcome_confidence' => $outcomeConfidence,
            'actions_taken' => $actionsTaken,
            'verification_results' => $verificationResults,
            'errors' => $errors,
            'metadata' => [
                'dry_run' => $intent->dryRun,
                'target' => $context->targets[0] ?? null,
                'context' => $context->toArray(),
            ],
            'snapshots' => $snapshots,
            'architecture_diff' => $architectureDiff,
            'post_explain' => $postExplain,
            'post_explain_rendered' => $postExplainRendered,
            'packs_used' => $packsUsed,
            'packs_installed' => $packsInstalled,
        ];
    }

    private function resolveTarget(Intent $intent, ApplicationGraph $graph, ExtensionRegistry $extensions): ?string
    {
        $requested = trim((string) ($intent->target ?? ''));
        if ($requested !== '') {
            return $requested;
        }

        if ($intent->packHints !== []) {
            foreach ($intent->packHints as $pack) {
                if ($extensions->packRegistry()->has($pack)) {
                    return 'pack:' . $pack;
                }
            }
        }

        $closestFeature = $this->closestFeature($graph, $intent);
        if ($closestFeature !== null) {
            return 'feature:' . $closestFeature;
        }

        return null;
    }

    private function buildExplainModel(
        GraphCompiler $compiler,
        ExtensionRegistry $extensions,
        ApplicationGraph $graph,
        ?string $target,
    ): ExplainModel {
        if ($target === null || trim($target) === '') {
            return $this->emptyModel($extensions);
        }

        $response = (new ArchitectureExplainer(
            paths: $this->paths,
            impactAnalyzer: $compiler->impactAnalyzer(),
            apiSurfaceRegistry: $this->apiSurfaceRegistry,
            extensionRows: $extensions->inspectRows(),
        ))->explain($graph, ExplainTarget::parse($target), new ExplainOptions());

        return $response->plan->model;
    }

    private function buildExplainResponse(
        GraphCompiler $compiler,
        ExtensionRegistry $extensions,
        ApplicationGraph $graph,
        ?string $target,
    ): ?ExplainResponse {
        $resolvedTarget = $target ?? ExplainSupport::defaultTargetOrNull($graph);
        if ($resolvedTarget === null || trim($resolvedTarget) === '') {
            return null;
        }

        return (new ArchitectureExplainer(
            paths: $this->paths,
            impactAnalyzer: $compiler->impactAnalyzer(),
            apiSurfaceRegistry: $this->apiSurfaceRegistry,
            extensionRows: $extensions->inspectRows(),
        ))->explain($graph, ExplainTarget::parse($resolvedTarget), new ExplainOptions());
    }

    private function postGenerateTarget(GenerationPlan $plan, GenerationContextPacket $context, ApplicationGraph $graph): ?string
    {
        $feature = trim((string) ($plan->metadata['feature'] ?? ''));
        if ($feature !== '') {
            return 'feature:' . $feature;
        }

        $resolved = trim((string) ($context->targets[0]['resolved'] ?? ''));
        if ($resolved !== '') {
            return $resolved;
        }

        return ExplainSupport::defaultTargetOrNull($graph);
    }

    /**
     * @return array<int,string>
     */
    private function constraintsFor(Intent $intent, ExplainModel $model): array
    {
        $constraints = [
            'Generate plans must remain deterministic and explain-traceable.',
            'Generate may not mutate extension-owned nodes implicitly.',
        ];

        if ($intent->dryRun) {
            $constraints[] = 'Dry-run mode may not write files or install packs.';
        }

        if (((string) ($model->subject['origin'] ?? 'core')) === 'extension') {
            $constraints[] = 'Extension-owned targets require explicit pack-aware generators.';
        }

        sort($constraints);

        return array_values(array_unique($constraints));
    }

    /**
     * @return array<int,string>
     */
    private function absolutePaths(array $paths): array
    {
        $absolute = [];
        foreach ($paths as $path) {
            $absolute[] = $this->absolutePath((string) $path);
        }

        $absolute = array_values(array_unique($absolute));
        sort($absolute);

        return $absolute;
    }

    private function absolutePath(string $path): string
    {
        return str_starts_with($path, $this->paths->root() . '/')
            ? $path
            : $this->paths->join($path);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function executePlan(GenerationPlan $plan, Intent $intent): array
    {
        $execution = is_array($plan->metadata['execution'] ?? null) ? $plan->metadata['execution'] : [];
        $strategy = (string) ($execution['strategy'] ?? '');

        return match ($strategy) {
            'feature_definition' => $this->executeFeatureDefinition($execution, $plan, $intent),
            'modify_feature' => $this->executeModifyFeature($execution),
            'repair_feature' => $this->executeRepairFeature($execution),
            default => throw new FoundryError(
                'GENERATE_PLAN_INVALID',
                'validation',
                ['plan' => $plan->toArray()],
                'Generation plan execution strategy is missing or invalid.',
            ),
        };
    }

    /**
     * @param array<string,mixed> $execution
     * @return array<int,array<string,mixed>>
     */
    private function executeFeatureDefinition(array $execution, GenerationPlan $plan, Intent $intent): array
    {
        $definition = is_array($execution['feature_definition'] ?? null) ? $execution['feature_definition'] : [];
        if ($definition === []) {
            throw new FoundryError(
                'GENERATE_PLAN_INVALID',
                'validation',
                ['execution' => $execution],
                'Feature-definition execution is missing the feature definition payload.',
            );
        }

        $files = (new FeatureGenerator($this->paths))->generateFromArray($definition, $intent->allowRisky);
        sort($files);

        return array_values(array_map(
            fn(string $path): array => [
                'type' => 'write_file',
                'path' => $this->relativePath($path),
                'status' => 'written',
                'origin' => $plan->origin,
                'extension' => $plan->extension,
            ],
            $files,
        ));
    }

    /**
     * @param array<string,mixed> $execution
     * @return array<int,array<string,mixed>>
     */
    private function executeModifyFeature(array $execution): array
    {
        $manifestPath = $this->absolutePath((string) ($execution['manifest_path'] ?? ''));
        $manifest = is_array($execution['manifest'] ?? null) ? $execution['manifest'] : [];
        $promptsPath = $this->absolutePath((string) ($execution['prompts_path'] ?? ''));
        $promptsContent = (string) ($execution['prompts_content'] ?? '');

        $actions = [];
        if ($manifest !== []) {
            $written = $this->codeWriter->syncFile($manifestPath, Yaml::dump($manifest));
            $actions[] = [
                'type' => 'update_file',
                'path' => $this->relativePath($manifestPath),
                'status' => $written ? 'written' : 'unchanged',
            ];
        }

        if ($promptsContent !== '') {
            $written = $this->codeWriter->syncFile($promptsPath, $promptsContent);
            $actions[] = [
                'type' => 'update_docs',
                'path' => $this->relativePath($promptsPath),
                'status' => $written ? 'written' : 'unchanged',
            ];
        }

        return $actions;
    }

    /**
     * @param array<string,mixed> $execution
     * @return array<int,array<string,mixed>>
     */
    private function executeRepairFeature(array $execution): array
    {
        $feature = trim((string) ($execution['feature'] ?? ''));
        $basePath = $this->absolutePath((string) ($execution['base_path'] ?? ('app/features/' . $feature)));
        $manifest = is_array($execution['manifest'] ?? null) ? $execution['manifest'] : [];
        $missingTests = array_values(array_map('strval', (array) ($execution['missing_tests'] ?? [])));
        $restoreContextManifest = (bool) ($execution['restore_context_manifest'] ?? false);
        $actions = [];

        if ($missingTests !== []) {
            $written = (new TestGenerator())->generate($feature, $basePath, $missingTests);
            sort($written);
            foreach ($written as $path) {
                $actions[] = [
                    'type' => 'add_test',
                    'path' => $this->relativePath($path),
                    'status' => 'written',
                ];
            }
        }

        if ($restoreContextManifest) {
            $path = (new ContextManifestGenerator($this->paths))->write($feature, $manifest);
            $actions[] = [
                'type' => 'create_file',
                'path' => $this->relativePath($path),
                'status' => 'written',
            ];
        }

        return $actions;
    }

    /**
     * @return array<string,mixed>
     */
    private function runVerification(GenerationPlan $plan): array
    {
        $results = [
            'compile_graph' => $this->runCliCommand(['foundry', 'compile', 'graph', '--json']),
            'doctor' => $this->runCliCommand(['foundry', 'doctor', '--json']),
            'verify_graph' => $this->runCliCommand(['foundry', 'verify', 'graph', '--json']),
            'verify_contracts' => $this->runCliCommand(['foundry', 'verify', 'contracts', '--json']),
        ];

        $feature = trim((string) ($plan->metadata['feature'] ?? ''));
        if ($feature !== '') {
            $results['verify_feature'] = $this->runCliCommand(['foundry', 'verify', 'feature', $feature, '--json']);
        }

        $ok = true;
        foreach ($results as $result) {
            if (!is_array($result) || ((int) ($result['status'] ?? 1)) !== 0) {
                $ok = false;
                break;
            }
        }

        $results['ok'] = $ok;

        return $results;
    }

    /**
     * @param array<int,string> $argv
     * @return array<string,mixed>
     */
    private function runCliCommand(array $argv): array
    {
        ob_start();
        $status = (new Application())->run($argv);
        $output = ob_get_clean() ?: '';

        try {
            /** @var array<string,mixed> $payload */
            $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $payload = ['raw_output' => $output];
        }

        return [
            'status' => $status,
            'payload' => $payload,
        ];
    }

    private function rebuildAfterRestore(): void
    {
        $this->runCliCommand(['foundry', 'compile', 'graph', '--json']);
    }

    private function closestFeature(ApplicationGraph $graph, Intent $intent): ?string
    {
        $tokens = $intent->tokens();
        $bestFeature = null;
        $bestScore = -1;

        foreach ($graph->nodesByType('feature') as $node) {
            $payload = $node->payload();
            $feature = trim((string) ($payload['feature'] ?? ''));
            if ($feature === '') {
                continue;
            }

            $haystacks = array_merge(
                explode('_', $feature),
                $this->routeTokens((string) ($payload['route']['path'] ?? '')),
            );
            $haystacks = array_values(array_unique(array_filter(array_map('strval', $haystacks))));
            $score = count(array_intersect($tokens, $haystacks));

            if ($score > $bestScore || ($score === $bestScore && ($bestFeature === null || strcmp($feature, $bestFeature) < 0))) {
                $bestFeature = $feature;
                $bestScore = $score;
            }
        }

        return $bestFeature;
    }

    /**
     * @return array<int,string>
     */
    private function routeTokens(string $route): array
    {
        $route = preg_replace('/[^a-z0-9]+/i', ' ', strtolower($route)) ?? strtolower($route);
        $tokens = [];
        foreach (explode(' ', $route) as $token) {
            $token = trim($token);
            if ($token === '' || str_starts_with($token, '{')) {
                continue;
            }

            $tokens[] = $token;
        }

        return array_values(array_unique($tokens));
    }

    private function emptyModel(ExtensionRegistry $extensions): ExplainModel
    {
        $extensionRows = [];
        foreach ($extensions->packRegistry()->all() as $pack) {
            $extensionRows[] = [
                'name' => $pack->name,
                'version' => $pack->version,
                'type' => 'pack',
                'provides' => $pack->providedCapabilities,
                'affects' => [],
                'entry_points' => [$pack->extension],
                'nodes' => [],
                'verified' => true,
                'source' => 'local',
            ];
        }

        $model = new ExplainModel(
            subject: ExplainOrigin::applyToRow([
                'id' => 'system:root',
                'kind' => 'system',
                'label' => 'system',
            ]),
            graph: [
                'node_ids' => [],
                'subject_node' => null,
                'neighbors' => ['inbound' => [], 'outbound' => [], 'lateral' => []],
            ],
            execution: [
                'entries' => [],
                'stages' => [],
                'action' => null,
                'workflows' => [],
                'jobs' => [],
            ],
            guards: ['items' => []],
            events: ['emits' => [], 'subscriptions' => [], 'emitters' => [], 'subscribers' => []],
            schemas: ['subject' => null, 'items' => [], 'reads' => [], 'writes' => [], 'fields' => []],
            relationships: [
                'dependsOn' => ['items' => []],
                'usedBy' => ['items' => []],
                'graph' => ['inbound' => [], 'outbound' => [], 'lateral' => []],
            ],
            diagnostics: [
                'summary' => ['error' => 0, 'warning' => 0, 'info' => 0, 'total' => 0],
                'items' => [],
            ],
            docs: ['related' => []],
            impact: [],
            commands: ['subject' => null, 'related' => []],
            metadata: ['target' => ['raw' => 'system:root', 'kind' => null, 'selector' => 'system:root']],
            extensions: $extensionRows,
        );

        return $model->withConfidence($this->confidenceEngine->explain($model));
    }

    /**
     * @param array<string,array{exists:bool,content:?string}> $packSnapshots
     * @param array<string,array{exists:bool,content:?string}> $fileSnapshots
     * @param array<string,array{exists:bool,content:?string}> $iterationSnapshots
     */
    private function restoreGenerateState(array $packSnapshots, array $fileSnapshots, array $iterationSnapshots): void
    {
        $snapshots = $packSnapshots + $fileSnapshots + $iterationSnapshots;
        if ($snapshots === []) {
            return;
        }

        $this->codeWriter->restore($snapshots);
        $this->rebuildAfterRestore();
    }

    private function relativePath(string $absolute): string
    {
        $root = rtrim($this->paths->root(), '/') . '/';

        return str_starts_with($absolute, $root)
            ? substr($absolute, strlen($root))
            : $absolute;
    }
}
