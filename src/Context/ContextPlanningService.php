<?php

declare(strict_types=1);

namespace Foundry\Context;

use Foundry\Context\Validation\ValidationIssue;
use Foundry\Support\FeatureNaming;
use Foundry\Support\FoundryError;
use Foundry\Support\Paths;

final class ContextPlanningService
{
    private readonly ContextInspectionService $inspectionService;
    private readonly ContextExecutionService $executionService;
    private readonly ExecutionSpecCatalog $executionSpecCatalog;

    public function __construct(
        private readonly Paths $paths,
        private readonly FeatureNameValidator $featureNameValidator = new FeatureNameValidator(),
        private readonly ContextFileResolver $resolver = new ContextFileResolver(),
        private readonly ExecutionSpecPlanner $planner = new ExecutionSpecPlanner(),
        ?ExecutionSpecCatalog $executionSpecCatalog = null,
        ?ContextInspectionService $inspectionService = null,
        ?ContextExecutionService $executionService = null,
    ) {
        $this->inspectionService = $inspectionService ?? new ContextInspectionService($paths);
        $this->executionService = $executionService ?? new ContextExecutionService($paths);
        $this->executionSpecCatalog = $executionSpecCatalog ?? new ExecutionSpecCatalog($paths);
    }

    public function plan(string $featureName): PlanResult
    {
        $featureName = FeatureNaming::canonical($featureName);

        $nameValidation = $this->featureNameValidator->validate($featureName);
        if (!$nameValidation->valid) {
            return new PlanResult(
                feature: $featureName,
                status: 'blocked',
                canProceed: false,
                requiresRepair: true,
                specId: null,
                specPath: null,
                actionsTaken: [],
                issues: $this->validationIssuesToArray($nameValidation->issues),
                requiredActions: ['Use a lowercase kebab-case feature name.'],
            );
        }

        $inspection = $this->inspectionService->inspectFeature($featureName);
        $verification = $this->inspectionService->verifyFeature($featureName);
        if (!(bool) ($inspection['can_proceed'] ?? false)) {
            return new PlanResult(
                feature: $featureName,
                status: 'blocked',
                canProceed: false,
                requiresRepair: true,
                specId: null,
                specPath: null,
                actionsTaken: [],
                issues: array_values((array) ($verification['issues'] ?? [])),
                requiredActions: array_values(array_map('strval', (array) ($inspection['required_actions'] ?? []))),
            );
        }

        $executionInput = $this->normalizeExecutionInput(
            $this->executionService->buildExecutionInput($featureName),
        );
        $plan = $this->planner->plan($featureName, $executionInput);

        if ($plan === null) {
            return new PlanResult(
                feature: $featureName,
                status: 'blocked',
                canProceed: false,
                requiresRepair: true,
                specId: null,
                specPath: null,
                actionsTaken: [],
                issues: [[
                    'code' => 'PLANNING_NO_BOUNDED_STEP',
                    'message' => 'No meaningful bounded work step could be derived from the gap between Expected Behavior and Current State.',
                    'file_path' => $this->resolver->statePath($featureName),
                ]],
                requiredActions: [
                    'Update docs/features/' . $featureName . '.spec.md or docs/features/' . $featureName . '.md so there is a concrete actionable gap between Expected Behavior and Current State.',
                ],
            );
        }

        $relativeDirectory = 'docs/specs/' . $featureName . '/drafts';
        $absoluteDirectory = $this->paths->join($relativeDirectory);
        if (file_exists($absoluteDirectory) && !is_dir($absoluteDirectory)) {
            throw new FoundryError(
                'PLANNING_SPEC_DIRECTORY_BLOCKED',
                'filesystem',
                ['path' => $relativeDirectory],
                'Draft execution spec directory path exists but is not a directory.',
            );
        }

        if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0777, true) && !is_dir($absoluteDirectory)) {
            throw new FoundryError(
                'PLANNING_SPEC_DIRECTORY_CREATE_FAILED',
                'filesystem',
                ['path' => $relativeDirectory],
                'Unable to create execution spec directory.',
            );
        }

        $rootId = $this->executionSpecCatalog->nextRootId($featureName);
        $name = $rootId . '-' . $plan['slug'];
        $filename = $name . '.md';
        $specId = $featureName . '/' . $name;
        $relativePath = $relativeDirectory . '/' . $filename;
        $absolutePath = $this->paths->join($relativePath);

        if (file_exists($absolutePath)) {
            return new PlanResult(
                feature: $featureName,
                status: 'blocked',
                canProceed: false,
                requiresRepair: true,
                specId: $specId,
                specPath: $relativePath,
                actionsTaken: [],
                issues: [[
                    'code' => 'PLANNING_SPEC_PATH_EXISTS',
                    'message' => 'Planned execution spec path already exists.',
                    'file_path' => $relativePath,
                ]],
                requiredActions: [
                    'Resolve the existing execution spec path before rerunning plan feature.',
                ],
            );
        }

        $beforeWritePaths = $this->specMarkdownPaths($featureName);
        $contents = $this->renderExecutionSpec($specId, $name, $featureName, $plan);
        if (file_put_contents($absolutePath, $contents) === false) {
            throw new FoundryError(
                'PLANNING_SPEC_WRITE_FAILED',
                'filesystem',
                ['path' => $relativePath],
                'Unable to write execution spec.',
            );
        }

        $postWriteVerification = $this->verifySingleDraftWrite($featureName, $specId, $relativePath, $beforeWritePaths);
        if ($postWriteVerification !== null) {
            return $postWriteVerification;
        }

        return new PlanResult(
            feature: $featureName,
            status: 'planned',
            canProceed: true,
            requiresRepair: false,
            specId: $specId,
            specPath: $relativePath,
            actionsTaken: ['generated execution spec'],
            issues: [],
            requiredActions: [],
        );
    }

    /**
     * @param array{
     *     slug:string,
     *     purpose:string,
     *     scope:list<string>,
     *     constraints:list<string>,
     *     requested_changes:list<string>,
     *     non_goals:list<string>,
     *     completion_signals:list<string>,
     *     post_execution_expectations:list<string>
     * } $plan
     */
    private function renderExecutionSpec(string $specId, string $specName, string $featureName, array $plan): string
    {
        $contents = str_replace(
            [
                '{{spec_id}}',
                '{{spec_name}}',
                '{{feature}}',
                '{{purpose}}',
                '{{scope}}',
                '{{constraints}}',
                '{{requested_changes}}',
                '{{non_goals}}',
                '{{completion_signals}}',
                '{{post_execution_expectations}}',
            ],
            [
                $specId,
                $specName,
                $featureName,
                $plan['purpose'],
                $this->renderBulletList($plan['scope']),
                $this->renderBulletList($plan['constraints']),
                $this->renderBulletList($plan['requested_changes']),
                $this->renderBulletList($plan['non_goals']),
                $this->renderBulletList($plan['completion_signals']),
                $this->renderBulletList($plan['post_execution_expectations']),
            ],
            $this->loadExecutionSpecStub(),
        );

        if ($this->firstLine($contents) !== ExecutionSpecFilename::heading($specName)) {
            throw new FoundryError(
                'PLANNING_SPEC_STUB_INVALID',
                'validation',
                ['spec_name' => $specName],
                'Execution spec stub must render a canonical filename-only heading.',
            );
        }

        return $contents;
    }

    private function loadExecutionSpecStub(): string
    {
        $relativePath = 'stubs/specs/execution-spec.stub.md';
        $stubPath = $this->paths->frameworkJoin($relativePath);

        if (!is_file($stubPath) || !is_readable($stubPath)) {
            throw new FoundryError(
                'PLANNING_SPEC_STUB_MISSING',
                'filesystem',
                ['path' => $relativePath],
                'Execution spec stub could not be read.',
            );
        }

        $contents = file_get_contents($stubPath);

        if ($contents === false) {
            throw new FoundryError(
                'PLANNING_SPEC_STUB_MISSING',
                'filesystem',
                ['path' => $relativePath],
                'Execution spec stub could not be read.',
            );
        }

        return $contents;
    }

    /**
     * @param list<string> $items
     */
    private function renderBulletList(array $items): string
    {
        return implode("\n", array_map(
            static fn(string $item): string => '- ' . $item,
            $items,
        ));
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
     * } $executionInput
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
    private function normalizeExecutionInput(array $executionInput): array
    {
        $spec = $executionInput['spec'];
        ksort($spec);

        $state = $executionInput['state'];
        ksort($state);

        $executionInput['spec'] = $spec;
        $executionInput['state'] = $state;
        $executionInput['decisions'] = $this->stableDecisionEntries($executionInput['decisions']);

        return $executionInput;
    }

    /**
     * @param list<array<string,string>> $decisions
     * @return list<array<string,string>>
     */
    private function stableDecisionEntries(array $decisions): array
    {
        usort($decisions, function (array $left, array $right): int {
            return strcmp($this->decisionSortKey($left), $this->decisionSortKey($right));
        });

        return $decisions;
    }

    /**
     * @param array<string,string> $decision
     */
    private function decisionSortKey(array $decision): string
    {
        return implode("\n", [
            strtolower((string) ($decision['title'] ?? '')),
            strtolower((string) ($decision['timestamp'] ?? '')),
            strtolower((string) ($decision['context'] ?? '')),
            strtolower((string) ($decision['decision'] ?? '')),
            strtolower((string) ($decision['reasoning'] ?? '')),
            strtolower((string) ($decision['alternatives_considered'] ?? '')),
            strtolower((string) ($decision['impact'] ?? '')),
            strtolower((string) ($decision['spec_reference'] ?? '')),
        ]);
    }

    private function firstLine(string $contents): string
    {
        $firstLine = strtok(str_replace("\r\n", "\n", $contents), "\n");

        return $firstLine === false ? '' : trim($firstLine);
    }

    /**
     * @param list<string> $beforeWritePaths
     */
    private function verifySingleDraftWrite(string $featureName, string $specId, string $relativePath, array $beforeWritePaths): ?PlanResult
    {
        clearstatcache();

        $afterWritePaths = $this->specMarkdownPaths($featureName);
        $createdPaths = array_values(array_diff($afterWritePaths, $beforeWritePaths));
        sort($createdPaths);

        $absolutePath = $this->paths->join($relativePath);
        if (is_file($absolutePath) && $createdPaths === [$relativePath]) {
            return null;
        }

        $message = !is_file($absolutePath)
            ? 'Planner did not leave the generated draft execution spec at the reported path.'
            : 'Planner must create exactly one visible draft execution spec file per invocation.';

        return new PlanResult(
            feature: $featureName,
            status: 'blocked',
            canProceed: false,
            requiresRepair: true,
            specId: $specId,
            specPath: $relativePath,
            actionsTaken: [],
            issues: [[
                'code' => 'PLANNING_SPEC_WRITE_CONTRACT_FAILED',
                'message' => $message,
                'file_path' => $relativePath,
                'created_paths' => $createdPaths,
            ]],
            requiredActions: [
                'Inspect docs/specs/' . $featureName . '/ and docs/specs/' . $featureName . '/drafts/ so one planner invocation creates exactly one draft execution spec file.',
            ],
        );
    }

    /**
     * @return list<string>
     */
    private function specMarkdownPaths(string $featureName): array
    {
        $relativePaths = [];

        foreach ([
            'docs/specs/' . $featureName,
            'docs/specs/' . $featureName . '/drafts',
        ] as $directory) {
            $absoluteDirectory = $this->paths->join($directory);
            if (!is_dir($absoluteDirectory)) {
                continue;
            }

            foreach (glob($absoluteDirectory . '/*.md') ?: [] as $match) {
                if (!is_file($match)) {
                    continue;
                }

                $relativePath = $this->relativePath($match);
                if ($relativePath === null) {
                    continue;
                }

                $relativePaths[] = $relativePath;
            }
        }

        sort($relativePaths);

        return array_values(array_unique($relativePaths));
    }

    private function relativePath(string $absolutePath): ?string
    {
        $root = rtrim($this->paths->root(), '/');
        if (!str_starts_with($absolutePath, $root . '/')) {
            return null;
        }

        return substr($absolutePath, strlen($root) + 1);
    }

    /**
     * @param array<int,ValidationIssue> $issues
     * @return list<array<string,mixed>>
     */
    private function validationIssuesToArray(array $issues): array
    {
        return array_values(array_map(
            static function (ValidationIssue $issue): array {
                $row = [
                    'code' => $issue->code,
                    'message' => $issue->message,
                    'file_path' => $issue->file_path,
                ];

                if ($issue->section !== null) {
                    $row['section'] = $issue->section;
                }

                return $row;
            },
            $issues,
        ));
    }
}
