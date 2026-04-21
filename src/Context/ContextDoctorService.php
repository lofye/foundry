<?php

declare(strict_types=1);

namespace Foundry\Context;

use Foundry\Context\Validation\ValidationIssue;
use Foundry\Context\Validation\ValidationResult;
use Foundry\Support\FeatureNaming;
use Foundry\Support\Paths;

final class ContextDoctorService
{
    /**
     * @var array<string,int>
     */
    private const array STATUS_PRIORITY = [
        'ok' => 0,
        'warning' => 1,
        'repairable' => 2,
        'non_compliant' => 3,
    ];

    /**
     * @var list<ContextDoctorDiagnosticRule>
     */
    private readonly array $diagnosticRules;

    /**
     * @param list<ContextDoctorDiagnosticRule>|null $diagnosticRules
     */
    public function __construct(
        private readonly Paths $paths,
        private readonly FeatureNameValidator $featureNameValidator = new FeatureNameValidator(),
        private readonly ContextFileResolver $resolver = new ContextFileResolver(),
        private readonly SpecValidator $specValidator = new SpecValidator(),
        private readonly StateValidator $stateValidator = new StateValidator(),
        private readonly DecisionLedgerValidator $decisionLedgerValidator = new DecisionLedgerValidator(),
        ?array $diagnosticRules = null,
        private readonly ContextDiagnosticOutputCoalescer $outputCoalescer = new ContextDiagnosticOutputCoalescer(),
    ) {
        $this->diagnosticRules = $diagnosticRules ?? [
            new ExecutionSpecDriftContextDoctorRule(),
            new StaleCompletedItemsInNextStepsContextDoctorRule(),
            new DecisionMissingForStateDivergenceContextDoctorRule(),
        ];
    }

    /**
     * @return array{status:string,feature:string,can_proceed:bool,requires_repair:bool,files:array<string,array<string,mixed>>,required_actions:list<string>}
     */
    public function checkFeature(string $featureName): array
    {
        $featureName = FeatureNaming::canonical($featureName);
        $relativePaths = $this->resolver->paths($featureName);
        $nameValidation = $this->featureNameValidator->validate($featureName);

        if (!$nameValidation->valid) {
            $readiness = ContextExecutionReadiness::fromDoctorStatus('non_compliant');

            return [
                'status' => 'non_compliant',
                'feature' => $featureName,
                'can_proceed' => $readiness['can_proceed'],
                'requires_repair' => $readiness['requires_repair'],
                'files' => $this->emptyFiles($relativePaths),
                'required_actions' => $this->requiredActionsForFeatureName($nameValidation),
            ];
        }

        $spec = $this->specValidator->validate($featureName, $this->paths->join($relativePaths['spec']));
        $state = $this->stateValidator->validate($featureName, $this->paths->join($relativePaths['state']));
        $decisions = $this->decisionLedgerValidator->validate($featureName, $this->paths->join($relativePaths['decisions']));

        $files = [
            'spec' => $this->filePayload($relativePaths['spec'], $spec, true),
            'state' => $this->filePayload($relativePaths['state'], $state, true),
            'decisions' => $this->filePayload($relativePaths['decisions'], $decisions, false),
        ];
        $baseRequiredActions = $this->requiredActionsForFiles($files);
        $diagnosticResults = $this->evaluateDiagnosticRules(new ContextDoctorDiagnosticRuleContext(
            feature: $featureName,
            files: $files,
            featureHasExecutionSpecs: $this->featureHasExecutionSpecs($featureName),
            contents: [
                'spec' => $this->readContextContents($relativePaths['spec']),
                'state' => $this->readContextContents($relativePaths['state']),
                'decisions' => $this->readContextContents($relativePaths['decisions']),
            ],
        ));
        $files = $this->applyDiagnosticResults($files, $diagnosticResults);

        $status = $this->statusForResults([$spec, $state, $decisions], $diagnosticResults);
        $readiness = ContextExecutionReadiness::fromDoctorStatus($status);

        return [
            'status' => $status,
            'feature' => $featureName,
            'can_proceed' => $readiness['can_proceed'],
            'requires_repair' => $readiness['requires_repair'],
            'files' => $files,
            'required_actions' => $this->mergeRequiredActions(
                $baseRequiredActions,
                $this->requiredActionsForDiagnosticResults($diagnosticResults),
            ),
        ];
    }

    /**
     * @return array{status:string,can_proceed:bool,requires_repair:bool,summary:array{ok:int,warning:int,repairable:int,non_compliant:int,total:int},features:list<array<string,mixed>>,required_actions:list<string>}
     */
    public function checkAll(): array
    {
        $features = [];
        foreach ($this->discoverFeatures() as $featureName) {
            $features[] = $this->checkFeature($featureName);
        }

        $summary = [
            'ok' => 0,
            'warning' => 0,
            'repairable' => 0,
            'non_compliant' => 0,
            'total' => 0,
        ];
        $status = 'ok';
        $requiredActions = [];

        foreach ($features as $feature) {
            $featureStatus = (string) ($feature['status'] ?? 'ok');
            $summary[$featureStatus] = ($summary[$featureStatus] ?? 0) + 1;
            $summary['total']++;
            $status = $this->moreSevereStatus($status, $featureStatus);

            foreach ((array) ($feature['required_actions'] ?? []) as $action) {
                $requiredActions[] = (string) ($feature['feature'] ?? '') . ': ' . (string) $action;
            }
        }

        $readiness = ContextExecutionReadiness::fromDoctorStatus($status);

        return [
            'status' => $status,
            'can_proceed' => $readiness['can_proceed'],
            'requires_repair' => $readiness['requires_repair'],
            'summary' => $summary,
            'features' => $features,
            'required_actions' => array_values(array_unique($requiredActions)),
        ];
    }

    /**
     * @return list<string>
     */
    private function discoverFeatures(): array
    {
        $features = array_values(array_unique(array_merge(
            $this->discoverContextFeatures(),
            $this->discoverExecutionSpecFeatures(),
        )));

        sort($features);

        return $features;
    }

    /**
     * @return list<string>
     */
    private function discoverContextFeatures(): array
    {
        $directory = $this->paths->join('docs/features');
        if (!is_dir($directory)) {
            return [];
        }

        $items = scandir($directory);
        if ($items === false) {
            return [];
        }

        $features = [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;
            if (!is_file($path)) {
                continue;
            }

            $featureName = $this->featureNameFromCanonicalFilename($item);
            if ($featureName === null) {
                continue;
            }

            $features[] = $featureName;
        }

        return $features;
    }

    /**
     * @return list<string>
     */
    private function discoverExecutionSpecFeatures(): array
    {
        $directory = $this->paths->join('docs/specs');
        if (!is_dir($directory)) {
            return [];
        }

        $items = scandir($directory);
        if ($items === false) {
            return [];
        }

        $features = [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;
            if (!is_dir($path) || !$this->isCanonicalFeatureDirectory($item)) {
                continue;
            }

            if (!$this->featureHasExecutionSpecs($item)) {
                continue;
            }

            $features[] = $item;
        }

        return $features;
    }

    private function featureNameFromCanonicalFilename(string $filename): ?string
    {
        $featureName = null;
        if (str_ends_with($filename, '.spec.md')) {
            $featureName = substr($filename, 0, -strlen('.spec.md'));
        } elseif (str_ends_with($filename, '.decisions.md')) {
            $featureName = substr($filename, 0, -strlen('.decisions.md'));
        } elseif (str_ends_with($filename, '.md')) {
            $featureName = substr($filename, 0, -strlen('.md'));
        }

        if ($featureName === null || $featureName === '') {
            return null;
        }

        if (str_contains($featureName, '.')) {
            return null;
        }

        return $featureName;
    }

    /**
     * @param array{spec:string,state:string,decisions:string} $relativePaths
     * @return array<string,array<string,mixed>>
     */
    private function emptyFiles(array $relativePaths): array
    {
        return [
            'spec' => [
                'path' => $relativePaths['spec'],
                'exists' => is_file($this->paths->join($relativePaths['spec'])),
                'valid' => false,
                'missing_sections' => [],
                'issues' => [],
            ],
            'state' => [
                'path' => $relativePaths['state'],
                'exists' => is_file($this->paths->join($relativePaths['state'])),
                'valid' => false,
                'missing_sections' => [],
                'issues' => [],
            ],
            'decisions' => [
                'path' => $relativePaths['decisions'],
                'exists' => is_file($this->paths->join($relativePaths['decisions'])),
                'valid' => false,
                'issues' => [],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function filePayload(string $relativePath, ValidationResult $result, bool $includeMissingSections): array
    {
        $payload = [
            'path' => $relativePath,
            'exists' => $result->file_exists,
            'valid' => $result->valid,
        ];

        if ($includeMissingSections) {
            $payload['missing_sections'] = $result->missing_sections;
        }

        $payload['issues'] = $this->issuesToArray($result->issues);

        return $payload;
    }

    /**
     * @param array<int,ValidationResult> $results
     * @param list<ContextDoctorDiagnosticRuleResult> $diagnosticResults
     */
    private function statusForResults(array $results, array $diagnosticResults = []): string
    {
        foreach ($results as $result) {
            foreach ($result->issues as $issue) {
                if (in_array($issue->code, ['CONTEXT_SPEC_PATH_NON_CANONICAL', 'CONTEXT_STATE_PATH_NON_CANONICAL', 'CONTEXT_DECISIONS_PATH_NON_CANONICAL'], true)) {
                    return 'non_compliant';
                }
            }

            if (!$result->valid) {
                return 'repairable';
            }
        }

        foreach ($diagnosticResults as $result) {
            if ($result->requiresRepair) {
                return 'repairable';
            }
        }

        return 'ok';
    }

    /**
     * @return list<string>
     */
    private function requiredActionsForFeatureName(ValidationResult $result): array
    {
        if ($result->valid) {
            return [];
        }

        return ['Use a lowercase kebab-case feature name.'];
    }

    /**
     * @param array<string,array<string,mixed>> $files
     * @return list<string>
     */
    private function requiredActionsForFiles(array $files): array
    {
        $actions = [];

        foreach (['spec', 'state', 'decisions'] as $kind) {
            $file = (array) ($files[$kind] ?? []);
            $path = (string) ($file['path'] ?? '');
            foreach ((array) ($file['issues'] ?? []) as $issue) {
                if (!is_array($issue)) {
                    continue;
                }

                $action = $this->requiredActionForIssue($kind, $path, $issue);
                if ($action === null) {
                    continue;
                }

                $actions[] = $action;
            }
        }

        return $this->outputCoalescer->coalesceRequiredActions($actions);
    }

    /**
     * @param array<string,mixed> $issue
     */
    private function requiredActionForIssue(string $kind, string $path, array $issue): ?string
    {
        $code = (string) ($issue['code'] ?? '');
        $section = (string) ($issue['section'] ?? '');

        return match ($code) {
            'CONTEXT_FILE_MISSING' => match ($kind) {
                'spec' => 'Create missing spec file: ' . $path,
                'state' => 'Create missing state file: ' . $path,
                'decisions' => 'Create missing decision ledger: ' . $path,
                default => null,
            },
            'CONTEXT_SPEC_HEADING_INVALID' => 'Fix malformed spec heading in ' . $path . '.',
            'CONTEXT_STATE_HEADING_INVALID' => 'Fix malformed state heading in ' . $path . '.',
            'CONTEXT_SPEC_SECTION_MISSING' => 'Add missing required section "## ' . $section . '" to ' . $path . '.',
            'CONTEXT_STATE_SECTION_MISSING' => 'Add missing required section "## ' . $section . '" to ' . $path . '.',
            'CONTEXT_DECISION_ENTRY_MALFORMED' => 'Fix malformed decision entry in ' . $path . '.',
            'CONTEXT_DECISION_TIMESTAMP_MISSING' => 'Add missing decision timestamp line to ' . $path . '.',
            'CONTEXT_DECISION_TIMESTAMP_INVALID' => 'Fix decision timestamp to ISO-8601 in ' . $path . '.',
            'CONTEXT_DECISION_SUBSECTION_MISSING' => 'Add missing required decision subsection "**' . $section . '**" to ' . $path . '.',
            default => null,
        };
    }

    /**
     * @param array<int,ValidationIssue> $issues
     * @return list<array<string,mixed>>
     */
    private function issuesToArray(array $issues): array
    {
        return array_values(array_map(
            function (ValidationIssue $issue): array {
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

    private function readContextContents(string $relativePath): string
    {
        $path = $this->paths->join($relativePath);
        if (!is_file($path)) {
            return '';
        }

        $contents = file_get_contents($path);

        return is_string($contents) ? $contents : '';
    }

    private function moreSevereStatus(string $current, string $candidate): string
    {
        $currentPriority = self::STATUS_PRIORITY[$current] ?? 0;
        $candidatePriority = self::STATUS_PRIORITY[$candidate] ?? 0;

        return $candidatePriority > $currentPriority ? $candidate : $current;
    }

    /**
     * @param list<ContextDoctorDiagnosticRuleResult> $diagnosticResults
     * @return list<ContextDoctorDiagnosticRuleResult>
     */
    private function evaluateDiagnosticRules(ContextDoctorDiagnosticRuleContext $context): array
    {
        $results = [];

        foreach ($this->diagnosticRules as $rule) {
            $result = $rule->evaluate($context);
            if ($result === null) {
                continue;
            }

            $results[] = $result;
        }

        usort($results, function (ContextDoctorDiagnosticRuleResult $left, ContextDoctorDiagnosticRuleResult $right): int {
            $leftTarget = $this->diagnosticTargetSortKey($left->targets[0] ?? null);
            $rightTarget = $this->diagnosticTargetSortKey($right->targets[0] ?? null);

            return [$leftTarget, $left->code, $left->message] <=> [$rightTarget, $right->code, $right->message];
        });

        return $this->outputCoalescer->coalesceRuleResults(array_values($results));
    }

    /**
     * @param array<string,array<string,mixed>> $files
     * @param list<ContextDoctorDiagnosticRuleResult> $diagnosticResults
     * @return array<string,array<string,mixed>>
     */
    private function applyDiagnosticResults(array $files, array $diagnosticResults): array
    {
        foreach ($diagnosticResults as $result) {
            foreach ($result->targets as $target) {
                $file = (array) ($files[$target->bucket] ?? []);
                $issues = array_values((array) ($file['issues'] ?? []));
                $issues[] = [
                    'code' => $result->code,
                    'message' => $result->message,
                    'file_path' => $target->filePath,
                ];
                $file['issues'] = $issues;
                $files[$target->bucket] = $file;
            }
        }

        foreach (['spec', 'state', 'decisions'] as $bucket) {
            $file = (array) ($files[$bucket] ?? []);
            $issues = array_values((array) ($file['issues'] ?? []));
            usort($issues, function (array $left, array $right): int {
                return [
                    (string) ($left['code'] ?? ''),
                    (string) ($left['message'] ?? ''),
                    (string) ($left['file_path'] ?? ''),
                    (string) ($left['section'] ?? ''),
                ] <=> [
                    (string) ($right['code'] ?? ''),
                    (string) ($right['message'] ?? ''),
                    (string) ($right['file_path'] ?? ''),
                    (string) ($right['section'] ?? ''),
                ];
            });
            $file['issues'] = $this->outputCoalescer->coalesceIssueRows($issues);
            $files[$bucket] = $file;
        }

        return $files;
    }

    /**
     * @param list<ContextDoctorDiagnosticRuleResult> $diagnosticResults
     * @return list<string>
     */
    private function requiredActionsForDiagnosticResults(array $diagnosticResults): array
    {
        $actions = [];

        foreach ($diagnosticResults as $result) {
            foreach ($result->requiredActions as $action) {
                $actions[] = $action;
            }
        }

        return $this->outputCoalescer->coalesceRequiredActions($actions);
    }

    /**
     * @param list<string> $base
     * @param list<string> $extra
     * @return list<string>
     */
    private function mergeRequiredActions(array $base, array $extra): array
    {
        return $this->outputCoalescer->coalesceRequiredActions(array_merge($base, $extra));
    }

    private function featureHasExecutionSpecs(string $featureName): bool
    {
        $featureName = FeatureNaming::canonical($featureName);

        return $this->directoryContainsMarkdownFiles('docs/specs/' . $featureName)
            || $this->directoryContainsMarkdownFiles('docs/specs/' . $featureName . '/drafts');
    }

    private function directoryContainsMarkdownFiles(string $relativePath): bool
    {
        $directory = $this->paths->join($relativePath);
        if (!is_dir($directory)) {
            return false;
        }

        $items = scandir($directory);
        if ($items === false) {
            return false;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            if (!str_ends_with($item, '.md')) {
                continue;
            }

            if (is_file($directory . '/' . $item)) {
                return true;
            }
        }

        return false;
    }

    private function isCanonicalFeatureDirectory(string $name): bool
    {
        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $name) === 1;
    }

    /**
     * @param array<string,mixed> $doctor
     * @return list<array<string,mixed>>
     */
    public function flattenIssues(array $doctor): array
    {
        $issues = [];

        foreach (['spec', 'state', 'decisions'] as $kind) {
            $file = (array) (($doctor['files'] ?? [])[$kind] ?? []);
            foreach ((array) ($file['issues'] ?? []) as $issue) {
                if (!is_array($issue)) {
                    continue;
                }

                $row = [
                    'source' => 'doctor',
                    'code' => (string) ($issue['code'] ?? ''),
                    'message' => (string) ($issue['message'] ?? ''),
                    'file_path' => (string) ($issue['file_path'] ?? ($file['path'] ?? '')),
                ];

                if (array_key_exists('section', $issue)) {
                    $row['section'] = $issue['section'];
                }

                $issues[] = $row;
            }
        }

        return $this->outputCoalescer->coalesceIssueRows($issues);
    }

    private function diagnosticTargetSortKey(?ContextDoctorDiagnosticTarget $target): string
    {
        if ($target === null) {
            return '9:';
        }

        $bucketOrder = match ($target->bucket) {
            'spec' => '0',
            'state' => '1',
            'decisions' => '2',
            default => '9',
        };

        return $bucketOrder . ':' . $target->filePath;
    }
}
