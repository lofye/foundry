<?php

declare(strict_types=1);

namespace Foundry\Context;

use Foundry\Support\FeatureNaming;
use Foundry\Support\FoundryError;
use Foundry\Support\Paths;

final class ContextInspectionService
{
    private readonly ContextDoctorService $doctorService;

    public function __construct(
        private readonly Paths $paths,
        ?ContextDoctorService $doctorService = null,
        private readonly ContextFileResolver $resolver = new ContextFileResolver(),
        private readonly AlignmentChecker $alignmentChecker = new AlignmentChecker(),
        private readonly ContextDiagnosticOutputCoalescer $outputCoalescer = new ContextDiagnosticOutputCoalescer(),
    ) {
        $this->doctorService = $doctorService ?? new ContextDoctorService($paths);
    }

    /**
     * @return array{
     *     feature:string,
     *     can_proceed:bool,
     *     requires_repair:bool,
     *     doctor:array<string,mixed>,
     *     alignment:array<string,mixed>,
     *     summary:array{doctor_status:string,alignment_status:string},
     *     required_actions:list<string>
     * }
     */
    public function inspectFeature(string $featureName): array
    {
        $featureName = FeatureNaming::canonical($featureName);
        $doctor = $this->doctorService->checkFeature($featureName);
        $alignment = $this->alignmentForFeature($featureName, $doctor);
        $readiness = ContextExecutionReadiness::fromDoctorAndAlignment(
            (string) ($doctor['status'] ?? 'non_compliant'),
            (string) ($alignment['status'] ?? 'mismatch'),
        );

        return [
            'feature' => $featureName,
            'can_proceed' => $readiness['can_proceed'],
            'requires_repair' => $readiness['requires_repair'],
            'doctor' => $doctor,
            'alignment' => $alignment,
            'summary' => [
                'doctor_status' => (string) ($doctor['status'] ?? 'non_compliant'),
                'alignment_status' => (string) ($alignment['status'] ?? 'mismatch'),
            ],
            'required_actions' => $this->requiredActionsFromInspection($doctor, $alignment),
        ];
    }

    /**
     * @param array<string,mixed>|null $doctor
     * @return array{status:string,feature:string,can_proceed:bool,requires_repair:bool,issues:list<array<string,mixed>>,required_actions:list<string>}
     */
    public function alignmentForFeature(string $featureName, ?array $doctor = null): array
    {
        $featureName = FeatureNaming::canonical($featureName);
        $doctor ??= $this->doctorService->checkFeature($featureName);
        $doctorStatus = (string) ($doctor['status'] ?? 'repairable');

        if (!in_array($doctorStatus, ['ok', 'warning'], true)) {
            return $this->preflightFailurePayload($featureName, $doctor);
        }

        return $this->alignmentChecker->check(
            $this->readFile($this->preferredContextPaths($featureName)['spec']),
            $this->readFile($this->preferredContextPaths($featureName)['state']),
            $this->readFile($this->preferredContextPaths($featureName)['decisions']),
        )->toArray($featureName);
    }

    /**
     * @return array{
     *     feature:string,
     *     status:string,
     *     can_proceed:bool,
     *     requires_repair:bool,
     *     consumable:bool,
     *     doctor_status:string,
     *     alignment_status:string,
     *     issues:list<array<string,mixed>>,
     *     required_actions:list<string>
     * }
     */
    public function verifyFeature(string $featureName): array
    {
        $featureName = FeatureNaming::canonical($featureName);

        return $this->verificationPayload($this->inspectFeature($featureName));
    }

    /**
     * @return array{
     *     status:string,
     *     can_proceed:bool,
     *     requires_repair:bool,
     *     summary:array{pass:int,fail:int,total:int},
     *     features:list<array<string,mixed>>,
     *     required_actions:list<string>
     * }
     */
    public function verifyAll(): array
    {
        $doctorAll = $this->doctorService->checkAll();
        $features = [];
        $summary = [
            'pass' => 0,
            'fail' => 0,
            'total' => 0,
        ];
        $status = 'pass';
        $requiredActions = [];

        foreach ((array) ($doctorAll['features'] ?? []) as $doctorFeature) {
            if (!is_array($doctorFeature)) {
                continue;
            }

            $inspection = [
                'feature' => (string) ($doctorFeature['feature'] ?? ''),
                'doctor' => $doctorFeature,
                'alignment' => $this->alignmentForFeature(
                    (string) ($doctorFeature['feature'] ?? ''),
                    $doctorFeature,
                ),
                'summary' => [
                    'doctor_status' => (string) ($doctorFeature['status'] ?? 'non_compliant'),
                    'alignment_status' => '',
                ],
            ];
            $inspection['summary']['alignment_status'] = (string) ($inspection['alignment']['status'] ?? 'mismatch');

            $payload = $this->verificationPayload($inspection);
            $features[] = $payload;
            $summary[$payload['status']]++;
            $summary['total']++;
            foreach ((array) ($payload['required_actions'] ?? []) as $action) {
                $requiredActions[] = (string) ($payload['feature'] ?? '') . ': ' . (string) $action;
            }

            if ($payload['status'] === 'fail') {
                $status = 'fail';
            }
        }

        $allConsumable = array_values(array_filter(
            array_map(
                static fn(array $feature): bool => (bool) ($feature['consumable'] ?? false),
                $features,
            ),
            static fn(bool $consumable): bool => !$consumable,
        )) === [];
        $canProceed = $status === 'pass' && $allConsumable;

        return [
            'status' => $status,
            'can_proceed' => $canProceed,
            'requires_repair' => !$canProceed,
            'summary' => $summary,
            'features' => $features,
            'required_actions' => $this->outputCoalescer->coalesceRequiredActions($requiredActions),
        ];
    }

    private function readFile(string $relativePath): string
    {
        $contents = file_get_contents($this->paths->join($relativePath));
        if ($contents === false) {
            throw new FoundryError(
                'CLI_CONTEXT_ALIGNMENT_FILE_UNREADABLE',
                'filesystem',
                ['path' => $relativePath],
                'Context file could not be read for alignment.',
            );
        }

        return $contents;
    }

    /**
     * @return array{spec:string,state:string,decisions:string}
     */
    private function preferredContextPaths(string $featureName): array
    {
        $canonical = $this->resolver->canonicalPaths($featureName);
        $canonicalRoot = dirname($canonical['spec']);
        if (
            is_file($this->paths->join($canonical['spec']))
            || is_file($this->paths->join($canonical['state']))
            || is_file($this->paths->join($canonical['decisions']))
            || is_dir($this->paths->join($canonicalRoot))
        ) {
            return $canonical;
        }

        return $this->resolver->legacyPaths($featureName);
    }

    /**
     * @param array<string,mixed> $doctor
     * @return array{status:string,feature:string,can_proceed:bool,requires_repair:bool,issues:list<array<string,mixed>>,required_actions:list<string>}
     */
    private function preflightFailurePayload(string $featureName, array $doctor): array
    {
        $requiredActions = array_values(array_map(
            'strval',
            (array) ($doctor['required_actions'] ?? []),
        ));

        $message = $requiredActions === ['Use a lowercase kebab-case feature name.']
            ? 'Feature name must be lowercase kebab-case before alignment can be checked.'
            : 'Context files must be structurally valid before alignment can be checked.';

        return (new AlignmentResult(
            status: 'mismatch',
            issues: [
                new AlignmentIssue(
                    code: 'mismatch',
                    message: $message,
                    spec_section: null,
                    state_section: null,
                    decision_reference_found: false,
                ),
            ],
            required_actions: $requiredActions === [] ? ['Repair the feature context files before checking alignment.'] : $requiredActions,
        ))->toArray($featureName);
    }

    /**
     * @param array{
     *     feature:string,
     *     doctor:array<string,mixed>,
     *     alignment:array<string,mixed>,
     *     summary:array{doctor_status:string,alignment_status:string}
     * } $inspection
     * @return array{
     *     feature:string,
     *     status:string,
     *     can_proceed:bool,
     *     requires_repair:bool,
     *     consumable:bool,
     *     doctor_status:string,
     *     alignment_status:string,
     *     issues:list<array<string,mixed>>,
     *     required_actions:list<string>
     * }
     */
    private function verificationPayload(array $inspection): array
    {
        $doctorStatus = (string) ($inspection['summary']['doctor_status'] ?? 'non_compliant');
        $alignmentStatus = (string) ($inspection['summary']['alignment_status'] ?? 'mismatch');
        $status = ContextExecutionReadiness::verifyStatus($doctorStatus, $alignmentStatus);
        $readiness = ContextExecutionReadiness::fromVerifyStatus($status);
        $requiredActions = $this->requiredActionsFromInspection(
            (array) ($inspection['doctor'] ?? []),
            (array) ($inspection['alignment'] ?? []),
        );

        return [
            'feature' => (string) ($inspection['feature'] ?? ''),
            'status' => $status,
            'can_proceed' => $readiness['can_proceed'],
            'requires_repair' => $readiness['requires_repair'],
            'consumable' => ContextExecutionReadiness::isConsumable(
                $doctorStatus,
                $alignmentStatus,
                $requiredActions,
            ),
            'doctor_status' => $doctorStatus,
            'alignment_status' => $alignmentStatus,
            'issues' => $this->verificationIssues(
                (array) ($inspection['doctor'] ?? []),
                (array) ($inspection['alignment'] ?? []),
            ),
            'required_actions' => $requiredActions,
        ];
    }

    /**
     * @param array<string,mixed> $doctor
     * @param array<string,mixed> $alignment
     * @return list<array<string,mixed>>
     */
    private function verificationIssues(array $doctor, array $alignment): array
    {
        $issues = $this->doctorService->flattenIssues($doctor);

        foreach ((array) ($alignment['issues'] ?? []) as $issue) {
            if (!is_array($issue)) {
                continue;
            }

            $issues[] = [
                'source' => 'alignment',
                'code' => (string) ($issue['code'] ?? ''),
                'message' => (string) ($issue['message'] ?? ''),
                'spec_section' => $issue['spec_section'] ?? null,
                'state_section' => $issue['state_section'] ?? null,
                'decision_reference_found' => (bool) ($issue['decision_reference_found'] ?? false),
            ];
        }

        return $this->outputCoalescer->coalesceIssueRows($issues);
    }

    /**
     * @param array<string,mixed> $doctor
     * @param array<string,mixed> $alignment
     * @return list<string>
     */
    private function requiredActionsFromInspection(array $doctor, array $alignment): array
    {
        return $this->outputCoalescer->coalesceRequiredActions(array_merge(
            array_values(array_map('strval', (array) ($doctor['required_actions'] ?? []))),
            array_values(array_map('strval', (array) ($alignment['required_actions'] ?? []))),
        ));
    }
}
