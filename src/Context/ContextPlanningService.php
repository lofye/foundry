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

    public function __construct(
        private readonly Paths $paths,
        private readonly FeatureNameValidator $featureNameValidator = new FeatureNameValidator(),
        private readonly ContextFileResolver $resolver = new ContextFileResolver(),
        private readonly ExecutionSpecPlanner $planner = new ExecutionSpecPlanner(),
        ?ContextInspectionService $inspectionService = null,
        ?ContextExecutionService $executionService = null,
    ) {
        $this->inspectionService = $inspectionService ?? new ContextInspectionService($paths);
        $this->executionService = $executionService ?? new ContextExecutionService($paths);
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

        $relativeDirectory = 'docs/specs/' . $featureName;
        $absoluteDirectory = $this->paths->join($relativeDirectory);
        if (file_exists($absoluteDirectory) && !is_dir($absoluteDirectory)) {
            throw new FoundryError(
                'PLANNING_SPEC_DIRECTORY_BLOCKED',
                'filesystem',
                ['path' => $relativeDirectory],
                'Execution spec directory path exists but is not a directory.',
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

        $sequence = $this->nextSequenceNumber($featureName);
        $filename = sprintf('%03d-%s.md', $sequence, $plan['slug']);
        $specId = $featureName . '/' . substr($filename, 0, -strlen('.md'));
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

        $contents = $this->planner->render($specId, $featureName, $plan);
        if (file_put_contents($absolutePath, $contents) === false) {
            throw new FoundryError(
                'PLANNING_SPEC_WRITE_FAILED',
                'filesystem',
                ['path' => $relativePath],
                'Unable to write execution spec.',
            );
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

    private function nextSequenceNumber(string $featureName): int
    {
        $matches = glob($this->paths->join('docs/specs/' . $featureName . '/*.md')) ?: [];
        $numbers = [];

        foreach ($matches as $path) {
            if (preg_match('/\/(\d{3})-[a-z0-9]+(?:-[a-z0-9]+)*\.md$/', $path, $pathMatches) !== 1) {
                continue;
            }

            $numbers[] = (int) $pathMatches[1];
        }

        sort($numbers);

        return $numbers === [] ? 1 : max($numbers) + 1;
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
