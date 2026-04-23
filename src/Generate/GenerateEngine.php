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
use Foundry\Git\GitRepositoryInspector;
use Foundry\Packs\PackManager;
use Foundry\Pro\ArchitectureExplainer;
use Foundry\Support\ApiSurfaceRegistry;
use Foundry\Support\FoundryError;
use Foundry\Support\Paths;
use Foundry\Support\Uuid;
use Foundry\Tooling\BuildArtifactStore;

final class GenerateEngine
{
    private readonly PackManager $packManager;
    private readonly CodeWriter $codeWriter;
    private readonly ApiSurfaceRegistry $apiSurfaceRegistry;
    private readonly ExplainSnapshotService $snapshotService;
    private readonly ExplainDiffService $diffService;
    private readonly ConfidenceEngine $confidenceEngine;
    private readonly GenerateSafetyRouter $safetyRouter;

    public function __construct(
        private readonly Paths $paths,
        ?PackManager $packManager = null,
        ?CodeWriter $codeWriter = null,
        ?ApiSurfaceRegistry $apiSurfaceRegistry = null,
        ?ExplainSnapshotService $snapshotService = null,
        ?ExplainDiffService $diffService = null,
        private readonly ?InteractiveGenerateReviewer $interactiveReviewer = null,
        ?GenerateSafetyRouter $safetyRouter = null,
    ) {
        $this->packManager = $packManager ?? new PackManager($paths);
        $this->codeWriter = $codeWriter ?? new CodeWriter();
        $this->apiSurfaceRegistry = $apiSurfaceRegistry ?? new ApiSurfaceRegistry();
        $this->snapshotService = $snapshotService ?? new ExplainSnapshotService($paths, $this->apiSurfaceRegistry);
        $this->diffService = $diffService ?? new ExplainDiffService($paths, $this->snapshotService);
        $this->confidenceEngine = new ConfidenceEngine();
        $this->safetyRouter = $safetyRouter ?? new GenerateSafetyRouter();
    }

    /**
     * @return array<string,mixed>
     */
    public function run(Intent $intent): array
    {
        $gitInspector = new GitRepositoryInspector($this->paths->root());
        $planStore = new PlanRecordStore($this->paths);
        $planId = Uuid::v4();
        $gitBefore = $gitInspector->inspect();
        $gitWarnings = [];
        $gitCommit = $intent->gitCommit
            ? ['requested' => true, 'created' => false, 'message' => $this->defaultGitCommitMessage($intent), 'files' => []]
            : null;
        $initialExtensions = ExtensionRegistry::forPaths($this->paths);
        $requirementResolver = new PackRequirementResolver();
        $preSnapshot = null;

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
        $context = null;
        $plan = null;
        $executionPlan = null;
        $interactiveReview = null;
        $safetyRouting = null;
        $actionsTaken = [];
        $verificationResults = null;
        $frameworkVersion = null;
        $graphVersion = null;
        $sourceHash = null;

        try {
            $initialRequirements = $requirementResolver->resolve($intent, $initialExtensions->packRegistry());
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
            $artifactStore = new BuildArtifactStore($compiler->buildLayout());
            $compile = $compiler->compile(new CompileOptions(emit: true));
            $frameworkVersion = $compile->graph->frameworkVersion();
            $graphVersion = $compile->graph->graphVersion();
            $sourceHash = $compile->graph->sourceHash();

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
            $validator = new PlanValidator();
            $validator->validate($plan, $intent, $intent->interactive);
            $plan = $plan->withConfidence($this->confidenceEngine->plan($context, $plan));
            $safetyRouting = $this->safetyRouter->route($intent, $plan);
            $this->assertGitPlanSafe($gitBefore, $plan, $intent, $gitWarnings);

            $executionPlan = $plan;
            $executionIntent = $intent;

            if ($intent->interactive) {
                if (!$this->interactiveReviewer instanceof InteractiveGenerateReviewer) {
                    throw new FoundryError(
                        'GENERATE_INTERACTIVE_REVIEWER_REQUIRED',
                        'validation',
                        [],
                        'Interactive generate requires an interactive reviewer.',
                    );
                }

                $preExplain = $this->buildExplainResponse($compiler, $extensions, $compile->graph, $target);
                $interactiveReview = $this->interactiveReviewer->review(new InteractiveGenerateReviewRequest(
                    intent: $intent,
                    plan: $plan,
                    context: $context,
                    explainRendered: $preExplain?->rendered,
                ));
                $executionPlan = $interactiveReview->plan;
                $executionIntent = $interactiveReview->allowRisky ? $intent->withAllowRisky(true) : $intent;

                if (!$interactiveReview->approved) {
                    $outcomeConfidence = $this->confidenceEngine->outcome(
                        $intent,
                        $executionPlan,
                        [],
                        ['skipped' => true, 'ok' => true],
                    );

                    $payload = $this->buildPayload(
                        intent: $intent,
                        plan: $executionPlan,
                        actionsTaken: [],
                        verificationResults: ['skipped' => true, 'ok' => true],
                        outcomeConfidence: $outcomeConfidence,
                        errors: [],
                        context: $context,
                        packsInstalled: $packsInstalled,
                        git: $this->gitPayload(
                            before: $gitBefore,
                            after: null,
                            warnings: $gitWarnings,
                            commit: $gitCommit,
                        ),
                        interactive: $this->interactivePayload($plan, $interactiveReview),
                        safetyRouting: $safetyRouting,
                    );

                    $record = $artifactStore->persistGenerateRecord($this->historyPayload($payload, $compile->graph->sourceHash()));
                    $planRecord = $planStore->persist($this->planRecordPayload(
                        planId: $planId,
                        status: 'aborted',
                        intent: $intent,
                        context: $context,
                        originalPlan: $plan,
                        finalPlan: $interactiveReview->modified ? $interactiveReview->plan : null,
                        interactiveReview: $interactiveReview,
                        actionsTaken: [],
                        verificationResults: ['skipped' => true, 'ok' => true],
                        safetyRouting: $safetyRouting,
                        frameworkVersion: $frameworkVersion,
                        graphVersion: $graphVersion,
                        sourceHash: $compile->graph->sourceHash(),
                        error: null,
                    ));
                    $payload['record'] = $this->historyRecordReference($record);
                    $payload['plan_record'] = $this->planRecordReference($planRecord);

                    return $payload;
                }

                $validator->validate($executionPlan, $executionIntent);
            }

            if ($executionIntent->dryRun) {
                $outcomeConfidence = $this->confidenceEngine->outcome(
                    $intent,
                    $executionPlan,
                    [],
                    ['skipped' => true, 'ok' => true],
                );

                $payload = $this->buildPayload(
                    intent: $intent,
                    plan: $executionPlan,
                    actionsTaken: [],
                    verificationResults: ['skipped' => true, 'ok' => true],
                    outcomeConfidence: $outcomeConfidence,
                    errors: [],
                    context: $context,
                    packsInstalled: $packsInstalled,
                    git: $this->gitPayload(
                        before: $gitBefore,
                        after: null,
                        warnings: $gitWarnings,
                        commit: $gitCommit,
                    ),
                    interactive: $this->interactivePayload($plan, $interactiveReview),
                    safetyRouting: $safetyRouting,
                );

                $record = $artifactStore->persistGenerateRecord($this->historyPayload($payload, $compile->graph->sourceHash()));
                $planRecord = $planStore->persist($this->planRecordPayload(
                    planId: $planId,
                    status: 'success',
                    intent: $intent,
                    context: $context,
                    originalPlan: $plan,
                    finalPlan: $interactiveReview?->modified === true ? $interactiveReview->plan : null,
                    interactiveReview: $interactiveReview,
                    actionsTaken: [],
                    verificationResults: ['skipped' => true, 'ok' => true],
                    safetyRouting: $safetyRouting,
                    frameworkVersion: $frameworkVersion,
                    graphVersion: $graphVersion,
                    sourceHash: $compile->graph->sourceHash(),
                    error: null,
                ));
                $payload['record'] = $this->historyRecordReference($record);
                $payload['plan_record'] = $this->planRecordReference($planRecord);

                return $payload;
            }

            $fileSnapshots = $this->codeWriter->snapshot($this->absolutePaths($executionPlan->affectedFiles));
            $actionsTaken = $this->executePlan($executionPlan, $executionIntent);
            $verificationResults = $executionIntent->skipVerify
                ? ['skipped' => true, 'ok' => true]
                : $this->runVerification($executionPlan);

            if (($verificationResults['ok'] ?? false) !== true) {
                throw new FoundryError(
                    'GENERATE_VERIFICATION_FAILED',
                    'validation',
                    [
                        'plan' => $executionPlan->toArray(),
                        'verification_results' => $verificationResults,
                    ],
                    'Generation was rolled back because verification failed.',
                );
            }

            $postExtensions = ExtensionRegistry::forPaths($this->paths);
            $postCompiler = new GraphCompiler($this->paths, $postExtensions);
            $postCompile = $postCompiler->compile(new CompileOptions(emit: true));
            $frameworkVersion = $postCompile->graph->frameworkVersion();
            $graphVersion = $postCompile->graph->graphVersion();
            $sourceHash = $postCompile->graph->sourceHash();
            $postTarget = $this->postGenerateTarget($executionPlan, $context, $postCompile->graph);
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

            $gitAfter = $gitInspector->inspect();
            if ($executionIntent->gitCommit) {
                $gitCommit = $this->commitGenerateChanges(
                    $gitInspector,
                    $gitBefore,
                    $gitAfter,
                    $executionIntent,
                    $executionPlan,
                    $packsInstalled,
                    $gitWarnings,
                );
                $gitAfter = $gitInspector->inspect();
            }

            $postExplain = null;
            $postExplainRendered = null;
            if ($executionIntent->explainAfter) {
                $response = $this->buildExplainResponse($postCompiler, $postExtensions, $postCompile->graph, $postTarget);
                $postExplain = $response?->toArray();
                $postExplainRendered = $response?->rendered;
            }

            $outcomeConfidence = $this->confidenceEngine->outcome(
                $intent,
                $executionPlan,
                $actionsTaken,
                $verificationResults,
                $architectureDiff,
                $packsInstalled,
            );

            $payload = $this->buildPayload(
                intent: $intent,
                plan: $executionPlan,
                actionsTaken: $actionsTaken,
                verificationResults: $verificationResults,
                outcomeConfidence: $outcomeConfidence,
                errors: [],
                context: $context,
                packsInstalled: $packsInstalled,
                git: $this->gitPayload(
                    before: $gitBefore,
                    after: $gitAfter,
                    warnings: $gitWarnings,
                    commit: $gitCommit,
                ),
                snapshots: [
                    'pre' => $this->relativePath($this->snapshotService->snapshotPath('pre-generate')),
                    'post' => $this->relativePath($this->snapshotService->snapshotPath('post-generate')),
                    'diff' => $this->relativePath($this->diffService->lastDiffPath()),
                ],
                architectureDiff: $architectureDiff,
                postExplain: $postExplain,
                postExplainRendered: $postExplainRendered,
                interactive: $this->interactivePayload($plan, $interactiveReview),
                safetyRouting: $safetyRouting,
            );

            $record = $artifactStore->persistGenerateRecord($this->historyPayload($payload, $postCompile->graph->sourceHash()));
            $planRecord = $planStore->persist($this->planRecordPayload(
                planId: $planId,
                status: 'success',
                intent: $intent,
                context: $context,
                originalPlan: $plan,
                finalPlan: $interactiveReview?->modified === true ? $executionPlan : null,
                interactiveReview: $interactiveReview,
                actionsTaken: $actionsTaken,
                verificationResults: $verificationResults,
                safetyRouting: $safetyRouting,
                frameworkVersion: $frameworkVersion,
                graphVersion: $graphVersion,
                sourceHash: $postCompile->graph->sourceHash(),
                error: null,
            ));
            $payload['record'] = $this->historyRecordReference($record);
            $payload['plan_record'] = $this->planRecordReference($planRecord);

            return $payload;
        } catch (\Throwable $error) {
            $this->restoreGenerateState($packSnapshots, $fileSnapshots, $iterationSnapshots);
            $planStore->persist($this->planRecordPayload(
                planId: $planId,
                status: 'failed',
                intent: $intent,
                context: $context,
                originalPlan: $plan,
                finalPlan: $interactiveReview?->modified === true ? $executionPlan : null,
                interactiveReview: $interactiveReview,
                actionsTaken: $actionsTaken,
                verificationResults: $verificationResults,
                safetyRouting: $safetyRouting,
                frameworkVersion: $frameworkVersion,
                graphVersion: $graphVersion,
                sourceHash: $sourceHash,
                error: $this->publicErrorPayload($error),
            ));

            throw $error;
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function replay(string $planId, bool $strict = false, bool $dryRun = false): array
    {
        $record = (new PlanRecordStore($this->paths))->load($planId);
        if (!is_array($record)) {
            throw new FoundryError(
                'PLAN_RECORD_NOT_FOUND',
                'not_found',
                ['plan_id' => $planId],
                'Persisted plan record not found.',
            );
        }

        [$selectedPlan, $selectedPlanName] = $this->selectReplayPlan($record);
        $plan = GenerationPlan::fromArray($selectedPlan);
        $intent = $this->replayIntent($record, $plan, $dryRun);
        $validator = new PlanValidator();
        $validator->validate($plan, $intent);

        $extensions = ExtensionRegistry::forPaths($this->paths);
        $compiler = new GraphCompiler($this->paths, $extensions);
        $compile = $compiler->compile(new CompileOptions(emit: true));

        if ($compile->diagnostics->hasErrors() && $intent->mode !== 'repair' && !$intent->allowRisky) {
            throw new FoundryError(
                'PLAN_REPLAY_PRECONDITION_FAILED',
                'validation',
                [
                    'plan_id' => $planId,
                    'compile' => $compile->toArray(),
                ],
                'Replay cannot proceed while the current graph has errors.',
            );
        }

        $gitInspector = new GitRepositoryInspector($this->paths->root());
        $gitBefore = $gitInspector->inspect();
        $gitWarnings = [];
        $this->assertGitPlanSafe($gitBefore, $plan, $intent, $gitWarnings);

        $driftSummary = $this->replayDriftSummary($record, $plan, $intent, $gitInspector, $gitBefore, $compile->graph->sourceHash());
        if ($strict && ($driftSummary['detected'] ?? false) === true) {
            throw new FoundryError(
                'PLAN_REPLAY_STRICT_DRIFT',
                'validation',
                [
                    'plan_id' => $planId,
                    'drift_summary' => $driftSummary,
                ],
                'Strict replay cannot proceed because material drift was detected.',
            );
        }

        $safetyRouting = $this->safetyRouter->route($intent, $plan);
        $actionsExecuted = $dryRun ? $this->plannedReplayActions($plan) : [];
        $verification = ['skipped' => true, 'ok' => true];
        $fileSnapshots = [];

        try {
            if (!$dryRun) {
                $fileSnapshots = $this->codeWriter->snapshot($this->absolutePaths($plan->affectedFiles));
                $actionsExecuted = $this->executePlan($plan, $intent);
                $verification = $intent->skipVerify
                    ? ['skipped' => true, 'ok' => true]
                    : $this->runVerification($plan);

                if (($verification['ok'] ?? false) !== true) {
                    throw new FoundryError(
                        'PLAN_REPLAY_VERIFICATION_FAILED',
                        'validation',
                        [
                            'plan_id' => $planId,
                            'plan' => $plan->toArray(),
                            'verification' => $verification,
                        ],
                        'Replay was rolled back because verification failed.',
                    );
                }
            }
        } catch (\Throwable $error) {
            if ($fileSnapshots !== []) {
                $this->codeWriter->restore($fileSnapshots);
                $this->rebuildAfterRestore();
            }

            throw $error;
        }

        return [
            'plan_id' => $planId,
            'replay_mode' => $strict ? 'strict' : 'adaptive',
            'status' => $dryRun ? 'dry_run' : 'replayed',
            'replayable' => true,
            'drift_detected' => ($driftSummary['detected'] ?? false) === true,
            'drift_summary' => $driftSummary,
            'actions_executed' => $actionsExecuted,
            'verification' => $verification,
            'dry_run' => $dryRun,
            'plan' => $plan->toArray(),
            'source_record' => [
                'status' => $record['status'] ?? null,
                'timestamp' => $record['timestamp'] ?? null,
                'storage_path' => $record['storage_path'] ?? null,
                'selected_plan' => $selectedPlanName,
            ],
            'git' => $this->gitPayload(
                before: $gitBefore,
                after: $dryRun ? null : $gitInspector->inspect(),
                warnings: $gitWarnings,
                commit: null,
            ),
            'safety_routing' => $safetyRouting,
        ];
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
        array $git = [],
        array $snapshots = [],
        ?array $architectureDiff = null,
        ?array $postExplain = null,
        ?string $postExplainRendered = null,
        ?array $interactive = null,
        ?array $safetyRouting = null,
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
            'git' => $git,
            'snapshots' => $snapshots,
            'architecture_diff' => $architectureDiff,
            'post_explain' => $postExplain,
            'post_explain_rendered' => $postExplainRendered,
            'packs_used' => $packsUsed,
            'packs_installed' => $packsInstalled,
            'interactive' => $interactive,
            'safety_routing' => $safetyRouting,
        ];
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed>|null $after
     * @param array<int,string> $warnings
     * @param array<string,mixed>|null $commit
     * @return array<string,mixed>
     */
    private function gitPayload(array $before, ?array $after, array $warnings, ?array $commit): array
    {
        return [
            'available' => (bool) ($before['available'] ?? false),
            'warnings' => array_values(array_unique(array_map('strval', $warnings))),
            'before' => $this->publicGitState($before),
            'after' => $after !== null ? $this->publicGitState($after) : null,
            'commit' => $commit,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function historyPayload(array $payload, string $sourceHash): array
    {
        return [
            'intent' => [
                'raw' => $payload['intent'] ?? '',
                'mode' => $payload['mode'] ?? 'new',
            ],
            'target' => $payload['metadata']['target']['resolved'] ?? null,
            'plan' => $payload['plan'] ?? [],
            'plan_confidence' => $payload['plan_confidence'] ?? [],
            'outcome_confidence' => $payload['outcome_confidence'] ?? [],
            'actions_taken' => $payload['actions_taken'] ?? [],
            'verification_results' => $payload['verification_results'] ?? [],
            'metadata' => [
                'dry_run' => $payload['metadata']['dry_run'] ?? false,
                'target' => $payload['metadata']['target'] ?? null,
            ],
            'snapshots' => $payload['snapshots'] ?? [],
            'architecture_diff' => is_array($payload['architecture_diff'] ?? null)
                ? ['summary' => $payload['architecture_diff']['summary'] ?? []]
                : null,
            'git' => $payload['git'] ?? [],
            'packs_used' => $payload['packs_used'] ?? [],
            'packs_installed' => $payload['packs_installed'] ?? [],
            'interactive' => $payload['interactive'] ?? null,
            'safety_routing' => $payload['safety_routing'] ?? null,
            'source_hash' => $sourceHash,
        ];
    }

    private function interactivePayload(GenerationPlan $originalPlan, ?InteractiveGenerateReviewResult $review): ?array
    {
        if ($review === null) {
            return null;
        }

        return array_merge(
            $review->toArray(),
            [
                'original_plan' => $originalPlan->toArray(),
                'modified_plan' => $review->modified ? $review->plan->toArray() : null,
            ],
        );
    }

    /**
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private function historyRecordReference(array $record): array
    {
        return [
            'id' => $record['id'] ?? null,
            'kind' => $record['kind'] ?? null,
            'label' => $record['label'] ?? null,
            'sequence' => $record['sequence'] ?? null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function planRecordReference(array $record): array
    {
        return [
            'plan_id' => $record['plan_id'] ?? null,
            'timestamp' => $record['timestamp'] ?? null,
            'status' => $record['status'] ?? null,
            'storage_path' => $record['storage_path'] ?? null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function planRecordPayload(
        string $planId,
        string $status,
        Intent $intent,
        ?GenerationContextPacket $context,
        ?GenerationPlan $originalPlan,
        ?GenerationPlan $finalPlan,
        ?InteractiveGenerateReviewResult $interactiveReview,
        array $actionsTaken,
        ?array $verificationResults,
        ?array $safetyRouting,
        ?string $frameworkVersion,
        ?int $graphVersion,
        ?string $sourceHash,
        ?array $error,
    ): array {
        $effectivePlan = $finalPlan ?? $originalPlan;
        $affectedFiles = $effectivePlan?->affectedFiles ?? [];
        $riskLevel = $this->planRiskLevel($interactiveReview, $safetyRouting);

        return [
            'plan_id' => $planId,
            'timestamp' => null,
            'storage_path' => null,
            'intent' => $intent->raw,
            'mode' => $intent->mode,
            'targets' => $context?->targets ?? [],
            'generation_context_packet' => $context?->toArray(),
            'plan_original' => $originalPlan?->toArray(),
            'plan_final' => $finalPlan?->toArray(),
            'interactive' => $interactiveReview !== null
                ? [
                    'enabled' => true,
                    'approved' => $interactiveReview->approved,
                    'rejected' => !$interactiveReview->approved,
                    'modified' => $interactiveReview->modified,
                    'allow_risky' => $interactiveReview->allowRisky,
                    'preview' => $interactiveReview->preview,
                    'risk' => $interactiveReview->risk,
                ]
                : null,
            'user_decisions' => $interactiveReview?->userDecisions ?? [],
            'actions_executed' => $actionsTaken,
            'affected_files' => $affectedFiles,
            'risk_level' => $riskLevel,
            'verification_results' => $verificationResults,
            'status' => $status,
            'error' => $error,
            'metadata' => [
                'framework_version' => $frameworkVersion,
                'graph_version' => $graphVersion,
                'source_hash' => $sourceHash,
                'requested_intent' => $intent->toArray(),
                'dry_run' => $intent->dryRun,
                'interactive_requested' => $intent->interactive,
                'plan_origin' => $effectivePlan?->origin,
                'generator_id' => $effectivePlan?->generatorId,
                'safety_routing' => $safetyRouting,
            ],
        ];
    }

    private function planRiskLevel(?InteractiveGenerateReviewResult $interactiveReview, ?array $safetyRouting): ?string
    {
        $interactiveRisk = trim((string) ($interactiveReview?->risk['level'] ?? ''));
        if ($interactiveRisk !== '') {
            return $interactiveRisk;
        }

        $routingRisk = trim((string) ($safetyRouting['signals']['risk_level'] ?? ''));

        return $routingRisk !== '' ? $routingRisk : null;
    }

    /**
     * @return array<string,mixed>
     */
    private function publicErrorPayload(\Throwable $error): array
    {
        if ($error instanceof FoundryError) {
            return $error->toArray()['error'];
        }

        return [
            'code' => 'CLI_UNHANDLED_EXCEPTION',
            'category' => 'runtime',
            'message' => $error->getMessage(),
            'details' => ['exception' => $error::class],
        ];
    }

    /**
     * @param array<string,mixed> $gitState
     * @param array<int,string> $warnings
     */
    private function assertGitPlanSafe(array $gitState, GenerationPlan $plan, Intent $intent, array &$warnings): void
    {
        if (($gitState['available'] ?? false) !== true) {
            if ($intent->gitCommit) {
                $warnings[] = 'Git repository not detected; commit support is unavailable for this generate run.';
            }

            return;
        }

        $relevant = is_array($gitState['safety_relevant'] ?? null) ? $gitState['safety_relevant'] : [];
        $dirtyFiles = array_values(array_map('strval', (array) ($relevant['changed_files'] ?? [])));
        $untrackedFiles = array_values(array_map('strval', (array) ($relevant['untracked_files'] ?? [])));
        $conflictingUntracked = array_values(array_intersect($plan->affectedFiles, $untrackedFiles));
        sort($conflictingUntracked);

        if ($conflictingUntracked !== []) {
            throw new FoundryError(
                'GENERATE_GIT_UNTRACKED_CONFLICT',
                'validation',
                [
                    'conflicting_files' => $conflictingUntracked,
                    'plan_files' => $plan->affectedFiles,
                ],
                'Generate would overwrite untracked files. Move or commit them before retrying.',
            );
        }

        if ($dirtyFiles !== [] && !$intent->allowDirty) {
            throw new FoundryError(
                'GENERATE_GIT_DIRTY_TREE',
                'validation',
                [
                    'changed_files' => $dirtyFiles,
                    'staged_files' => (array) ($relevant['staged_files'] ?? []),
                    'unstaged_files' => (array) ($relevant['unstaged_files'] ?? []),
                    'untracked_files' => $untrackedFiles,
                    'conflicting_files' => array_values(array_intersect($plan->affectedFiles, $dirtyFiles)),
                ],
                'Git working tree is dirty. Re-run with --allow-dirty or clean the repository first.',
            );
        }

        if ($dirtyFiles !== [] && $intent->allowDirty) {
            $warnings[] = 'Git working tree was dirty before generation; auto-commit may be skipped for safety.';
        }
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @param array<int,array<string,mixed>> $packsInstalled
     * @param array<int,string> $warnings
     * @return array<string,mixed>
     */
    private function commitGenerateChanges(
        GitRepositoryInspector $gitInspector,
        array $before,
        array $after,
        Intent $intent,
        GenerationPlan $plan,
        array $packsInstalled,
        array &$warnings,
    ): array {
        $commitMessage = trim((string) ($intent->gitCommitMessage ?? $this->defaultGitCommitMessage($intent)));
        $beforeStagedFiles = array_values(array_map('strval', (array) ($before['staged_files'] ?? [])));
        if ($beforeStagedFiles !== []) {
            $warning = 'Git commit skipped because the index already contained staged files before generation.';
            $warnings[] = $warning;

            return [
                'requested' => true,
                'created' => false,
                'message' => $commitMessage,
                'commit' => null,
                'branch' => $after['branch'] ?? $before['branch'] ?? null,
                'files' => [],
                'warning' => $warning,
            ];
        }

        $safeFiles = $this->planCommitPaths($plan, $packsInstalled, $after);
        $preexistingConflicts = array_values(array_intersect(
            $safeFiles,
            array_values(array_map('strval', (array) ($before['safety_relevant']['changed_files'] ?? []))),
        ));
        sort($preexistingConflicts);

        if ($preexistingConflicts !== []) {
            $warning = 'Git commit skipped because some generated targets were already dirty before generation.';
            $warnings[] = $warning;

            return [
                'requested' => true,
                'created' => false,
                'message' => $commitMessage,
                'commit' => null,
                'branch' => $after['branch'] ?? $before['branch'] ?? null,
                'files' => $preexistingConflicts,
                'warning' => $warning,
            ];
        }

        $result = $gitInspector->commit($safeFiles, $commitMessage);
        if (($result['created'] ?? false) !== true && isset($result['warning'])) {
            $warnings[] = (string) $result['warning'];
        }

        return $result;
    }

    /**
     * @param array<int,array<string,mixed>> $packsInstalled
     * @param array<string,mixed> $after
     * @return array<int,string>
     */
    private function planCommitPaths(GenerationPlan $plan, array $packsInstalled, array $after): array
    {
        $candidatePaths = $plan->affectedFiles;
        $changedAfter = array_values(array_map('strval', (array) ($after['changed_files'] ?? [])));
        $candidatePaths[] = '.foundry/packs/installed.json';

        foreach ($packsInstalled as $pack) {
            if (!is_array($pack)) {
                continue;
            }

            $installPath = trim((string) ($pack['install_path'] ?? ''));
            if ($installPath === '') {
                continue;
            }

            foreach ($changedAfter as $path) {
                if ($path === $installPath || str_starts_with($path, $installPath . '/')) {
                    $candidatePaths[] = $path;
                }
            }
        }

        $candidatePaths = array_values(array_unique(array_filter(array_map('strval', $candidatePaths))));
        sort($candidatePaths);

        return array_values(array_intersect($candidatePaths, $changedAfter));
    }

    private function defaultGitCommitMessage(Intent $intent): string
    {
        return sprintf('foundry generate (%s): %s', $intent->mode, $intent->raw);
    }

    /**
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function publicGitState(array $state): array
    {
        if (($state['available'] ?? false) !== true) {
            return ['available' => false];
        }

        return [
            'available' => true,
            'repository_root' => $this->relativeRoot((string) ($state['repository_root'] ?? '')),
            'branch' => $state['branch'] ?? null,
            'head' => $state['head'] ?? null,
            'dirty' => (bool) ($state['dirty'] ?? false),
            'changed_files' => array_values(array_map('strval', (array) ($state['changed_files'] ?? []))),
            'staged_files' => array_values(array_map('strval', (array) ($state['staged_files'] ?? []))),
            'unstaged_files' => array_values(array_map('strval', (array) ($state['unstaged_files'] ?? []))),
            'untracked_files' => array_values(array_map('strval', (array) ($state['untracked_files'] ?? []))),
            'ignored_internal_files' => array_values(array_map('strval', (array) ($state['ignored_internal_files'] ?? []))),
            'safety_relevant' => [
                'dirty' => (bool) ($state['safety_relevant']['dirty'] ?? false),
                'changed_files' => array_values(array_map('strval', (array) ($state['safety_relevant']['changed_files'] ?? []))),
                'staged_files' => array_values(array_map('strval', (array) ($state['safety_relevant']['staged_files'] ?? []))),
                'unstaged_files' => array_values(array_map('strval', (array) ($state['safety_relevant']['unstaged_files'] ?? []))),
                'untracked_files' => array_values(array_map('strval', (array) ($state['safety_relevant']['untracked_files'] ?? []))),
            ],
        ];
    }

    private function relativeRoot(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '.';
        }

        $root = rtrim($this->paths->root(), '/');
        if ($path === $root) {
            return '.';
        }

        $prefix = $root . '/';

        return str_starts_with($path, $prefix)
            ? substr($path, strlen($prefix))
            : $path;
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
     * @param array<string,mixed> $record
     * @return array{0:array<string,mixed>,1:string}
     */
    private function selectReplayPlan(array $record): array
    {
        $interactive = is_array($record['interactive'] ?? null) ? $record['interactive'] : [];
        $approvedFinalPlan = ($interactive['approved'] ?? false) === true && is_array($record['plan_final'] ?? null)
            ? $record['plan_final']
            : null;
        $originalPlan = is_array($record['plan_original'] ?? null) ? $record['plan_original'] : null;

        if (is_array($approvedFinalPlan)) {
            return [$approvedFinalPlan, 'final'];
        }

        if (is_array($originalPlan)) {
            return [$originalPlan, 'original'];
        }

        throw new FoundryError(
            'PLAN_REPLAY_PLAN_UNAVAILABLE',
            'validation',
            [
                'plan_id' => $record['plan_id'] ?? null,
                'status' => $record['status'] ?? null,
            ],
            'Persisted plan record does not contain a replayable plan.',
        );
    }

    /**
     * @param array<string,mixed> $record
     */
    private function replayIntent(array $record, GenerationPlan $plan, bool $dryRun): Intent
    {
        $storedIntent = is_array($record['metadata']['requested_intent'] ?? null)
            ? $record['metadata']['requested_intent']
            : [
                'raw' => $record['intent'] ?? '',
                'mode' => $record['mode'] ?? 'new',
                'target' => $this->replayTarget($record),
                'interactive' => false,
                'dry_run' => (bool) ($record['metadata']['dry_run'] ?? false),
                'skip_verify' => false,
                'explain' => false,
                'allow_risky' => (bool) ($record['interactive']['allow_risky'] ?? false),
                'allow_dirty' => false,
                'allow_pack_install' => false,
                'git_commit' => false,
                'git_commit_message' => null,
                'packs' => [],
            ];

        $storedIntent['interactive'] = false;
        $storedIntent['dry_run'] = $dryRun;
        $storedIntent['skip_verify'] = false;
        $storedIntent['explain'] = false;
        $storedIntent['git_commit'] = false;
        $storedIntent['git_commit_message'] = null;
        $storedIntent['allow_risky'] = ($storedIntent['allow_risky'] ?? false) === true || $this->planRequiresRisky($plan);
        $storedIntent['target'] = $storedIntent['target'] ?? $this->replayTarget($record);

        return Intent::fromArray($storedIntent);
    }

    /**
     * @param array<string,mixed> $record
     */
    private function replayTarget(array $record): ?string
    {
        $target = is_array($record['targets'][0] ?? null) ? $record['targets'][0] : [];
        $requested = trim((string) ($target['requested'] ?? ''));
        if ($requested !== '') {
            return $requested;
        }

        $resolved = trim((string) ($target['resolved'] ?? ''));

        return $resolved !== '' ? $resolved : null;
    }

    private function planRequiresRisky(GenerationPlan $plan): bool
    {
        foreach ($plan->actions as $action) {
            if ((string) ($action['type'] ?? '') === 'delete_file') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $record
     * @param array<string,mixed> $gitState
     * @return array{detected:bool,messages:list<string>,items:list<array<string,mixed>>}
     */
    private function replayDriftSummary(
        array $record,
        GenerationPlan $plan,
        Intent $intent,
        GitRepositoryInspector $gitInspector,
        array $gitState,
        ?string $currentSourceHash,
    ): array {
        $items = [];
        $storedSourceHash = trim((string) ($record['metadata']['source_hash'] ?? ''));
        if ($storedSourceHash !== '' && is_string($currentSourceHash) && $currentSourceHash !== '' && $storedSourceHash !== $currentSourceHash) {
            $items[] = [
                'code' => 'source_hash_changed',
                'path' => null,
                'message' => 'Stored graph source hash differs from the current compiled graph.',
                'details' => [
                    'stored_source_hash' => $storedSourceHash,
                    'current_source_hash' => $currentSourceHash,
                ],
            ];
        }

        foreach ($gitInspector->describePaths($plan->affectedFiles, $gitState) as $row) {
            if (($row['changed'] ?? false) !== true) {
                continue;
            }

            $items[] = [
                'code' => 'repository_state_changed',
                'path' => $row['path'] ?? null,
                'message' => 'Affected path has local repository changes.',
                'details' => $row,
            ];
        }

        $afterContents = (new GeneratePlanPreviewBuilder($this->paths))->afterContents($plan, $intent);

        foreach ($plan->actions as $action) {
            $type = trim((string) ($action['type'] ?? ''));
            $path = trim((string) ($action['path'] ?? ''));
            if ($path === '') {
                continue;
            }

            $absolute = $this->absolutePath($path);
            $exists = is_file($absolute);
            $current = $exists ? (string) (file_get_contents($absolute) ?: '') : null;
            $expected = $afterContents[$path] ?? null;

            if ($type === 'delete_file') {
                if (!$exists) {
                    $items[] = [
                        'code' => 'missing_delete_target',
                        'path' => $path,
                        'message' => 'Replay expected to delete a file that is already missing.',
                        'details' => ['action_type' => $type],
                    ];
                }

                continue;
            }

            if ($type === 'update_file' || $type === 'update_docs') {
                if (!$exists) {
                    $items[] = [
                        'code' => 'missing_target_file',
                        'path' => $path,
                        'message' => 'Replay expected to update a file that is currently missing.',
                        'details' => ['action_type' => $type],
                    ];
                    continue;
                }
            }

            if (($type === 'create_file' || $type === 'add_test') && $exists && $expected !== null && $current !== $expected) {
                $items[] = [
                    'code' => 'existing_file_differs',
                    'path' => $path,
                    'message' => 'Replay expected to create a file, but a different file already exists at that path.',
                    'details' => ['action_type' => $type],
                ];
                continue;
            }

            if ($exists && $expected !== null && $current !== $expected) {
                $items[] = [
                    'code' => 'file_content_differs',
                    'path' => $path,
                    'message' => 'Current file contents differ from the stored replay target.',
                    'details' => ['action_type' => $type],
                ];
            }
        }

        usort($items, static fn(array $left, array $right): int => [
            (string) ($left['code'] ?? ''),
            (string) ($left['path'] ?? ''),
        ] <=> [
            (string) ($right['code'] ?? ''),
            (string) ($right['path'] ?? ''),
        ]);

        return [
            'detected' => $items !== [],
            'messages' => array_values(array_map(
                static fn(array $item): string => (string) ($item['message'] ?? ''),
                $items,
            )),
            'items' => $items,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function plannedReplayActions(GenerationPlan $plan): array
    {
        $planned = [];

        foreach ($plan->actions as $action) {
            $type = trim((string) ($action['type'] ?? ''));
            $path = trim((string) ($action['path'] ?? ''));
            if ($path === '') {
                continue;
            }

            $planned[] = [
                'type' => $type,
                'path' => $path,
                'status' => 'planned',
                'origin' => $plan->origin,
                'extension' => $plan->extension,
            ];
        }

        return $planned;
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
            'modify_feature' => $this->executeModifyFeature($execution, $plan, $intent),
            'repair_feature' => $this->executeRepairFeature($execution, $plan, $intent),
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

        return $this->executeSelectedFileActions($plan, $intent);
    }

    /**
     * @param array<string,mixed> $execution
     * @return array<int,array<string,mixed>>
     */
    private function executeModifyFeature(array $execution, GenerationPlan $plan, Intent $intent): array
    {
        return $this->executeSelectedFileActions($plan, $intent);
    }

    /**
     * @param array<string,mixed> $execution
     * @return array<int,array<string,mixed>>
     */
    private function executeRepairFeature(array $execution, GenerationPlan $plan, Intent $intent): array
    {
        return $this->executeSelectedFileActions($plan, $intent);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function executeSelectedFileActions(GenerationPlan $plan, Intent $intent): array
    {
        $afterContents = (new GeneratePlanPreviewBuilder($this->paths))->afterContents($plan, $intent);
        $executed = [];

        foreach ($plan->actions as $action) {
            $type = trim((string) ($action['type'] ?? ''));
            $path = trim((string) ($action['path'] ?? ''));
            if ($path === '') {
                continue;
            }

            $absolutePath = $this->absolutePath($path);
            $status = 'unchanged';

            if ($type === 'delete_file') {
                if (is_file($absolutePath)) {
                    @unlink($absolutePath);
                    $status = 'deleted';
                }
            } elseif (array_key_exists($path, $afterContents) && $afterContents[$path] !== null) {
                $status = $this->codeWriter->syncFile($absolutePath, (string) $afterContents[$path]) ? 'written' : 'unchanged';
            }

            $executed[] = [
                'type' => $type,
                'path' => $path,
                'status' => $status,
                'origin' => $plan->origin,
                'extension' => $plan->extension,
            ];
        }

        return $executed;
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
