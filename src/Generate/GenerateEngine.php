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
    private readonly GenerateUnifiedDiffApplier $diffApplier;

    public function __construct(
        private readonly Paths $paths,
        ?PackManager $packManager = null,
        ?CodeWriter $codeWriter = null,
        ?ApiSurfaceRegistry $apiSurfaceRegistry = null,
        ?ExplainSnapshotService $snapshotService = null,
        ?ExplainDiffService $diffService = null,
        private readonly ?InteractiveGenerateReviewer $interactiveReviewer = null,
        ?GenerateSafetyRouter $safetyRouter = null,
        ?GenerateUnifiedDiffApplier $diffApplier = null,
    ) {
        $this->packManager = $packManager ?? new PackManager($paths);
        $this->codeWriter = $codeWriter ?? new CodeWriter();
        $this->apiSurfaceRegistry = $apiSurfaceRegistry ?? new ApiSurfaceRegistry();
        $this->snapshotService = $snapshotService ?? new ExplainSnapshotService($paths, $this->apiSurfaceRegistry);
        $this->diffService = $diffService ?? new ExplainDiffService($paths, $this->snapshotService);
        $this->confidenceEngine = new ConfidenceEngine();
        $this->safetyRouter = $safetyRouter ?? new GenerateSafetyRouter();
        $this->diffApplier = $diffApplier ?? new GenerateUnifiedDiffApplier();
    }

    /**
     * @return array<string,mixed>
     */
    public function run(Intent $intent): array
    {
        if ($intent->isTemplate()) {
            return $this->runTemplate($intent);
        }

        if ($intent->isWorkflow()) {
            return $this->runWorkflow($intent);
        }

        return $this->runSingle($intent);
    }

    /**
     * @return array<string,mixed>
     */
    private function runSingle(
        Intent $intent,
        ?array $workflowLinkage = null,
        ?array $templateMetadata = null,
        ?\Closure $planRecordObserver = null,
    ): array
    {
        $gitInspector = new GitRepositoryInspector($this->paths->root());
        $planStore = new PlanRecordStore($this->paths);
        $policyEngine = new GeneratePolicyEngine($this->paths);
        $planId = Uuid::v4();
        $gitBefore = $gitInspector->inspect();
        $noWriteMode = $intent->dryRun || $intent->policyCheck;
        $gitWarnings = [];
        $gitCommit = $intent->gitCommit
            ? ['requested' => true, 'created' => false, 'message' => $this->defaultGitCommitMessage($intent), 'files' => []]
            : null;
        $initialExtensions = ExtensionRegistry::forPaths($this->paths);
        $requirementResolver = new PackRequirementResolver();
        $preSnapshot = null;

        $packsInstalled = [];
        $packSnapshots = $noWriteMode
            ? []
            : $this->codeWriter->snapshot([$this->paths->join('.foundry/packs/installed.json')]);
        $iterationSnapshots = $noWriteMode
            ? []
            : $this->codeWriter->snapshot([
                $this->snapshotService->snapshotPath('post-generate'),
                $this->diffService->lastDiffPath(),
            ]);
        $fileSnapshots = [];
        $fileSnapshotsAfter = [];
        $context = null;
        $plan = null;
        $executionPlan = null;
        $interactiveReview = null;
        $safetyRouting = null;
        $policy = null;
        $actionsTaken = [];
        $verificationResults = null;
        $frameworkVersion = null;
        $graphVersion = null;
        $sourceHash = null;

        try {
            $initialRequirements = $requirementResolver->resolve($intent, $initialExtensions->packRegistry());
            if (!$noWriteMode) {
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
                if ($noWriteMode || !$intent->allowPackInstall) {
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
            $policy = $policyEngine->evaluate(
                $plan,
                $intent,
                $context,
                $intent->allowPolicyViolations,
                $intent->allowPolicyViolations ? 'flag' : null,
            );
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
                    policy: $policy,
                ));
                $executionPlan = $interactiveReview->plan;
                $executionIntent = $intent;
                if ($interactiveReview->allowRisky) {
                    $executionIntent = $executionIntent->withAllowRisky(true);
                }
                if ($interactiveReview->allowPolicyViolations) {
                    $executionIntent = $executionIntent->withAllowPolicyViolations(true);
                }
                $policy = $policyEngine->evaluate(
                    $executionPlan,
                    $executionIntent,
                    $context,
                    $executionIntent->allowPolicyViolations,
                    $executionIntent->allowPolicyViolations
                        ? ($intent->allowPolicyViolations ? 'flag' : 'interactive_confirmation')
                        : null,
                );

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
                        policy: $policy,
                        workflowLinkage: $workflowLinkage,
                        templateMetadata: $templateMetadata,
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
                        policy: $policy,
                        frameworkVersion: $frameworkVersion,
                        graphVersion: $graphVersion,
                        sourceHash: $compile->graph->sourceHash(),
                        error: null,
                        undo: null,
                        workflowLinkage: $workflowLinkage,
                        templateMetadata: $templateMetadata,
                    ));
                    $this->observePlanRecord($planRecordObserver, $planRecord);
                    $payload['record'] = $this->historyRecordReference($record);
                    $payload['plan_record'] = $this->planRecordReference($planRecord);

                    return $payload;
                }

                $validator->validate($executionPlan, $executionIntent);
            }

            if (($policy['blocking'] ?? false) === true && !$executionIntent->dryRun && !$executionIntent->policyCheck) {
                throw new FoundryError(
                    'GENERATE_POLICY_VIOLATION',
                    'validation',
                    ['policy' => $policy],
                    'Generate plan violates repository policy. Re-run with --allow-policy-violations or use --policy-check to inspect the policy result without writing files.',
                );
            }

            if ($executionIntent->dryRun || $executionIntent->policyCheck) {
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
                    policy: $policy,
                    workflowLinkage: $workflowLinkage,
                    templateMetadata: $templateMetadata,
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
                    policy: $policy,
                    frameworkVersion: $frameworkVersion,
                    graphVersion: $graphVersion,
                    sourceHash: $compile->graph->sourceHash(),
                    error: null,
                    undo: null,
                    workflowLinkage: $workflowLinkage,
                    templateMetadata: $templateMetadata,
                ));
                $this->observePlanRecord($planRecordObserver, $planRecord);
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

            $fileSnapshotsAfter = $this->codeWriter->snapshot($this->absolutePaths($executionPlan->affectedFiles));

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
                    policy: $policy,
                    workflowLinkage: $workflowLinkage,
                    templateMetadata: $templateMetadata,
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
                policy: $policy,
                frameworkVersion: $frameworkVersion,
                graphVersion: $graphVersion,
                    sourceHash: $postCompile->graph->sourceHash(),
                    error: null,
                    undo: $this->persistedUndoPayload($fileSnapshots, $fileSnapshotsAfter),
                    workflowLinkage: $workflowLinkage,
                    templateMetadata: $templateMetadata,
                ));
                $this->observePlanRecord($planRecordObserver, $planRecord);
                $payload['record'] = $this->historyRecordReference($record);
                $payload['plan_record'] = $this->planRecordReference($planRecord);

            return $payload;
        } catch (\Throwable $error) {
            $this->restoreGenerateState($packSnapshots, $fileSnapshots, $iterationSnapshots);
            $planRecord = $planStore->persist($this->planRecordPayload(
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
                policy: $policy,
                frameworkVersion: $frameworkVersion,
                graphVersion: $graphVersion,
                sourceHash: $sourceHash,
                error: $this->publicErrorPayload($error),
                undo: null,
                workflowLinkage: $workflowLinkage,
                templateMetadata: $templateMetadata,
            ));
            $this->observePlanRecord($planRecordObserver, $planRecord);

            throw $error;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function runWorkflow(Intent $intent): array
    {
        $loader = new GenerateWorkflowLoader($this->paths);
        $definition = $loader->load((string) $intent->workflowPath);

        return $this->runWorkflowDefinition($intent, $definition);
    }

    /**
     * @return array<string,mixed>
     */
    private function runTemplate(Intent $intent): array
    {
        $template = (new GenerateTemplateLoader($this->paths))->load((string) $intent->templateId);
        $resolution = (new GenerateTemplateResolver())->resolve($template, $intent->templateParameters);
        $templateMetadata = $resolution->metadata();

        if ($template->generateType === 'single') {
            $definition = $resolution->resolvedDefinition;
            $mode = trim((string) ($definition['mode'] ?? ''));
            if (!in_array($mode, Intent::supportedModes(), true)) {
                throw new FoundryError(
                    'GENERATE_TEMPLATE_MODE_INVALID',
                    'validation',
                    ['template_id' => $template->templateId, 'path' => $template->path, 'mode' => $mode],
                    'Generate template single definitions must resolve to mode new, modify, or repair.',
                );
            }

            $rawIntent = trim((string) ($definition['intent'] ?? ''));
            if ($rawIntent === '') {
                throw new FoundryError(
                    'GENERATE_TEMPLATE_INTENT_REQUIRED',
                    'validation',
                    ['template_id' => $template->templateId, 'path' => $template->path],
                    'Generate template single definitions must resolve to an intent.',
                );
            }

            $target = is_string($definition['target'] ?? null) ? trim((string) $definition['target']) : null;
            if (in_array($mode, ['modify', 'repair'], true) && ($target === null || $target === '')) {
                throw new FoundryError(
                    'GENERATE_TEMPLATE_TARGET_REQUIRED',
                    'validation',
                    ['template_id' => $template->templateId, 'path' => $template->path, 'mode' => $mode],
                    'Generate template single definitions require a target for modify and repair modes.',
                );
            }

            $packHints = array_values(array_filter(array_map(
                static fn(mixed $pack): string => trim((string) $pack),
                (array) ($definition['packs'] ?? []),
            ), static fn(string $pack): bool => $pack !== ''));
            $packHints = array_values(array_unique(array_merge($intent->packHints, $packHints)));
            sort($packHints);

            return $this->runSingle(
                new Intent(
                    raw: $rawIntent,
                    mode: $mode,
                    target: $target !== '' ? $target : null,
                    workflowPath: null,
                    templateId: $template->templateId,
                    templateParameters: $intent->templateParameters,
                    multiStep: false,
                    interactive: $intent->interactive,
                    dryRun: $intent->dryRun,
                    policyCheck: $intent->policyCheck,
                    skipVerify: $intent->skipVerify,
                    explainAfter: $intent->explainAfter,
                    allowRisky: $intent->allowRisky,
                    allowPolicyViolations: $intent->allowPolicyViolations,
                    allowDirty: $intent->allowDirty,
                    allowPackInstall: $intent->allowPackInstall,
                    gitCommit: $intent->gitCommit,
                    gitCommitMessage: $intent->gitCommitMessage,
                    packHints: $packHints,
                ),
                workflowLinkage: null,
                templateMetadata: $templateMetadata,
            );
        }

        if ($intent->gitCommit) {
            throw new FoundryError(
                'GENERATE_WORKFLOW_GIT_COMMIT_UNSUPPORTED',
                'validation',
                [],
                'Generate workflow does not support top-level --git-commit yet.',
            );
        }

        if ($intent->explainAfter) {
            throw new FoundryError(
                'GENERATE_WORKFLOW_EXPLAIN_UNSUPPORTED',
                'validation',
                [],
                'Generate workflow does not support top-level --explain yet.',
            );
        }

        $workflow = (new GenerateWorkflowLoader($this->paths))->loadDefinition(
            $resolution->resolvedDefinition,
            $template->path,
        );

        return $this->runWorkflowDefinition($intent, $workflow, $templateMetadata);
    }

    /**
     * @return array<string,mixed>
     */
    private function runWorkflowDefinition(
        Intent $intent,
        GenerateWorkflowDefinition $definition,
        ?array $templateMetadata = null,
    ): array
    {
        $resolver = new GenerateWorkflowContextResolver();
        if ($intent->multiStep && count($definition->steps) < 2) {
            throw new FoundryError(
                'GENERATE_WORKFLOW_MULTI_STEP_MINIMUM',
                'validation',
                ['path' => $definition->path],
                'Generate workflow requires at least two steps when --multi-step is used.',
            );
        }

        $compiler = new GraphCompiler($this->paths, ExtensionRegistry::forPaths($this->paths));
        $artifactStore = new BuildArtifactStore($compiler->buildLayout());
        $planStore = new PlanRecordStore($this->paths);
        $planId = Uuid::v4();
        $runtimeContext = [
            'shared' => $definition->sharedContext,
            'steps' => [],
            'workflow' => [
                'id' => $definition->id,
                'path' => $definition->path,
            ],
        ];
        $steps = [];
        $actionsTaken = [];
        $packsInstalled = [];
        $packsUsed = [];
        $affectedFiles = [];
        $rollbackGuidance = [];
        $verificationSteps = [];
        $error = null;
        foreach ($definition->steps as $index => $stepDefinition) {
            $step = $resolver->resolveStep($stepDefinition, $runtimeContext);

            if ($error !== null) {
                $steps[] = $this->workflowSkippedStepSummary($step, $index);
                continue;
            }

            foreach ($step->dependencies as $dependency) {
                $dependencyStatus = (string) ($runtimeContext['steps'][$dependency]['status'] ?? '');
                if ($dependencyStatus !== 'completed') {
                    $error = [
                        'code' => 'GENERATE_WORKFLOW_DEPENDENCY_UNRESOLVED',
                        'message' => 'Generate workflow dependency did not complete successfully.',
                        'details' => [
                            'step_id' => $step->id,
                            'dependency' => $dependency,
                        ],
                    ];
                    $steps[] = $this->workflowFailedStepSummary(
                        step: $step,
                        index: $index,
                        failure: $error,
                        recordId: null,
                    );
                    $rollbackGuidance = $this->workflowRollbackGuidance($steps);

                    continue 2;
                }
            }

            $stepIntent = new Intent(
                raw: $step->rawIntent,
                mode: $step->mode,
                target: $step->target,
                workflowPath: null,
                templateId: $intent->templateId,
                templateParameters: $intent->templateParameters,
                multiStep: false,
                interactive: $intent->interactive,
                dryRun: $intent->dryRun,
                policyCheck: $intent->policyCheck,
                skipVerify: $intent->skipVerify,
                explainAfter: false,
                allowRisky: $intent->allowRisky,
                allowPolicyViolations: $intent->allowPolicyViolations,
                allowDirty: $intent->allowDirty,
                allowPackInstall: $intent->allowPackInstall,
                gitCommit: false,
                gitCommitMessage: null,
                packHints: array_values(array_unique(array_merge($intent->packHints, $step->packHints))),
            );
            $workflowLinkage = $this->workflowStepLinkage($definition, $step, $index);
            $stepPlanRecord = null;

            try {
                $stepPayload = $this->runSingle(
                    $stepIntent,
                    $workflowLinkage,
                    $templateMetadata,
                    static function (array $record) use (&$stepPlanRecord): void {
                        $stepPlanRecord = $record;
                    },
                );
                $stepSummary = $this->workflowCompletedStepSummary($step, $index, $stepPayload);
                $steps[] = $stepSummary;
                $runtimeContext['steps'][$step->id] = $this->workflowContextExtension($stepSummary);
                $actionsTaken = array_values(array_merge($actionsTaken, array_values(array_filter((array) ($stepPayload['actions_taken'] ?? []), 'is_array'))));
                $packsInstalled = array_values(array_merge($packsInstalled, array_values(array_filter((array) ($stepPayload['packs_installed'] ?? []), 'is_array'))));
                $packsUsed = array_values(array_merge($packsUsed, array_values(array_map('strval', (array) ($stepPayload['packs_used'] ?? [])))));
                $affectedFiles = array_values(array_merge($affectedFiles, array_values(array_map('strval', (array) ($stepPayload['plan']['affected_files'] ?? [])))));
                $verificationSteps[] = [
                    'step_id' => $step->id,
                    'ok' => (bool) (($stepPayload['verification_results']['ok'] ?? false) === true),
                    'skipped' => (bool) (($stepPayload['verification_results']['skipped'] ?? false) === true),
                ];
            } catch (\Throwable $stepError) {
                $publicError = $this->publicErrorPayload($stepError);
                $error = $publicError + [
                    'details' => array_merge(
                        is_array($publicError['details'] ?? null)
                            ? (array) $publicError['details']
                            : [],
                        ['failed_step_id' => $step->id],
                    ),
                ];
                $steps[] = $this->workflowFailedStepSummary(
                    step: $step,
                    index: $index,
                    failure: $error,
                    recordId: is_array($stepPlanRecord) ? (string) ($stepPlanRecord['plan_id'] ?? null) : null,
                );
                $rollbackGuidance = $this->workflowRollbackGuidance($steps);
            }
        }

        $packsUsed = array_values(array_unique($packsUsed));
        sort($packsUsed);
        $affectedFiles = array_values(array_unique($affectedFiles));
        sort($affectedFiles);

        $ok = $error === null;
        $workflow = $this->workflowPayload(
            definition: $definition,
            steps: $steps,
            sharedContext: $runtimeContext,
            rollbackGuidance: $rollbackGuidance,
            failed: !$ok,
        );

        $verificationResults = [
            'ok' => $ok,
            'skipped' => $verificationSteps !== [] && count(array_filter(
                $verificationSteps,
                static fn(array $step): bool => ($step['skipped'] ?? false) === true,
            )) === count($verificationSteps),
            'steps' => $verificationSteps,
        ];

        $payload = [
            'ok' => $ok,
            'intent' => $intent->raw,
            'mode' => 'workflow',
            'actions_taken' => $actionsTaken,
            'verification_results' => $verificationResults,
            'errors' => $error !== null ? [$error] : [],
            'error' => $error,
            'metadata' => [
                'dry_run' => $intent->dryRun,
                'policy_check' => $intent->policyCheck,
                'workflow_path' => $definition->path,
                'multi_step' => $intent->multiStep || count($definition->steps) > 1,
                'context' => $runtimeContext,
                'template' => $templateMetadata,
            ],
            'packs_used' => $packsUsed,
            'packs_installed' => $packsInstalled,
            'workflow' => $workflow,
        ];

        $record = $artifactStore->persistGenerateRecord($this->workflowHistoryPayload($payload));
        $planRecord = $planStore->persist($this->workflowPlanRecordPayload(
            planId: $planId,
            intent: $intent,
            workflow: $workflow,
            actionsTaken: $actionsTaken,
            verificationResults: $verificationResults,
            affectedFiles: $affectedFiles,
            packsUsed: $packsUsed,
            error: $error,
            templateMetadata: $templateMetadata,
        ));
        $payload['record'] = $this->historyRecordReference($record);
        $payload['plan_record'] = $this->planRecordReference($planRecord);

        return $payload;
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

        [$selectedPlan, $selectedPlanName] = $this->selectPersistedPlan(
            $record,
            'PLAN_REPLAY_PLAN_UNAVAILABLE',
            'Persisted plan record does not contain a replayable plan.',
        );
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
    public function undo(string $planId, bool $dryRun = false, bool $confirmDestructive = false): array
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

        if (($record['status'] ?? null) !== 'success' || (($record['metadata']['dry_run'] ?? false) === true)) {
            return $this->undoUnavailablePayload(
                $record,
                'nothing_to_undo',
                'Persisted plan record does not contain applied generate changes to undo.',
            );
        }

        [$selectedPlan, $selectedPlanName] = $this->selectPersistedPlan(
            $record,
            'PLAN_UNDO_PLAN_UNAVAILABLE',
            'Persisted plan record does not contain an undoable plan.',
        );
        $plan = GenerationPlan::fromArray($selectedPlan);
        $intent = $this->replayIntent($record, $plan, false);
        $analysis = $this->analyzeUndo($record, $plan, $intent, $selectedPlanName);
        $operations = $analysis['operations'];
        unset($analysis['operations']);

        if ($dryRun) {
            $analysis['status'] = 'dry_run';
            $analysis['dry_run'] = true;
            $analysis['reversed_actions'] = [];

            return $analysis;
        }

        if (($analysis['requires_confirmation'] ?? false) === true && !$confirmDestructive) {
            $analysis['status'] = 'confirmation_required';
            $analysis['dry_run'] = false;
            $analysis['reversed_actions'] = [];
            $analysis['files_recovered'] = [];
            $analysis['warnings'][] = 'Destructive undo requires explicit confirmation. Re-run with --yes to delete generated files.';
            $analysis['warnings'] = array_values(array_unique(array_map('strval', $analysis['warnings'])));

            return $analysis;
        }

        $reversedActions = $this->applyUndoOperations($operations);
        $analysis['dry_run'] = false;
        $analysis['reversed_actions'] = $reversedActions;
        $analysis['files_recovered'] = array_values(array_unique(array_map(
            static fn(array $action): string => (string) ($action['path'] ?? ''),
            $reversedActions,
        )));
        $analysis['status'] = $this->undoStatus(
            $reversedActions,
            $analysis['irreversible_actions'],
            $analysis['skipped_actions'],
        );

        return $analysis;
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
        ?array $policy = null,
        ?array $workflowLinkage = null,
        ?array $templateMetadata = null,
    ): array {
        $packsUsed = $plan->extension !== null ? [$plan->extension] : [];
        $metadata = [
            'dry_run' => $intent->dryRun,
            'policy_check' => $intent->policyCheck,
            'target' => $context->targets[0] ?? null,
            'context' => $context->toArray(),
        ];
        if ($workflowLinkage !== null) {
            $metadata['workflow'] = $workflowLinkage;
        }
        if ($templateMetadata !== null) {
            $metadata['template'] = $templateMetadata;
        }

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
            'metadata' => $metadata,
            'git' => $git,
            'snapshots' => $snapshots,
            'architecture_diff' => $architectureDiff,
            'post_explain' => $postExplain,
            'post_explain_rendered' => $postExplainRendered,
            'packs_used' => $packsUsed,
            'packs_installed' => $packsInstalled,
            'interactive' => $interactive,
            'safety_routing' => $safetyRouting,
            'policy' => $policy,
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
                'policy_check' => $payload['metadata']['policy_check'] ?? false,
                'target' => $payload['metadata']['target'] ?? null,
                'workflow' => is_array($payload['metadata']['workflow'] ?? null)
                    ? $payload['metadata']['workflow']
                    : null,
                'template' => is_array($payload['metadata']['template'] ?? null)
                    ? $payload['metadata']['template']
                    : null,
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
            'policy' => $payload['policy'] ?? null,
            'source_hash' => $sourceHash,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function workflowHistoryPayload(array $payload): array
    {
        return [
            'intent' => [
                'raw' => $payload['intent'] ?? '',
                'mode' => 'workflow',
            ],
            'target' => $payload['metadata']['workflow_path'] ?? null,
            'workflow' => $payload['workflow'] ?? [],
            'actions_taken' => $payload['actions_taken'] ?? [],
            'verification_results' => $payload['verification_results'] ?? [],
            'metadata' => [
                'dry_run' => $payload['metadata']['dry_run'] ?? false,
                'policy_check' => $payload['metadata']['policy_check'] ?? false,
                'workflow_path' => $payload['metadata']['workflow_path'] ?? null,
                'multi_step' => $payload['metadata']['multi_step'] ?? false,
                'template' => is_array($payload['metadata']['template'] ?? null)
                    ? $payload['metadata']['template']
                    : null,
            ],
            'packs_used' => $payload['packs_used'] ?? [],
            'packs_installed' => $payload['packs_installed'] ?? [],
            'source_hash' => null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function workflowPlanRecordPayload(
        string $planId,
        Intent $intent,
        array $workflow,
        array $actionsTaken,
        array $verificationResults,
        array $affectedFiles,
        array $packsUsed,
        ?array $error,
        ?array $templateMetadata,
    ): array {
        $stepIds = array_values(array_map(
            static fn(array $step): string => (string) ($step['step_id'] ?? ''),
            array_values(array_filter((array) ($workflow['steps'] ?? []), 'is_array')),
        ));

        return [
            'plan_id' => $planId,
            'schema' => $workflow['schema'] ?? null,
            'workflow_id' => $workflow['workflow_id'] ?? null,
            'source' => $workflow['source'] ?? null,
            'started_at' => $workflow['started_at'] ?? null,
            'completed_at' => $workflow['completed_at'] ?? null,
            'steps' => $workflow['steps'] ?? [],
            'shared_context' => $workflow['shared_context'] ?? [],
            'result' => $workflow['result'] ?? [],
            'rollback_guidance' => $workflow['rollback_guidance'] ?? [],
            'timestamp' => null,
            'storage_path' => null,
            'intent' => $intent->raw,
            'mode' => 'workflow',
            'targets' => [],
            'generation_context_packet' => [
                'workflow' => [
                    'id' => $workflow['workflow_id'] ?? null,
                    'path' => $workflow['source']['path'] ?? null,
                    'shared_context_final' => $workflow['shared_context'] ?? [],
                ],
            ],
            'plan_original' => null,
            'plan_final' => null,
            'interactive' => null,
            'user_decisions' => [],
            'actions_executed' => $actionsTaken,
            'affected_files' => $affectedFiles,
            'risk_level' => null,
            'policy' => null,
            'verification_results' => $verificationResults,
            'status' => $error === null ? 'completed' : 'failed',
            'error' => $error,
            'undo' => null,
            'metadata' => [
                'requested_intent' => $intent->toArray(),
                'dry_run' => $intent->dryRun,
                'policy_check' => $intent->policyCheck,
                'interactive_requested' => $intent->interactive,
                'workflow_id' => $workflow['workflow_id'] ?? null,
                'workflow_path' => $workflow['source']['path'] ?? null,
                'multi_step' => count((array) ($workflow['steps'] ?? [])) > 1,
                'step_ids' => $stepIds,
                'packs_used' => $packsUsed,
                'template' => $templateMetadata,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function workflowStepInput(GenerateWorkflowStepDefinition $step): array
    {
        return [
            'intent' => $step->rawIntent,
            'mode' => $step->mode,
            'target' => $step->target,
            'packs' => $step->packHints,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function workflowCompletedStepSummary(GenerateWorkflowStepDefinition $step, int $index, array $payload): array
    {
        return [
            'step_id' => $step->id,
            'index' => $index,
            'description' => $step->description,
            'dependencies' => $this->normalizeWorkflowDependencies($step->dependencies),
            'status' => 'completed',
            'record_id' => $payload['plan_record']['plan_id'] ?? null,
            'failure' => null,
            'input' => $this->workflowStepInput($step),
            'output' => [
                'plan' => $payload['plan'] ?? null,
                'target' => $payload['metadata']['target'] ?? null,
                'actions_taken' => $payload['actions_taken'] ?? [],
                'verification_results' => $payload['verification_results'] ?? [],
                'plan_record' => $payload['plan_record'] ?? null,
                'record' => $payload['record'] ?? null,
                'packs_used' => $payload['packs_used'] ?? [],
                'packs_installed' => $payload['packs_installed'] ?? [],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $failure
     * @return array<string,mixed>
     */
    private function workflowFailedStepSummary(
        GenerateWorkflowStepDefinition $step,
        int $index,
        array $failure,
        ?string $recordId,
    ): array {
        return [
            'step_id' => $step->id,
            'index' => $index,
            'description' => $step->description,
            'dependencies' => $this->normalizeWorkflowDependencies($step->dependencies),
            'status' => 'failed',
            'record_id' => $recordId,
            'failure' => $failure,
            'input' => $this->workflowStepInput($step),
            'output' => [
                'plan_record' => $recordId !== null ? ['plan_id' => $recordId] : null,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function workflowSkippedStepSummary(GenerateWorkflowStepDefinition $step, int $index): array
    {
        return [
            'step_id' => $step->id,
            'index' => $index,
            'description' => $step->description,
            'dependencies' => $this->normalizeWorkflowDependencies($step->dependencies),
            'status' => 'skipped',
            'record_id' => null,
            'failure' => null,
            'input' => $this->workflowStepInput($step),
            'output' => null,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $steps
     * @param array<string,mixed> $sharedContext
     * @return array<string,mixed>
     */
    private function workflowPayload(
        GenerateWorkflowDefinition $definition,
        array $steps,
        array $sharedContext,
        array $rollbackGuidance,
        bool $failed,
    ): array {
        $completedSteps = count(array_filter(
            $steps,
            static fn(array $step): bool => (string) ($step['status'] ?? '') === 'completed',
        ));
        $failedStep = null;
        foreach ($steps as $step) {
            if ((string) ($step['status'] ?? '') === 'failed') {
                $failedStep = (string) ($step['step_id'] ?? '');
                break;
            }
        }

        return [
            'schema' => 'foundry.generate.workflow_record.v1',
            'workflow_id' => $definition->id,
            'source' => [
                'type' => 'repository_file',
                'path' => $definition->path,
            ],
            'status' => $failed ? 'failed' : 'completed',
            'started_at' => null,
            'completed_at' => null,
            'steps' => $steps,
            'shared_context' => $sharedContext,
            'result' => [
                'completed_steps' => $completedSteps,
                'failed_step' => $failedStep !== '' ? $failedStep : null,
                'skipped_steps' => count(array_filter(
                    $steps,
                    static fn(array $step): bool => (string) ($step['status'] ?? '') === 'skipped',
                )),
            ],
            'rollback_guidance' => array_values(array_map('strval', $rollbackGuidance)),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function workflowStepLinkage(
        GenerateWorkflowDefinition $definition,
        GenerateWorkflowStepDefinition $step,
        int $index,
    ): array {
        return [
            'workflow_id' => $definition->id,
            'step_id' => $step->id,
            'step_index' => $index,
            'is_workflow_step' => true,
        ];
    }

    /**
     * @param list<string> $dependencies
     * @return list<string>
     */
    private function normalizeWorkflowDependencies(array $dependencies): array
    {
        $dependencies = array_values(array_unique(array_map('strval', $dependencies)));
        sort($dependencies);

        return $dependencies;
    }

    /**
     * @param array<string,mixed> $stepSummary
     * @return array<string,mixed>
     */
    private function workflowContextExtension(array $stepSummary): array
    {
        $plan = is_array($stepSummary['output']['plan'] ?? null) ? $stepSummary['output']['plan'] : [];
        $target = is_array($stepSummary['output']['target'] ?? null) ? $stepSummary['output']['target'] : null;

        return [
            'status' => $stepSummary['status'] ?? 'completed',
            'feature' => $plan['metadata']['feature'] ?? null,
            'target' => $target,
            'generator_id' => $plan['generator_id'] ?? null,
            'origin' => $plan['origin'] ?? null,
            'affected_files' => $plan['affected_files'] ?? [],
            'plan_record' => $stepSummary['output']['plan_record'] ?? null,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $steps
     * @return array<int,string>
     */
    private function workflowRollbackGuidance(array $steps): array
    {
        $guidance = [];

        foreach ($steps as $step) {
            if ((string) ($step['status'] ?? '') !== 'completed') {
                continue;
            }

            $planRecordId = trim((string) ($step['output']['plan_record']['plan_id'] ?? ''));
            if ($planRecordId === '') {
                continue;
            }

            $guidance[] = sprintf(
                'Review step %s with `foundry plan:show %s` and undo it with `foundry plan:undo %s --dry-run` if needed.',
                (string) ($step['step_id'] ?? 'step'),
                $planRecordId,
                $planRecordId,
            );
        }

        return $guidance;
    }

    /**
     * @param null|\Closure(array<string,mixed>):void $observer
     * @param array<string,mixed> $record
     */
    private function observePlanRecord(?\Closure $observer, array $record): void
    {
        if ($observer instanceof \Closure) {
            $observer($record);
        }
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
        ?array $policy,
        ?string $frameworkVersion,
        ?int $graphVersion,
        ?string $sourceHash,
        ?array $error,
        ?array $undo,
        ?array $workflowLinkage,
        ?array $templateMetadata,
    ): array {
        $effectivePlan = $finalPlan ?? $originalPlan;
        $affectedFiles = $effectivePlan?->affectedFiles ?? [];
        $riskLevel = $this->planRiskLevel($interactiveReview, $safetyRouting);
        $metadata = [
            'framework_version' => $frameworkVersion,
            'graph_version' => $graphVersion,
            'source_hash' => $sourceHash,
            'requested_intent' => $intent->toArray(),
            'dry_run' => $intent->dryRun,
            'policy_check' => $intent->policyCheck,
            'interactive_requested' => $intent->interactive,
            'plan_origin' => $effectivePlan?->origin,
            'generator_id' => $effectivePlan?->generatorId,
            'safety_routing' => $safetyRouting,
        ];
        if ($workflowLinkage !== null) {
            $metadata['workflow'] = $workflowLinkage;
        }
        if ($templateMetadata !== null) {
            $metadata['template'] = $templateMetadata;
        }

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
                    'allow_policy_violations' => $interactiveReview->allowPolicyViolations,
                    'preview' => $interactiveReview->preview,
                    'risk' => $interactiveReview->risk,
                ]
                : null,
            'user_decisions' => $interactiveReview?->userDecisions ?? [],
            'actions_executed' => $actionsTaken,
            'affected_files' => $affectedFiles,
            'risk_level' => $riskLevel,
            'policy' => $policy,
            'verification_results' => $verificationResults,
            'status' => $status,
            'error' => $error,
            'undo' => $undo,
            'metadata' => $metadata,
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
     * @param array<string,array{exists:bool,content:?string}> $beforeSnapshots
     * @param array<string,array{exists:bool,content:?string}> $afterSnapshots
     * @return array<string,mixed>|null
     */
    private function persistedUndoPayload(array $beforeSnapshots, array $afterSnapshots): ?array
    {
        if ($beforeSnapshots === [] && $afterSnapshots === []) {
            return null;
        }

        $paths = array_values(array_unique(array_merge(array_keys($beforeSnapshots), array_keys($afterSnapshots))));
        sort($paths);

        $renderer = new GenerateUnifiedDiffRenderer();
        $before = [];
        $after = [];
        $patches = [];

        foreach ($paths as $path) {
            $beforeSnapshot = $beforeSnapshots[$path] ?? ['exists' => false, 'content' => null];
            $afterSnapshot = $afterSnapshots[$path] ?? ['exists' => false, 'content' => null];
            $relativePath = $this->relativePath((string) $path);

            $before[] = $this->normalizedRollbackSnapshot($relativePath, $beforeSnapshot);
            $after[] = $this->normalizedRollbackSnapshot($relativePath, $afterSnapshot);
            $patches[] = [
                'path' => $relativePath,
                'format' => 'unified_diff',
                'before_exists' => ($beforeSnapshot['exists'] ?? false) === true,
                'after_exists' => ($afterSnapshot['exists'] ?? false) === true,
                'before_hash' => $this->contentHash(($beforeSnapshot['exists'] ?? false) === true ? ($beforeSnapshot['content'] ?? '') : null),
                'after_hash' => $this->contentHash(($afterSnapshot['exists'] ?? false) === true ? ($afterSnapshot['content'] ?? '') : null),
                'patch' => $renderer->render(
                    $relativePath,
                    ($beforeSnapshot['exists'] ?? false) === true ? (string) ($beforeSnapshot['content'] ?? '') : null,
                    ($afterSnapshot['exists'] ?? false) === true ? (string) ($afterSnapshot['content'] ?? '') : null,
                ),
            ];
        }

        return [
            'file_snapshots_before' => $before,
            'file_snapshots_after' => $after,
            'patches' => $patches,
        ];
    }

    /**
     * @param array{exists:bool,content:?string} $snapshot
     * @return array<string,mixed>
     */
    private function normalizedRollbackSnapshot(string $relativePath, array $snapshot): array
    {
        $exists = ($snapshot['exists'] ?? false) === true;
        $content = $exists ? (string) ($snapshot['content'] ?? '') : null;

        return [
            'path' => $relativePath,
            'exists' => $exists,
            'content' => $content,
            'hash' => $this->contentHash($content),
        ];
    }

    private function contentHash(?string $content): ?string
    {
        if ($content === null) {
            return null;
        }

        return hash('sha256', $content);
    }

    /**
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private function undoUnavailablePayload(array $record, string $status, string $warning): array
    {
        return [
            'plan_id' => (string) ($record['plan_id'] ?? ''),
            'status' => $status,
            'dry_run' => false,
            'rollback_mode' => 'snapshot',
            'fully_reversible' => false,
            'reversible' => false,
            'requires_confirmation' => false,
            'confidence_level' => 'low',
            'reversible_actions' => [],
            'reversed_actions' => [],
            'irreversible_actions' => [],
            'skipped_actions' => [],
            'files_recovered' => [],
            'files_unrecoverable' => [],
            'integrity_warnings' => [],
            'warnings' => [$warning],
            'source_record' => [
                'status' => $record['status'] ?? null,
                'timestamp' => $record['timestamp'] ?? null,
                'storage_path' => $record['storage_path'] ?? null,
                'selected_plan' => null,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private function analyzeUndo(array $record, GenerationPlan $plan, Intent $intent, string $selectedPlanName): array
    {
        $beforeSnapshots = $this->undoSnapshotMap($record, 'file_snapshots_before', 'file_snapshots');
        $afterSnapshots = $this->undoSnapshotMap($record, 'file_snapshots_after');
        $patches = $this->undoPatchMap($record);
        $executedByAction = [];

        foreach ((array) ($record['actions_executed'] ?? []) as $action) {
            if (!is_array($action)) {
                continue;
            }

            $path = trim((string) ($action['path'] ?? ''));
            $type = trim((string) ($action['type'] ?? ''));
            if ($path === '' || $type === '') {
                continue;
            }

            $executedByAction[$type . ':' . $path] = $action;
        }

        $reversibleActions = [];
        $irreversibleActions = [];
        $skippedActions = [];
        $integrityWarnings = [];
        $operations = [];
        $requiresConfirmation = false;

        foreach ($plan->actions as $index => $action) {
            if (!is_array($action)) {
                continue;
            }

            $type = trim((string) ($action['type'] ?? ''));
            $path = trim((string) ($action['path'] ?? ''));
            if ($type === '' || $path === '') {
                continue;
            }

            $executed = is_array($executedByAction[$type . ':' . $path] ?? null) ? $executedByAction[$type . ':' . $path] : null;
            $recordedStatus = trim((string) ($executed['status'] ?? ''));
            if ($recordedStatus === '' || $recordedStatus === 'unchanged' || $recordedStatus === 'planned') {
                continue;
            }

            $beforeSnapshot = is_array($beforeSnapshots[$path] ?? null) ? $beforeSnapshots[$path] : null;
            $afterSnapshot = is_array($afterSnapshots[$path] ?? null) ? $afterSnapshots[$path] : null;
            $patchRecord = is_array($patches[$path] ?? null) ? $patches[$path] : null;
            $absolutePath = $this->absolutePath($path);
            $exists = is_file($absolutePath);
            $current = $exists ? (string) (file_get_contents($absolutePath) ?: '') : null;
            $actualHash = $this->contentHash($current);
            $expectedAfterExists = ($afterSnapshot['exists'] ?? false) === true;
            $expectedAfterContent = $expectedAfterExists ? (string) ($afterSnapshot['content'] ?? '') : null;
            $expectedAfterHash = $patchRecord['after_hash'] ?? ($afterSnapshot['hash'] ?? null);

            if (in_array($type, ['create_file', 'add_test'], true)) {
                if ($beforeSnapshot === null) {
                    $irreversibleActions[] = $this->undoIssue($index, $type, $path, 'missing_prechange_snapshot', 'Pre-change file snapshot is missing, so create-file undo cannot prove the path was newly created.');
                    continue;
                }

                if (($beforeSnapshot['exists'] ?? false) === true) {
                    $skippedActions[] = $this->undoIssue($index, $type, $path, 'preexisting_path', 'Undo skipped because the path existed before generate and deleting it would be unsafe.');
                    continue;
                }

                if (!$exists) {
                    $skippedActions[] = $this->undoIssue($index, $type, $path, 'already_reversed', 'Undo skipped because the generated file is already absent.');
                    continue;
                }

                if ($expectedAfterHash !== null && $actualHash !== $expectedAfterHash) {
                    $integrityWarnings[] = $this->integrityWarning($path, $expectedAfterHash, $actualHash, 'Current file hash no longer matches the stored generated hash.');
                    $skippedActions[] = $this->undoIssue($index, $type, $path, 'integrity_mismatch', 'Undo skipped because the current file hash differs from the stored generated hash.');
                    continue;
                }

                if (!$expectedAfterExists || $current !== $expectedAfterContent) {
                    $skippedActions[] = $this->undoIssue($index, $type, $path, 'current_state_differs', 'Undo skipped because the current file contents differ from the stored generated contents.');
                    continue;
                }

                $publicAction = [
                    'action_index' => $index,
                    'type' => $type,
                    'path' => $path,
                    'rollback_mode' => 'snapshot',
                    'strategy' => 'delete_created_file',
                    'recorded_status' => $recordedStatus,
                ];
                $reversibleActions[] = $publicAction;
                $operations[] = $publicAction + [
                    'absolute_path' => $absolutePath,
                    'destructive' => true,
                    'before_snapshot' => $beforeSnapshot,
                ];
                $requiresConfirmation = true;

                continue;
            }

            if (in_array($type, ['update_file', 'update_docs', 'delete_file'], true)) {
                if ($expectedAfterHash !== null && $actualHash !== $expectedAfterHash) {
                    $integrityWarnings[] = $this->integrityWarning($path, $expectedAfterHash, $actualHash, 'Current file hash no longer matches the stored generated hash.');
                    $skippedActions[] = $this->undoIssue($index, $type, $path, 'integrity_mismatch', 'Undo skipped because the current file hash differs from the stored generated hash.');
                    continue;
                }

                $patchOperation = $this->patchUndoOperation($path, $current, $patchRecord);
                if ($patchOperation !== null) {
                    $publicAction = [
                        'action_index' => $index,
                        'type' => $type,
                        'path' => $path,
                        'rollback_mode' => 'patch',
                        'strategy' => $type === 'delete_file' ? 'restore_deleted_file' : 'restore_previous_contents',
                        'recorded_status' => $recordedStatus,
                    ];
                    $reversibleActions[] = $publicAction;
                    $operations[] = $publicAction + [
                        'absolute_path' => $absolutePath,
                        'destructive' => false,
                        'restored_content' => $patchOperation['restored_content'],
                        'restore_exists' => $patchOperation['restore_exists'],
                    ];

                    continue;
                }

                if ($beforeSnapshot === null || ($beforeSnapshot['exists'] ?? false) !== true) {
                    $irreversibleActions[] = $this->undoIssue($index, $type, $path, 'missing_prior_contents', 'Undo cannot restore this file because prior contents were not persisted.');
                    continue;
                }

                if ($type !== 'delete_file' && !$exists) {
                    $skippedActions[] = $this->undoIssue($index, $type, $path, 'missing_current_file', 'Undo skipped because the updated file is now missing.');
                    continue;
                }

                if ($exists && $current === (string) ($beforeSnapshot['content'] ?? '')) {
                    $skippedActions[] = $this->undoIssue($index, $type, $path, 'already_reversed', 'Undo skipped because the file already matches the persisted pre-change contents.');
                    continue;
                }

                if ($type === 'delete_file' && !$exists) {
                    $publicAction = [
                        'action_index' => $index,
                        'type' => $type,
                        'path' => $path,
                        'rollback_mode' => 'snapshot',
                        'strategy' => 'restore_deleted_file',
                        'recorded_status' => $recordedStatus,
                    ];
                    $reversibleActions[] = $publicAction;
                    $operations[] = $publicAction + [
                        'absolute_path' => $absolutePath,
                        'destructive' => false,
                        'before_snapshot' => $beforeSnapshot,
                    ];
                    continue;
                }

                $publicAction = [
                    'action_index' => $index,
                    'type' => $type,
                    'path' => $path,
                    'rollback_mode' => 'snapshot',
                    'strategy' => $type === 'delete_file' ? 'restore_deleted_file' : 'restore_previous_contents',
                    'recorded_status' => $recordedStatus,
                ];
                $reversibleActions[] = $publicAction;
                $operations[] = $publicAction + [
                    'absolute_path' => $absolutePath,
                    'destructive' => false,
                    'before_snapshot' => $beforeSnapshot,
                ];

                continue;
            }

            $irreversibleActions[] = $this->undoIssue($index, $type, $path, 'unsupported_action_type', 'Undo does not support this action type in V1.');
        }

        $warnings = [];
        if ($reversibleActions === [] && $irreversibleActions === [] && $skippedActions === []) {
            $warnings[] = 'No applied file changes were recorded for this plan.';
        }
        if ($irreversibleActions !== []) {
            $warnings[] = 'Some recorded actions are not reversible in V1.';
        }
        if ($skippedActions !== []) {
            $warnings[] = 'Some reversible actions were skipped because the current filesystem state is unsafe or already reverted.';
        }
        if ($integrityWarnings !== []) {
            $warnings[] = 'Some rollback inputs failed integrity checks and were refused.';
        }
        if ($requiresConfirmation) {
            $warnings[] = 'Deleting generated files is considered destructive and requires explicit confirmation.';
        }

        $rollbackMode = $this->rollbackMode($reversibleActions);
        $filesRecovered = array_values(array_unique(array_map(
            static fn(array $action): string => (string) ($action['path'] ?? ''),
            $reversibleActions,
        )));
        $filesUnrecoverable = array_values(array_unique(array_filter(array_merge(
            array_map(static fn(array $action): string => (string) ($action['path'] ?? ''), $irreversibleActions),
            array_map(static fn(array $action): string => (string) ($action['path'] ?? ''), $skippedActions),
        ))));

        return [
            'plan_id' => (string) ($record['plan_id'] ?? ''),
            'status' => 'planned',
            'dry_run' => false,
            'rollback_mode' => $rollbackMode,
            'fully_reversible' => $irreversibleActions === [] && $skippedActions === [],
            'reversible' => $irreversibleActions === [] && $skippedActions === [],
            'requires_confirmation' => $requiresConfirmation,
            'confidence_level' => $this->undoConfidenceLevel($irreversibleActions, $skippedActions, $integrityWarnings),
            'reversible_actions' => $reversibleActions,
            'reversed_actions' => [],
            'irreversible_actions' => $irreversibleActions,
            'skipped_actions' => $skippedActions,
            'files_recovered' => $filesRecovered,
            'files_unrecoverable' => $filesUnrecoverable,
            'integrity_warnings' => $integrityWarnings,
            'warnings' => $warnings,
            'source_record' => [
                'status' => $record['status'] ?? null,
                'timestamp' => $record['timestamp'] ?? null,
                'storage_path' => $record['storage_path'] ?? null,
                'selected_plan' => $selectedPlanName,
            ],
            'operations' => $operations,
        ];
    }

    /**
     * @param array<string,mixed> $record
     * @return array<string,array<string,mixed>>
     */
    private function undoSnapshotMap(array $record, string $primaryKey, ?string $legacyKey = null): array
    {
        $snapshots = [];

        $source = (array) ($record['undo'][$primaryKey] ?? []);
        if ($source === [] && $legacyKey !== null) {
            $source = (array) ($record['undo'][$legacyKey] ?? []);
        }

        foreach ($source as $snapshot) {
            if (!is_array($snapshot)) {
                continue;
            }

            $path = trim((string) ($snapshot['path'] ?? ''));
            if ($path === '') {
                continue;
            }

            $snapshots[$path] = [
                'exists' => ($snapshot['exists'] ?? false) === true,
                'content' => array_key_exists('content', $snapshot) ? $snapshot['content'] : null,
                'hash' => $snapshot['hash'] ?? $this->contentHash(($snapshot['exists'] ?? false) === true ? (string) ($snapshot['content'] ?? '') : null),
            ];
        }

        ksort($snapshots);

        return $snapshots;
    }

    /**
     * @param array<string,mixed> $record
     * @return array<string,array<string,mixed>>
     */
    private function undoPatchMap(array $record): array
    {
        $patches = [];

        foreach ((array) ($record['undo']['patches'] ?? []) as $patch) {
            if (!is_array($patch)) {
                continue;
            }

            $path = trim((string) ($patch['path'] ?? ''));
            if ($path === '') {
                continue;
            }

            $patches[$path] = $patch;
        }

        ksort($patches);

        return $patches;
    }

    /**
     * @return array<string,mixed>
     */
    private function undoIssue(int $index, string $type, string $path, string $reason, string $message): array
    {
        return [
            'action_index' => $index,
            'type' => $type,
            'path' => $path,
            'reason' => $reason,
            'message' => $message,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function integrityWarning(string $path, ?string $expectedHash, ?string $actualHash, string $message): array
    {
        return [
            'path' => $path,
            'expected_hash' => $expectedHash,
            'actual_hash' => $actualHash,
            'message' => $message,
        ];
    }

    /**
     * @param array<string,mixed>|null $patchRecord
     * @return array<string,mixed>|null
     */
    private function patchUndoOperation(string $path, ?string $current, ?array $patchRecord): ?array
    {
        if ($patchRecord === null || trim((string) ($patchRecord['patch'] ?? '')) === '') {
            return null;
        }

        try {
            $restoreExists = ($patchRecord['before_exists'] ?? false) === true;
            $restoredContent = $this->diffApplier->reverse(
                $current,
                (string) $patchRecord['patch'],
                $restoreExists,
            );

            return [
                'path' => $path,
                'restore_exists' => $restoreExists,
                'restored_content' => $restoredContent,
            ];
        } catch (FoundryError) {
            return null;
        }
    }

    /**
     * @param list<array<string,mixed>> $operations
     * @return list<array<string,mixed>>
     */
    private function applyUndoOperations(array $operations): array
    {
        if ($operations === []) {
            return [];
        }

        $reversed = [];
        $restoreSnapshots = [];

        foreach ($operations as $operation) {
            if (!is_array($operation)) {
                continue;
            }

            $strategy = (string) ($operation['strategy'] ?? '');
            $absolutePath = (string) ($operation['absolute_path'] ?? '');
            if ($absolutePath === '') {
                continue;
            }

            if ($strategy === 'delete_created_file') {
                if (is_file($absolutePath) && !unlink($absolutePath)) {
                    throw new FoundryError(
                        'PLAN_UNDO_DELETE_FAILED',
                        'filesystem',
                        ['path' => $this->relativePath($absolutePath)],
                        'Unable to delete generated file during undo.',
                    );
                }

                $reversed[] = [
                    'action_index' => $operation['action_index'] ?? null,
                    'type' => $operation['type'] ?? null,
                    'path' => $operation['path'] ?? null,
                    'strategy' => $strategy,
                    'status' => 'deleted',
                ];

                continue;
            }

            if (array_key_exists('restore_exists', $operation)) {
                $restoreExists = ($operation['restore_exists'] ?? false) === true;
                $restoreContent = $restoreExists ? (string) ($operation['restored_content'] ?? '') : null;

                if (!$restoreExists) {
                    if (is_file($absolutePath) && !unlink($absolutePath)) {
                        throw new FoundryError(
                            'PLAN_UNDO_DELETE_FAILED',
                            'filesystem',
                            ['path' => $this->relativePath($absolutePath)],
                            'Unable to delete generated file during undo.',
                        );
                    }
                } else {
                    $directory = dirname($absolutePath);
                    if (!is_dir($directory)) {
                        mkdir($directory, 0777, true);
                    }

                    file_put_contents($absolutePath, $restoreContent);
                }

                $reversed[] = [
                    'action_index' => $operation['action_index'] ?? null,
                    'type' => $operation['type'] ?? null,
                    'path' => $operation['path'] ?? null,
                    'rollback_mode' => $operation['rollback_mode'] ?? 'patch',
                    'strategy' => $strategy,
                    'status' => 'restored',
                ];

                continue;
            }

            $snapshot = is_array($operation['before_snapshot'] ?? null) ? $operation['before_snapshot'] : null;
            if ($snapshot === null) {
                continue;
            }

            $restoreSnapshots[$absolutePath] = [
                'exists' => ($snapshot['exists'] ?? false) === true,
                'content' => array_key_exists('content', $snapshot) ? $snapshot['content'] : null,
            ];
            $reversed[] = [
                'action_index' => $operation['action_index'] ?? null,
                'type' => $operation['type'] ?? null,
                'path' => $operation['path'] ?? null,
                'rollback_mode' => $operation['rollback_mode'] ?? 'snapshot',
                'strategy' => $strategy,
                'status' => 'restored',
            ];
        }

        if ($restoreSnapshots !== []) {
            $this->codeWriter->restore($restoreSnapshots);
        }

        $this->rebuildAfterRestore();

        return $reversed;
    }

    /**
     * @param list<array<string,mixed>> $reversibleActions
     */
    private function rollbackMode(array $reversibleActions): string
    {
        if ($reversibleActions === []) {
            return 'snapshot';
        }

        foreach ($reversibleActions as $action) {
            if (($action['rollback_mode'] ?? 'snapshot') !== 'patch') {
                return 'snapshot';
            }
        }

        return 'patch';
    }

    /**
     * @param list<array<string,mixed>> $irreversibleActions
     * @param list<array<string,mixed>> $skippedActions
     * @param list<array<string,mixed>> $integrityWarnings
     */
    private function undoConfidenceLevel(array $irreversibleActions, array $skippedActions, array $integrityWarnings): string
    {
        if ($integrityWarnings !== [] || $irreversibleActions !== []) {
            return 'low';
        }

        if ($skippedActions !== []) {
            return 'medium';
        }

        return 'high';
    }

    /**
     * @param list<array<string,mixed>> $reversedActions
     * @param list<array<string,mixed>> $irreversibleActions
     * @param list<array<string,mixed>> $skippedActions
     */
    private function undoStatus(array $reversedActions, array $irreversibleActions, array $skippedActions): string
    {
        if ($reversedActions === [] && $irreversibleActions === [] && $skippedActions === []) {
            return 'nothing_to_undo';
        }

        if ($reversedActions !== [] && $irreversibleActions === [] && $skippedActions === []) {
            return 'undone';
        }

        if ($reversedActions !== []) {
            return 'partial';
        }

        if ($irreversibleActions !== [] && $skippedActions === []) {
            return 'irreversible';
        }

        return 'skipped';
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
    private function selectPersistedPlan(array $record, string $errorCode, string $errorMessage): array
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
            $errorCode,
            'validation',
            [
                'plan_id' => $record['plan_id'] ?? null,
                'status' => $record['status'] ?? null,
            ],
            $errorMessage,
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
