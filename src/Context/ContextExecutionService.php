<?php

declare(strict_types=1);

namespace Foundry\Context;

use Foundry\Generation\ContextManifestGenerator;
use Foundry\Generation\FeatureGenerator;
use Foundry\Support\FoundryError;
use Foundry\Support\FeatureNaming;
use Foundry\Support\Paths;
use Foundry\Support\Str;
use Foundry\Support\Yaml;

final class ContextExecutionService
{
    private readonly ContextInspectionService $inspectionService;
    private readonly ContextInitService $initService;
    private readonly FeatureGenerator $featureGenerator;
    private readonly ContextManifestGenerator $contextManifestGenerator;
    private readonly ExecutionSpecImplementationLogService $executionSpecImplementationLogService;

    public function __construct(
        private readonly Paths $paths,
        private readonly FeatureNameValidator $featureNameValidator = new FeatureNameValidator(),
        private readonly ContextFileResolver $resolver = new ContextFileResolver(),
        ?ContextInspectionService $inspectionService = null,
        ?ContextInitService $initService = null,
        ?FeatureGenerator $featureGenerator = null,
        ?ContextManifestGenerator $contextManifestGenerator = null,
        ?ExecutionSpecImplementationLogService $executionSpecImplementationLogService = null,
    ) {
        $this->inspectionService = $inspectionService ?? new ContextInspectionService($paths);
        $this->initService = $initService ?? new ContextInitService($paths);
        $this->featureGenerator = $featureGenerator ?? new FeatureGenerator($paths);
        $this->contextManifestGenerator = $contextManifestGenerator ?? new ContextManifestGenerator($paths);
        $this->executionSpecImplementationLogService = $executionSpecImplementationLogService ?? new ExecutionSpecImplementationLogService($paths);
    }

    /**
     * @return array{
     *     feature:string,
     *     mode:string,
     *     paths:array{spec:string,state:string,decisions:string,feature_base:string,manifest:string,prompts:string},
     *     spec:array<string,string>,
     *     state:array<string,string>,
     *     decisions:list<array<string,string>>,
     *     spec_tracking_items:list<string>,
     *     description:string,
     *     execution_summary:string
     * }
     */
    public function buildExecutionInput(string $featureName): array
    {
        $featureName = FeatureNaming::canonical($featureName);
        $specPath = $this->resolver->specPath($featureName);
        $statePath = $this->resolver->statePath($featureName);
        $decisionsPath = $this->resolver->decisionsPath($featureName);
        $featureBase = FeatureNaming::directory($featureName);
        $manifestPath = $featureBase . '/feature.yaml';
        $promptsPath = $featureBase . '/prompts.md';

        $spec = $this->parseSections(
            $this->readFile($specPath),
            [
                'Purpose',
                'Goals',
                'Non-Goals',
                'Constraints',
                'Expected Behavior',
                'Acceptance Criteria',
                'Assumptions',
            ],
        );
        $state = $this->parseSections(
            $this->readFile($statePath),
            ['Purpose', 'Current State', 'Open Questions', 'Next Steps'],
        );

        $trackingItems = array_values(array_merge(
            $this->meaningfulSectionItems($spec['Expected Behavior'] ?? ''),
            $this->meaningfulSectionItems($spec['Acceptance Criteria'] ?? ''),
        ));

        return [
            'feature' => $featureName,
            'mode' => is_file($this->paths->join($manifestPath)) ? 'modify' : 'new',
            'paths' => [
                'spec' => $specPath,
                'state' => $statePath,
                'decisions' => $decisionsPath,
                'feature_base' => $featureBase,
                'manifest' => $manifestPath,
                'prompts' => $promptsPath,
            ],
            'spec' => $spec,
            'state' => $state,
            'decisions' => $this->parseDecisionEntries($this->readFile($decisionsPath)),
            'spec_tracking_items' => $trackingItems,
            'description' => $this->descriptionFromSpec($featureName, $spec, $trackingItems),
            'execution_summary' => $this->executionSummary($featureName, $spec, $trackingItems),
        ];
    }

    public function execute(string $featureName, bool $repair = false, bool $autoRepair = false): ExecutionResult
    {
        $featureName = FeatureNaming::canonical($featureName);
        $nameValidation = $this->featureNameValidator->validate($featureName);
        if (!$nameValidation->valid) {
            return new ExecutionResult(
                feature: $featureName,
                status: 'blocked',
                canProceed: false,
                requiresRepair: true,
                repairAttempted: false,
                repairSuccessful: false,
                actionsTaken: [],
                issues: $this->validationIssuesToArray($nameValidation->issues),
                requiredActions: ['Use a lowercase kebab-case feature name.'],
            );
        }

        $inspection = $this->inspectionService->inspectFeature($featureName);
        $verification = $this->inspectionService->verifyFeature($featureName);
        $repairActions = [];
        $repairAttempted = false;
        $repairSuccessful = false;

        if (!(bool) ($inspection['can_proceed'] ?? false)) {
            if (!$repair && !$autoRepair) {
                return new ExecutionResult(
                    feature: $featureName,
                    status: 'blocked',
                    canProceed: false,
                    requiresRepair: true,
                    repairAttempted: false,
                    repairSuccessful: false,
                    actionsTaken: [],
                    issues: array_values((array) ($verification['issues'] ?? [])),
                    requiredActions: array_values(array_map('strval', (array) ($inspection['required_actions'] ?? []))),
                );
            }

            $repairAttempted = true;
            $seenRepairSets = [];

            while (!(bool) ($inspection['can_proceed'] ?? false)) {
                $requiredActions = array_values(array_map('strval', (array) ($inspection['required_actions'] ?? [])));
                if ($requiredActions === []) {
                    break;
                }

                $repairKey = implode("\n", $requiredActions);
                if (isset($seenRepairSets[$repairKey])) {
                    break;
                }

                $seenRepairSets[$repairKey] = true;
                $applied = $this->applyRepairs($featureName, $requiredActions);
                if ($applied === []) {
                    break;
                }

                $repairActions = array_values(array_merge($repairActions, $applied));
                $inspection = $this->inspectionService->inspectFeature($featureName);
                $verification = $this->inspectionService->verifyFeature($featureName);
            }

            $repairActions = array_values(array_unique($repairActions));
            sort($repairActions);
            $repairSuccessful = (bool) ($inspection['can_proceed'] ?? false);

            if (!$repairSuccessful) {
                return new ExecutionResult(
                    feature: $featureName,
                    status: 'blocked',
                    canProceed: false,
                    requiresRepair: true,
                    repairAttempted: true,
                    repairSuccessful: false,
                    actionsTaken: $repairActions,
                    issues: array_values((array) ($verification['issues'] ?? [])),
                    requiredActions: array_values(array_map('strval', (array) ($inspection['required_actions'] ?? []))),
                );
            }
        }

        $executionInput = $this->buildExecutionInput($featureName);
        $implementationActions = $this->executeFeatureWork($executionInput);
        $contextActions = $this->updateContextAfterExecution(
            $executionInput,
            $repairAttempted,
        );

        $finalInspection = $this->inspectionService->inspectFeature($featureName);
        $finalVerification = $this->inspectionService->verifyFeature($featureName);
        $actionsTaken = array_values(array_merge($repairActions, $implementationActions, $contextActions));

        if (
            !(bool) ($finalInspection['can_proceed'] ?? false)
            || array_values((array) ($finalVerification['issues'] ?? [])) !== []
        ) {
            return new ExecutionResult(
                feature: $featureName,
                status: 'completed_with_issues',
                canProceed: (bool) ($finalInspection['can_proceed'] ?? false),
                requiresRepair: (bool) ($finalInspection['requires_repair'] ?? true),
                repairAttempted: $repairAttempted,
                repairSuccessful: $repairSuccessful,
                actionsTaken: $actionsTaken,
                issues: array_values((array) ($finalVerification['issues'] ?? [])),
                requiredActions: array_values(array_map('strval', (array) ($finalInspection['required_actions'] ?? []))),
            );
        }

        return new ExecutionResult(
            feature: $featureName,
            status: $repairSuccessful ? 'repaired' : 'completed',
            canProceed: true,
            requiresRepair: false,
            repairAttempted: $repairAttempted,
            repairSuccessful: $repairSuccessful,
            actionsTaken: $actionsTaken,
            issues: [],
            requiredActions: [],
        );
    }

    /**
     * @return array{
     *     spec_id:string,
     *     feature:string,
     *     status:string,
     *     can_proceed:bool,
     *     requires_repair:bool,
     *     repair_attempted:bool,
     *     repair_successful:bool,
     *     actions_taken:list<string>,
     *     issues:list<array<string,mixed>>,
     *     required_actions:list<string>
     * }
     */
    public function executeSpec(ExecutionSpec $executionSpec, bool $repair = false, bool $autoRepair = false): array
    {
        $conflict = $this->canonicalConflictForExecutionSpec($executionSpec);
        if ($conflict !== null) {
            return [
                'spec_id' => $executionSpec->specId,
                'feature' => $executionSpec->feature,
                'status' => 'blocked',
                'can_proceed' => false,
                'requires_repair' => true,
                'repair_attempted' => false,
                'repair_successful' => false,
                'actions_taken' => [],
                'issues' => [$conflict['issue']],
                'required_actions' => $conflict['required_actions'],
            ];
        }

        $payload = $this->execute($executionSpec->feature, repair: $repair, autoRepair: $autoRepair)->toArray();
        if (in_array($payload['status'], ['completed', 'repaired'], true)) {
            try {
                $logAction = $this->executionSpecImplementationLogService->recordIfEligible($executionSpec);
                if (is_string($logAction) && $logAction !== '') {
                    $payload['actions_taken'][] = $logAction;
                }
            } catch (FoundryError $error) {
                $payload['status'] = 'completed_with_issues';
                $payload['issues'][] = [
                    'code' => $error->errorCode,
                    'message' => $error->getMessage(),
                    'file_path' => (string) ($error->details['path'] ?? ''),
                ];
                $payload['required_actions'] = array_values(array_unique(array_merge(
                    array_map('strval', (array) $payload['required_actions']),
                    ['Restore write access to docs/specs/implementation-log.md and record the missing implementation entry.'],
                )));
            }
        }

        if (in_array($payload['status'], ['completed', 'repaired', 'completed_with_issues'], true)) {
            $payload['actions_taken'][] = 'Applied execution spec: ' . $executionSpec->path;
        }

        return [
            'spec_id' => $executionSpec->specId,
            'feature' => (string) $payload['feature'],
            'status' => (string) $payload['status'],
            'can_proceed' => (bool) $payload['can_proceed'],
            'requires_repair' => (bool) $payload['requires_repair'],
            'repair_attempted' => (bool) $payload['repair_attempted'],
            'repair_successful' => (bool) $payload['repair_successful'],
            'actions_taken' => array_values(array_map('strval', (array) $payload['actions_taken'])),
            'issues' => array_values((array) $payload['issues']),
            'required_actions' => array_values(array_map('strval', (array) $payload['required_actions'])),
        ];
    }

    /**
     * @param array{
     *     feature:string,
     *     mode:string,
     *     paths:array{spec:string,state:string,decisions:string,feature_base:string,manifest:string,prompts:string},
     *     spec:array<string,string>,
     *     state:array<string,string>,
     *     decisions:list<array<string,string>>,
     *     spec_tracking_items:list<string>,
     *     description:string,
     *     execution_summary:string
     * } $input
     * @return list<string>
     */
    private function executeFeatureWork(array $input): array
    {
        return $input['mode'] === 'new'
            ? $this->createFeatureFromContext($input)
            : $this->modifyFeatureFromContext($input);
    }

    /**
     * @param array{
     *     feature:string,
     *     mode:string,
     *     paths:array{spec:string,state:string,decisions:string,feature_base:string,manifest:string,prompts:string},
     *     spec:array<string,string>,
     *     state:array<string,string>,
     *     decisions:list<array<string,string>>,
     *     spec_tracking_items:list<string>,
     *     description:string,
     *     execution_summary:string
     * } $input
     * @return list<string>
     */
    private function createFeatureFromContext(array $input): array
    {
        $definition = [
            'feature' => FeatureNaming::codeSafe($input['feature']),
            'canonical_feature' => $input['feature'],
            'description' => $input['description'],
            'kind' => 'http',
            'owners' => ['platform'],
            'route' => [
                'method' => 'POST',
                'path' => '/' . $input['feature'],
            ],
            'input' => ['fields' => []],
            'output' => [
                'fields' => [
                    'status' => ['type' => 'string', 'required' => true],
                    'feature' => ['type' => 'string', 'required' => true],
                ],
            ],
            'auth' => [
                'required' => false,
                'strategies' => [],
                'permissions' => [],
            ],
            'database' => [
                'reads' => [],
                'writes' => [],
                'queries' => [],
                'transactions' => 'optional',
            ],
            'cache' => [
                'reads' => [],
                'writes' => [],
                'invalidate' => [],
            ],
            'events' => [
                'emit' => [],
                'subscribe' => [],
            ],
            'jobs' => [
                'dispatch' => [],
            ],
            'tests' => [
                'required' => ['contract', 'feature'],
            ],
        ];

        $files = $this->featureGenerator->generateFromArray($definition, false);
        $actions = [];

        foreach ($files as $path) {
            $actions[] = 'Implemented feature scaffold: ' . $this->relativePath($path);
        }

        sort($actions);

        return $actions;
    }

    /**
     * @param array{
     *     feature:string,
     *     mode:string,
     *     paths:array{spec:string,state:string,decisions:string,feature_base:string,manifest:string,prompts:string},
     *     spec:array<string,string>,
     *     state:array<string,string>,
     *     decisions:list<array<string,string>>,
     *     spec_tracking_items:list<string>,
     *     description:string,
     *     execution_summary:string
     * } $input
     * @return list<string>
     */
    private function modifyFeatureFromContext(array $input): array
    {
        $manifestPath = $this->paths->join($input['paths']['manifest']);
        $manifest = is_file($manifestPath) ? Yaml::parseFile($manifestPath) : [];
        $manifest['description'] = $input['description'];
        file_put_contents($manifestPath, Yaml::dump($manifest));

        $promptsPath = $this->paths->join($input['paths']['prompts']);
        $prompts = $this->updatedPrompts($promptsPath, $input['feature'], $input['execution_summary']);
        file_put_contents($promptsPath, $prompts);
        $this->contextManifestGenerator->write($input['feature'], $manifest);

        return [
            'Updated feature manifest: ' . $input['paths']['manifest'],
            'Updated feature prompts: ' . $input['paths']['prompts'],
            'Updated context manifest: app/features/' . $input['feature'] . '/context.manifest.json',
        ];
    }

    /**
     * @param array{
     *     feature:string,
     *     mode:string,
     *     paths:array{spec:string,state:string,decisions:string,feature_base:string,manifest:string,prompts:string},
     *     spec:array<string,string>,
     *     state:array<string,string>,
     *     decisions:list<array<string,string>>,
     *     spec_tracking_items:list<string>,
     *     description:string,
     *     execution_summary:string
     * } $input
     * @return list<string>
     */
    private function updateContextAfterExecution(array $input, bool $repairAttempted): array
    {
        $actions = [];
        $statePath = $this->paths->join($input['paths']['state']);
        $updatedState = $this->updatedStateDocument($input, $repairAttempted);
        if ((string) file_get_contents($statePath) !== $updatedState) {
            file_put_contents($statePath, $updatedState);
            $actions[] = 'Updated feature state: ' . $input['paths']['state'];
        }

        $decisionsPath = $this->paths->join($input['paths']['decisions']);
        $updatedDecisions = $this->appendExecutionDecision($input, $repairAttempted);
        if ((string) file_get_contents($decisionsPath) !== $updatedDecisions) {
            file_put_contents($decisionsPath, $updatedDecisions);
            $actions[] = 'Appended decision entry: ' . $input['paths']['decisions'];
        }

        return $actions;
    }

    /**
     * @param list<string> $requiredActions
     * @return list<string>
     */
    private function applyRepairs(string $featureName, array $requiredActions): array
    {
        $actions = [];

        foreach ($requiredActions as $requiredAction) {
            $applied = $this->applyRepairAction($featureName, $requiredAction);
            if ($applied === null) {
                continue;
            }

            $actions[] = $applied;
        }

        $actions = array_values(array_unique($actions));
        sort($actions);

        return $actions;
    }

    private function applyRepairAction(string $featureName, string $requiredAction): ?string
    {
        if (str_starts_with($requiredAction, 'Create missing ')) {
            $result = $this->initService->init($featureName);
            if ($result['created'] === []) {
                return null;
            }

            $actions = array_values(array_map(
                static fn(string $path): string => 'Created missing context file: ' . $path,
                $result['created'],
            ));

            return implode(' | ', $actions);
        }

        if (preg_match('/^Fix malformed spec heading in (.+)\.$/', $requiredAction, $matches) === 1) {
            $path = (string) $matches[1];
            $this->repairHeading($path, '# Feature Spec: ' . $featureName);

            return 'Fixed malformed spec heading: ' . $path;
        }

        if (preg_match('/^Fix malformed state heading in (.+)\.$/', $requiredAction, $matches) === 1) {
            $path = (string) $matches[1];
            $this->repairHeading($path, '# Feature: ' . $featureName);

            return 'Fixed malformed state heading: ' . $path;
        }

        if (preg_match('/^Add missing required section "## (.+)" to (.+)\.$/', $requiredAction, $matches) === 1) {
            $section = (string) $matches[1];
            $path = (string) $matches[2];
            $this->appendMissingSection($path, $section);

            return 'Added missing section: ' . $path . ' :: ' . $section;
        }

        if (preg_match('/^Add missing decision timestamp line to (.+)\.$/', $requiredAction, $matches) === 1) {
            $path = (string) $matches[1];
            $this->repairDecisionTimestamps($path, true);

            return 'Added missing decision timestamps: ' . $path;
        }

        if (preg_match('/^Fix decision timestamp to ISO-8601 in (.+)\.$/', $requiredAction, $matches) === 1) {
            $path = (string) $matches[1];
            $this->repairDecisionTimestamps($path, false);

            return 'Fixed decision timestamps: ' . $path;
        }

        if (preg_match('/^Add missing required decision subsection "\*\*(.+)\*\*" to (.+)\.$/', $requiredAction, $matches) === 1) {
            $section = (string) $matches[1];
            $path = (string) $matches[2];
            $this->repairDecisionSubsections($path, $section);

            return 'Added missing decision subsection: ' . $path . ' :: ' . $section;
        }

        if (in_array($requiredAction, [
            'Reflect the spec requirement in Current State, Open Questions, or Next Steps.',
            'Update the feature state to reflect current implementation or remove unsupported state claims.',
            'Update the feature state to reflect current implementation.',
        ], true)) {
            $input = $this->buildExecutionInput($featureName);
            $statePath = $this->paths->join($input['paths']['state']);
            file_put_contents($statePath, $this->updatedStateDocument($input, true));

            return 'Updated feature state: ' . $input['paths']['state'];
        }

        if ($requiredAction === 'Log divergence in the decision ledger.') {
            $input = $this->buildExecutionInput($featureName);
            $decisionsPath = $this->paths->join($input['paths']['decisions']);
            file_put_contents($decisionsPath, $this->appendExecutionDecision($input, true));

            return 'Appended decision entry: ' . $input['paths']['decisions'];
        }

        return null;
    }

    private function repairHeading(string $relativePath, string $expectedHeading): void
    {
        $path = $this->paths->join($relativePath);
        $contents = (string) file_get_contents($path);

        if (preg_match('/^#.*$/m', $contents) === 1) {
            $updated = preg_replace('/^#.*$/m', $expectedHeading, $contents, 1);
        } else {
            $updated = $expectedHeading . "\n\n" . ltrim($contents);
        }

        file_put_contents($path, (string) $updated);
    }

    private function appendMissingSection(string $relativePath, string $section): void
    {
        $path = $this->paths->join($relativePath);
        $contents = rtrim((string) file_get_contents($path));

        if (preg_match('/^## ' . preg_quote($section, '/') . '\s*$/m', $contents) === 1) {
            return;
        }

        $block = match ($section) {
            'Purpose', 'Constraints', 'Expected Behavior', 'Assumptions', 'Current State' => "\n\n## {$section}\n\nTBD.\n",
            'Goals', 'Non-Goals', 'Acceptance Criteria', 'Open Questions', 'Next Steps' => "\n\n## {$section}\n\n- TBD.\n",
            default => "\n\n## {$section}\n\nTBD.\n",
        };

        file_put_contents($path, $contents . $block);
    }

    private function repairDecisionTimestamps(string $relativePath, bool $onlyMissing): void
    {
        $path = $this->paths->join($relativePath);
        $contents = (string) file_get_contents($path);
        $entries = preg_split('/(?=^### Decision: )/m', $contents) ?: [];
        $updated = [];

        foreach ($entries as $entry) {
            if (trim($entry) === '') {
                continue;
            }

            if (preg_match('/^Timestamp:\s*(.+)$/m', $entry) === 1) {
                if ($onlyMissing) {
                    $updated[] = $entry;
                    continue;
                }

                $updated[] = (string) preg_replace('/^Timestamp:\s*(.+)$/m', 'Timestamp: <ISO-8601>', $entry, 1);
                continue;
            }

            $updated[] = (string) preg_replace(
                '/^(### Decision: .+)$/m',
                "$1\n\nTimestamp: <ISO-8601>",
                $entry,
                1,
            );
        }

        file_put_contents($path, implode("\n", array_map('rtrim', $updated)) . "\n");
    }

    private function repairDecisionSubsections(string $relativePath, string $section): void
    {
        $path = $this->paths->join($relativePath);
        $contents = (string) file_get_contents($path);
        $entries = preg_split('/(?=^### Decision: )/m', $contents) ?: [];
        $updated = [];

        foreach ($entries as $entry) {
            if (trim($entry) === '') {
                continue;
            }

            if (preg_match('/^\*\*' . preg_quote($section, '/') . '\*\*\s*$/m', $entry) !== 1) {
                $entry = rtrim($entry) . "\n\n**{$section}**\n\nTBD.\n";
            }

            $updated[] = rtrim($entry);
        }

        file_put_contents($path, implode("\n\n", $updated) . "\n");
    }

    /**
     * @param array{
     *     feature:string,
     *     mode:string,
     *     paths:array{spec:string,state:string,decisions:string,feature_base:string,manifest:string,prompts:string},
     *     spec:array<string,string>,
     *     state:array<string,string>,
     *     decisions:list<array<string,string>>,
     *     spec_tracking_items:list<string>,
     *     description:string,
     *     execution_summary:string
     * } $input
     */
    private function updatedStateDocument(array $input, bool $repairAttempted): string
    {
        $purpose = $this->existingOrFallback(
            $input['state']['Purpose'] ?? '',
            $input['spec']['Purpose'] ?? $input['description'],
        );

        $currentStateItems = $this->meaningfulSectionItems($input['state']['Current State'] ?? '');
        $nextStepItems = $this->meaningfulSectionItems($input['state']['Next Steps'] ?? '');
        $specItems = $input['spec_tracking_items'];

        if ($specItems !== []) {
            $currentStateItems[] = 'Implemented ' . rtrim($specItems[0], '. ') . '.';
            foreach (array_slice($specItems, 1) as $item) {
                $nextStepItems[] = rtrim($item, '. ') . '.';
            }
        } else {
            $currentStateItems[] = 'Implemented feature execution for ' . $input['feature'] . '.';
            $nextStepItems[] = 'Review the generated feature files and continue implementation against the canonical spec.';
        }

        if ($repairAttempted) {
            $nextStepItems[] = 'Confirm any repaired context files still match the canonical feature intent.';
        }

        $currentStateItems = $this->uniqueSortedPreservingOrder($currentStateItems);
        $nextStepItems = $this->uniqueSortedPreservingOrder($nextStepItems);
        $openQuestions = $this->meaningfulSectionItems($input['state']['Open Questions'] ?? '');

        return implode("\n", [
            '# Feature: ' . $input['feature'],
            '',
            '## Purpose',
            '',
            $this->paragraphBody($purpose),
            '',
            '## Current State',
            '',
            $this->bulletBody($currentStateItems),
            '',
            '## Open Questions',
            '',
            $this->bulletBody($openQuestions),
            '',
            '## Next Steps',
            '',
            $this->bulletBody($nextStepItems),
            '',
        ]);
    }

    /**
     * @param array{
     *     feature:string,
     *     mode:string,
     *     paths:array{spec:string,state:string,decisions:string,feature_base:string,manifest:string,prompts:string},
     *     spec:array<string,string>,
     *     state:array<string,string>,
     *     decisions:list<array<string,string>>,
     *     spec_tracking_items:list<string>,
     *     description:string,
     *     execution_summary:string
     * } $input
     */
    private function appendExecutionDecision(array $input, bool $repairAttempted): string
    {
        $path = $this->paths->join($input['paths']['decisions']);
        $existing = rtrim((string) file_get_contents($path));
        $entry = implode("\n", [
            '### Decision: context-driven execution for ' . $input['feature'],
            '',
            'Timestamp: <ISO-8601>',
            '',
            '**Context**',
            '',
            '- Foundry executed feature work for `' . $input['feature'] . '` from canonical context artifacts.',
            '',
            '**Decision**',
            '',
            '- Use the canonical spec, state, and decision ledger as the deterministic execution input.',
            '- Update feature context after execution and revalidate before finishing.',
            '',
            '**Reasoning**',
            '',
            '- This keeps feature execution traceable to the canonical context contract.',
            '- This preserves fail-closed behavior when repair is still required.',
            '',
            '**Alternatives Considered**',
            '',
            '- Execute from ad hoc prompts only.',
            '- Skip post-execution context updates.',
            $repairAttempted ? '- Block forever once context repair was required.' : '- Repair context only after implementation.',
            '',
            '**Impact**',
            '',
            '- Feature execution now leaves an explicit context trail.',
            '- Later runs can resume from updated state instead of relying on chat history.',
            '',
            '**Spec Reference**',
            '',
            '- Expected Behavior',
            '- Acceptance Criteria',
            '',
        ]);

        if ($existing === '') {
            return $entry;
        }

        return $existing . "\n\n" . $entry;
    }

    /**
     * @param array<string,string> $sections
     * @param list<string> $trackingItems
     */
    private function descriptionFromSpec(string $featureName, array $sections, array $trackingItems): string
    {
        $candidates = [
            $this->firstMeaningfulText($sections['Purpose'] ?? ''),
            $trackingItems[0] ?? null,
            $this->firstMeaningfulText($sections['Goals'] ?? ''),
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '' || $this->isPlaceholder($candidate)) {
                continue;
            }

            return rtrim($candidate, '. ') . '.';
        }

        return 'Implement ' . $featureName . '.';
    }

    /**
     * @param array<string,string> $sections
     * @param list<string> $trackingItems
     */
    private function executionSummary(string $featureName, array $sections, array $trackingItems): string
    {
        $summary = $this->descriptionFromSpec($featureName, $sections, $trackingItems);

        if ($trackingItems === []) {
            return $summary;
        }

        return $summary . ' Track: ' . implode(' | ', array_map(
            static fn(string $item): string => rtrim($item, '. ') . '.',
            array_slice($trackingItems, 0, 3),
        ));
    }

    /**
     * @param array<int,string> $sections
     * @return array<string,string>
     */
    private function parseSections(string $contents, array $sections): array
    {
        $parsed = [];

        foreach ($sections as $section) {
            $parsed[$section] = $this->sectionBody($contents, $section) ?? '';
        }

        return $parsed;
    }

    /**
     * @return list<array<string,string>>
     */
    private function parseDecisionEntries(string $contents): array
    {
        $entries = preg_split('/(?=^### Decision: )/m', $contents) ?: [];
        $parsed = [];

        foreach ($entries as $entry) {
            if (trim($entry) === '') {
                continue;
            }

            $parsed[] = [
                'title' => $this->matchLine($entry, '/^### Decision:\s*(.+)$/m'),
                'timestamp' => $this->matchLine($entry, '/^Timestamp:\s*(.+)$/m'),
                'context' => $this->sectionBody($entry, 'Context', 3) ?? '',
                'decision' => $this->sectionBody($entry, 'Decision', 3) ?? '',
                'reasoning' => $this->sectionBody($entry, 'Reasoning', 3) ?? '',
                'alternatives_considered' => $this->sectionBody($entry, 'Alternatives Considered', 3) ?? '',
                'impact' => $this->sectionBody($entry, 'Impact', 3) ?? '',
                'spec_reference' => $this->sectionBody($entry, 'Spec Reference', 3) ?? '',
            ];
        }

        return $parsed;
    }

    /**
     * @return list<string>
     */
    private function meaningfulSectionItems(string $body): array
    {
        $items = [];
        $paragraph = [];

        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                if ($paragraph !== []) {
                    $items[] = trim(implode(' ', $paragraph));
                    $paragraph = [];
                }

                continue;
            }

            if (preg_match('/^(?:[-*]|\d+\.)\s+(.+)$/', $trimmed, $matches) === 1) {
                if ($paragraph !== []) {
                    $items[] = trim(implode(' ', $paragraph));
                    $paragraph = [];
                }

                $items[] = trim($matches[1]);
                continue;
            }

            $paragraph[] = $trimmed;
        }

        if ($paragraph !== []) {
            $items[] = trim(implode(' ', $paragraph));
        }

        $items = array_values(array_filter(
            array_map(static fn(string $item): string => trim($item), $items),
            fn(string $item): bool => $item !== '' && !$this->isPlaceholder($item),
        ));

        return $items;
    }

    private function isPlaceholder(string $value): bool
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? $normalized;
        $normalized = trim($normalized);

        return in_array($normalized, ['tbd', 'none', 'title', 'iso 8601'], true);
    }

    private function firstMeaningfulText(string $body): ?string
    {
        foreach ($this->meaningfulSectionItems($body) as $item) {
            return $item;
        }

        return null;
    }

    private function existingOrFallback(string $existing, string $fallback): string
    {
        $candidate = trim($existing);
        if ($candidate === '' || $this->isPlaceholder($candidate)) {
            $candidate = trim($fallback);
        }

        return $candidate === '' ? 'TBD.' : rtrim($candidate);
    }

    /**
     * @param list<string> $items
     * @return list<string>
     */
    private function uniqueSortedPreservingOrder(array $items): array
    {
        $seen = [];
        $unique = [];

        foreach ($items as $item) {
            $normalized = trim($item);
            if ($normalized === '' || $this->isPlaceholder($normalized) || isset($seen[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;
            $unique[] = $normalized;
        }

        return $unique;
    }

    /**
     * @param list<string> $items
     */
    private function bulletBody(array $items): string
    {
        if ($items === []) {
            return '- TBD.';
        }

        return implode("\n", array_map(
            static fn(string $item): string => '- ' . rtrim($item),
            $items,
        ));
    }

    private function paragraphBody(string $body): string
    {
        $body = trim($body);

        return $body === '' ? 'TBD.' : $body;
    }

    private function updatedPrompts(string $promptsPath, string $feature, string $summary): string
    {
        $existing = is_file($promptsPath)
            ? (string) (file_get_contents($promptsPath) ?: '')
            : '# ' . Str::studly($feature) . "\n\n";

        $block = implode("\n", [
            '<!-- foundry:context-execution:start -->',
            'Latest context execution: ' . trim($summary),
            '<!-- foundry:context-execution:end -->',
        ]);

        $pattern = '/<!-- foundry:context-execution:start -->.*?<!-- foundry:context-execution:end -->/s';
        if (preg_match($pattern, $existing) === 1) {
            $updated = preg_replace($pattern, $block, $existing);

            return is_string($updated) ? $updated : $existing;
        }

        return rtrim($existing) . "\n\n" . $block . "\n";
    }

    /**
     * @param array<int,\Foundry\Context\Validation\ValidationIssue> $issues
     * @return list<array<string,mixed>>
     */
    private function validationIssuesToArray(array $issues): array
    {
        return array_values(array_map(
            function (\Foundry\Context\Validation\ValidationIssue $issue): array {
                $row = [
                    'code' => $issue->code,
                    'message' => $issue->message,
                    'file_path' => $this->relativePath($issue->file_path),
                ];

                if ($issue->section !== null) {
                    $row['section'] = $issue->section;
                }

                return $row;
            },
            $issues,
        ));
    }

    /**
     * @return array{issue:array<string,mixed>,required_actions:list<string>}|null
     */
    private function canonicalConflictForExecutionSpec(ExecutionSpec $executionSpec): ?array
    {
        $canonicalSpecPath = $this->paths->join($this->resolver->specPath($executionSpec->feature));
        if (!is_file($canonicalSpecPath)) {
            return null;
        }

        $contents = file_get_contents($canonicalSpecPath);
        if ($contents === false) {
            return null;
        }

        $sections = $this->parseSections($contents, ['Non-Goals', 'Constraints']);
        $negativeItems = array_values(array_merge(
            $this->meaningfulSectionItems($sections['Non-Goals'] ?? ''),
            array_values(array_filter(
                $this->meaningfulSectionItems($sections['Constraints'] ?? ''),
                fn(string $item): bool => $this->isNegativeRequirement($item),
            )),
        ));

        foreach ($executionSpec->instructionItems() as $item) {
            foreach ($negativeItems as $negativeItem) {
                if (!$this->itemsConflict($item, $negativeItem)) {
                    continue;
                }

                return [
                    'issue' => [
                        'code' => 'EXECUTION_SPEC_CONFLICTS_WITH_CANONICAL_SPEC',
                        'message' => 'Execution spec instructions conflict with the canonical feature spec.',
                        'file_path' => $executionSpec->path,
                    ],
                    'required_actions' => [
                        'Update the execution spec so it no longer conflicts with docs/features/' . $executionSpec->feature . '.spec.md.',
                        'If intended behavior changed, update the canonical feature spec and log a decision before rerunning implement spec.',
                    ],
                ];
            }
        }

        return null;
    }

    private function isNegativeRequirement(string $item): bool
    {
        $normalized = strtolower($item);

        return str_contains($normalized, 'do not')
            || str_contains($normalized, 'must not')
            || str_contains($normalized, 'never ')
            || str_contains($normalized, 'cannot ')
            || str_contains($normalized, "can't ");
    }

    private function itemsConflict(string $executionItem, string $canonicalItem): bool
    {
        $executionTokens = $this->significantTokens($executionItem);
        $canonicalTokens = $this->significantTokens($canonicalItem);
        $overlap = array_values(array_intersect($executionTokens, $canonicalTokens));

        return count($overlap) >= 3;
    }

    /**
     * @return list<string>
     */
    private function significantTokens(string $value): array
    {
        $normalized = strtolower($value);
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? $normalized;
        $parts = preg_split('/\s+/', trim($normalized)) ?: [];
        $stopWords = [
            'a',
            'an',
            'and',
            'as',
            'be',
            'by',
            'for',
            'from',
            'if',
            'in',
            'into',
            'is',
            'it',
            'may',
            'must',
            'not',
            'of',
            'on',
            'or',
            'the',
            'their',
            'then',
            'this',
            'to',
            'use',
            'when',
            'with',
        ];

        return array_values(array_unique(array_filter(
            array_map('strval', $parts),
            static fn(string $part): bool => $part !== '' && !in_array($part, $stopWords, true),
        )));
    }

    private function readFile(string $relativePath): string
    {
        $contents = file_get_contents($this->paths->join($relativePath));

        return $contents === false ? '' : $contents;
    }

    private function sectionBody(string $contents, string $section, int $headingLevel = 2): ?string
    {
        $marker = str_repeat('#', $headingLevel);
        $pattern = '/^' . preg_quote($marker . ' ' . $section, '/') . '\s*$\R(.*?)(?=^' . preg_quote($marker, '/') . ' |\z)/ms';
        if (preg_match($pattern, $contents, $matches) !== 1) {
            return null;
        }

        return rtrim($matches[1]);
    }

    private function matchLine(string $contents, string $pattern): string
    {
        if (preg_match($pattern, $contents, $matches) !== 1) {
            return '';
        }

        return trim((string) ($matches[1] ?? ''));
    }

    private function relativePath(string $path): string
    {
        $root = rtrim($this->paths->root(), '/') . '/';
        $normalizedPath = str_replace('\\', '/', $path);
        $normalizedRoot = str_replace('\\', '/', $root);

        if (str_starts_with($normalizedPath, $normalizedRoot)) {
            return substr($normalizedPath, strlen($normalizedRoot));
        }

        return $normalizedPath;
    }
}
