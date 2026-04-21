<?php

declare(strict_types=1);

namespace Foundry\Context;

use Foundry\Support\FeatureNaming;
use Foundry\Support\FoundryError;
use Foundry\Support\Paths;

final class ContextRepairService
{
    private readonly ContextInspectionService $inspectionService;

    public function __construct(
        private readonly Paths $paths,
        ?ContextInspectionService $inspectionService = null,
        private readonly ContextFileResolver $resolver = new ContextFileResolver(),
        private readonly FeatureSpecDocumentNormalizer $featureSpecDocumentNormalizer = new FeatureSpecDocumentNormalizer(),
        private readonly StateDocumentNormalizer $stateDocumentNormalizer = new StateDocumentNormalizer(),
    ) {
        $this->inspectionService = $inspectionService ?? new ContextInspectionService($paths);
    }

    /**
     * @return array{
     *     status:string,
     *     feature:string,
     *     files_changed:list<string>,
     *     issues_repaired:list<string>,
     *     issues_remaining:list<string>,
     *     can_proceed:bool,
     *     requires_manual_action:bool,
     *     doctor_status:string,
     *     alignment_status:string,
     *     required_actions:list<string>,
     *     error?:array{code:string,message:string}
     * }
     */
    public function repairFeature(string $featureName): array
    {
        $featureName = FeatureNaming::canonical($featureName);
        $filesChanged = [];
        $repairDescriptions = [];
        $preVerification = null;

        try {
            $inspection = $this->inspectionService->inspectFeature($featureName);
            $preVerification = $this->inspectionService->verifyFeature($featureName);

            $missingInputs = $this->missingCriticalInputs($inspection);
            if ($missingInputs !== []) {
                return $this->buildResult(
                    status: 'failed',
                    featureName: $featureName,
                    filesChanged: [],
                    issuesRepaired: [],
                    issuesRemaining: $this->issueTokens((array) ($preVerification['issues'] ?? [])),
                    canProceed: false,
                    requiresManualAction: true,
                    verification: $preVerification,
                    error: [
                        'code' => 'CONTEXT_REPAIR_CRITICAL_INPUT_MISSING',
                        'message' => 'Context repair requires existing canonical feature context files before safe normalization can run.',
                    ],
                );
            }

            foreach (['spec', 'state'] as $bucket) {
                $relativePath = $this->repairablePath($inspection, $bucket);
                if ($relativePath === null) {
                    continue;
                }

                if (!$this->normalizeRepairTarget($bucket, $relativePath)) {
                    continue;
                }

                $filesChanged[] = $relativePath;
                $repairDescriptions[] = 'Normalized ' . $relativePath;
            }

            $postVerification = $this->inspectionService->verifyFeature($featureName);
            $canProceed = (bool) ($postVerification['consumable'] ?? false);
            $issuesRemaining = $this->issueTokens((array) ($postVerification['issues'] ?? []));
            $issuesRepaired = $this->issuesRepaired(
                (array) ($preVerification['issues'] ?? []),
                (array) ($postVerification['issues'] ?? []),
                $repairDescriptions,
            );

            $status = !$canProceed
                ? 'blocked'
                : ($filesChanged === [] ? 'no_changes' : 'repaired');

            return $this->buildResult(
                status: $status,
                featureName: $featureName,
                filesChanged: $filesChanged,
                issuesRepaired: $issuesRepaired,
                issuesRemaining: $issuesRemaining,
                canProceed: $canProceed,
                requiresManualAction: !$canProceed,
                verification: $postVerification,
            );
        } catch (\Throwable $error) {
            return $this->buildResult(
                status: 'failed',
                featureName: $featureName,
                filesChanged: $filesChanged,
                issuesRepaired: $repairDescriptions,
                issuesRemaining: $this->issueTokens((array) (($preVerification ?? [])['issues'] ?? [])),
                canProceed: false,
                requiresManualAction: true,
                verification: is_array($preVerification) ? $preVerification : [],
                error: [
                    'code' => $error instanceof FoundryError ? $error->errorCode : 'CONTEXT_REPAIR_FAILED',
                    'message' => $error->getMessage() !== '' ? $error->getMessage() : 'Context repair failed.',
                ],
            );
        }
    }

    /**
     * @param array<string,mixed> $inspection
     * @return list<string>
     */
    private function missingCriticalInputs(array $inspection): array
    {
        $missing = [];

        foreach (['spec', 'state', 'decisions'] as $bucket) {
            $file = (array) (($inspection['doctor']['files'] ?? [])[$bucket] ?? []);
            $path = (string) ($file['path'] ?? $this->defaultPathForBucket((string) ($inspection['feature'] ?? ''), $bucket));

            if (!(bool) ($file['exists'] ?? false)) {
                $missing[] = $path;
                continue;
            }

            foreach ((array) ($file['issues'] ?? []) as $issue) {
                if (!is_array($issue)) {
                    continue;
                }

                if ((string) ($issue['code'] ?? '') !== 'CONTEXT_FILE_UNREADABLE') {
                    continue;
                }

                $missing[] = (string) ($issue['file_path'] ?? $path);
            }
        }

        return array_values(array_unique(array_filter($missing)));
    }

    /**
     * @param array<string,mixed> $inspection
     */
    private function repairablePath(array $inspection, string $bucket): ?string
    {
        $file = (array) (($inspection['doctor']['files'] ?? [])[$bucket] ?? []);

        if (!(bool) ($file['exists'] ?? false) || !(bool) ($file['valid'] ?? false)) {
            return null;
        }

        $relativePath = (string) ($file['path'] ?? '');

        return $relativePath !== '' ? $relativePath : null;
    }

    private function normalizeRepairTarget(string $bucket, string $relativePath): bool
    {
        $path = $this->paths->join($relativePath);
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new FoundryError(
                'CONTEXT_REPAIR_FILE_UNREADABLE',
                'filesystem',
                ['path' => $relativePath],
                'Context repair could not read the canonical context file.',
            );
        }

        $normalized = match ($bucket) {
            'spec' => $this->featureSpecDocumentNormalizer->normalize($contents),
            'state' => $this->stateDocumentNormalizer->normalize($contents),
            default => $contents,
        };

        if ($normalized === $contents) {
            return false;
        }

        if (file_put_contents($path, $normalized) === false) {
            throw new FoundryError(
                'CONTEXT_REPAIR_FILE_WRITE_FAILED',
                'filesystem',
                ['path' => $relativePath],
                'Context repair could not write the normalized canonical context file.',
            );
        }

        return true;
    }

    /**
     * @param list<array<string,mixed>> $preIssues
     * @param list<array<string,mixed>> $postIssues
     * @param list<string> $repairDescriptions
     * @return list<string>
     */
    private function issuesRepaired(array $preIssues, array $postIssues, array $repairDescriptions): array
    {
        $preTokens = $this->issueTokens($preIssues);
        $postSet = array_fill_keys($this->issueTokens($postIssues), true);
        $repaired = array_values(array_filter(
            $preTokens,
            static fn(string $token): bool => !isset($postSet[$token]),
        ));

        if ($repaired !== []) {
            return $repaired;
        }

        return array_values(array_unique($repairDescriptions));
    }

    /**
     * @param list<array<string,mixed>> $issues
     * @return list<string>
     */
    private function issueTokens(array $issues): array
    {
        $tokens = [];
        $seen = [];

        foreach ($issues as $issue) {
            if (!is_array($issue)) {
                continue;
            }

            $token = $this->issueToken($issue);
            if ($token === '' || isset($seen[$token])) {
                continue;
            }

            $seen[$token] = true;
            $tokens[] = $token;
        }

        return $tokens;
    }

    /**
     * @param array<string,mixed> $issue
     */
    private function issueToken(array $issue): string
    {
        $source = trim((string) ($issue['source'] ?? ''));
        $code = trim((string) ($issue['code'] ?? ''));
        $filePath = trim((string) ($issue['file_path'] ?? ''));

        if ($code === '') {
            return '';
        }

        $token = $source !== '' ? $source . ':' . $code : $code;

        if ($filePath !== '') {
            $token .= ' @ ' . $filePath;
        }

        return $token;
    }

    private function defaultPathForBucket(string $featureName, string $bucket): string
    {
        return match ($bucket) {
            'spec' => $this->resolver->specPath($featureName),
            'state' => $this->resolver->statePath($featureName),
            'decisions' => $this->resolver->decisionsPath($featureName),
            default => '',
        };
    }

    /**
     * @param list<string> $filesChanged
     * @param list<string> $issuesRepaired
     * @param list<string> $issuesRemaining
     * @param array<string,mixed> $verification
     * @param array{code:string,message:string}|null $error
     * @return array{
     *     status:string,
     *     feature:string,
     *     files_changed:list<string>,
     *     issues_repaired:list<string>,
     *     issues_remaining:list<string>,
     *     can_proceed:bool,
     *     requires_manual_action:bool,
     *     doctor_status:string,
     *     alignment_status:string,
     *     required_actions:list<string>,
     *     error?:array{code:string,message:string}
     * }
     */
    private function buildResult(
        string $status,
        string $featureName,
        array $filesChanged,
        array $issuesRepaired,
        array $issuesRemaining,
        bool $canProceed,
        bool $requiresManualAction,
        array $verification,
        ?array $error = null,
    ): array {
        $payload = [
            'status' => $status,
            'feature' => $featureName,
            'files_changed' => $filesChanged,
            'issues_repaired' => $issuesRepaired,
            'issues_remaining' => $issuesRemaining,
            'can_proceed' => $canProceed,
            'requires_manual_action' => $requiresManualAction,
            'doctor_status' => (string) ($verification['doctor_status'] ?? 'repairable'),
            'alignment_status' => (string) ($verification['alignment_status'] ?? 'mismatch'),
            'required_actions' => array_values(array_map(
                'strval',
                (array) ($verification['required_actions'] ?? []),
            )),
        ];

        if ($error !== null) {
            $payload['error'] = $error;
        }

        return $payload;
    }
}
