<?php

declare(strict_types=1);

namespace Foundry\Generate;

use Foundry\Support\FoundryError;
use Foundry\Support\Json;
use Foundry\Support\Paths;

final class PlanRecordStore
{
    private const int STORAGE_VERSION = 3;

    /**
     * @param null|\Closure():\DateTimeImmutable $clock
     */
    public function __construct(
        private readonly Paths $paths,
        private readonly ?\Closure $clock = null,
    ) {}

    /**
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    public function persist(array $record): array
    {
        $planId = trim((string) ($record['plan_id'] ?? ''));
        if ($planId === '') {
            throw new FoundryError(
                'PLAN_RECORD_ID_REQUIRED',
                'validation',
                [],
                'Persisted plan records require a plan id.',
            );
        }

        $timestamp = $this->now();
        $storagePath = '.foundry/plans/' . $this->fileTimestamp($timestamp) . '_' . $planId . '.json';

        $record['storage_version'] = self::STORAGE_VERSION;
        $record['timestamp'] = $timestamp->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        $record['storage_path'] = $storagePath;

        $metadata = is_array($record['metadata'] ?? null) ? $record['metadata'] : [];
        $metadata['storage_version'] = self::STORAGE_VERSION;
        unset($metadata['integrity_hash']);
        $record['metadata'] = $metadata;
        $record['metadata']['integrity_hash'] = $this->integrityHash($record);
        $this->validateRecordShape($record);

        $this->ensureDirectory();
        $absolutePath = $this->paths->join($storagePath);
        $encoded = Json::encode($record, true) . PHP_EOL;
        if ($this->writeRecord($absolutePath, $encoded) === false) {
            throw new FoundryError(
                'PLAN_RECORD_WRITE_FAILED',
                'filesystem',
                ['path' => $storagePath],
                'Unable to persist the plan record.',
            );
        }

        return $record;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function list(): array
    {
        $records = $this->loadValidatedRecords();

        usort(
            $records,
            static fn(array $left, array $right): int => [
                (string) ($right['timestamp'] ?? ''),
                (string) ($right['plan_id'] ?? ''),
            ] <=> [
                (string) ($left['timestamp'] ?? ''),
                (string) ($left['plan_id'] ?? ''),
            ],
        );

        return array_values(array_map($this->summary(...), $records));
    }

    /**
     * @return array<string,mixed>|null
     */
    public function load(string $planId): ?array
    {
        $matches = [];

        foreach ($this->loadValidatedRecords() as $record) {
            if ((string) ($record['plan_id'] ?? '') !== $planId) {
                continue;
            }

            $matches[] = $record;
        }

        if ($matches === []) {
            return null;
        }

        if (count($matches) > 1) {
            throw new FoundryError(
                'PLAN_RECORD_DUPLICATE_ID',
                'validation',
                ['plan_id' => $planId],
                'Multiple persisted plan records share the same plan id.',
            );
        }

        return $matches[0];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadValidatedRecords(): array
    {
        $records = [];

        foreach ($this->recordPaths() as $path) {
            $records[] = $this->loadRecord($path);
        }

        $this->validateWorkflowRecordSet($records);

        return $records;
    }

    /**
     * @return list<string>
     */
    private function recordPaths(): array
    {
        $paths = glob($this->plansDir() . '/*.json') ?: [];
        $paths = array_values(array_map('strval', $paths));
        sort($paths);

        return $paths;
    }

    /**
     * @return array<string,mixed>
     */
    private function loadRecord(string $path): array
    {
        $json = file_get_contents($path);
        if (!is_string($json) || trim($json) === '') {
            throw new FoundryError(
                'PLAN_RECORD_UNREADABLE',
                'filesystem',
                ['path' => $this->relativePath($path)],
                'Persisted plan record is unreadable.',
            );
        }

        $record = Json::decodeAssoc($json);
        $planId = trim((string) ($record['plan_id'] ?? ''));
        if ($planId === '') {
            throw new FoundryError(
                'PLAN_RECORD_INVALID',
                'validation',
                ['path' => $this->relativePath($path)],
                'Persisted plan record is missing its plan id.',
            );
        }

        $this->validateRecordShape($record, $this->relativePath($path));

        return $record;
    }

    /**
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private function summary(array $record): array
    {
        $workflowLinkage = $this->workflowStepLinkage($record);

        return [
            'plan_id' => (string) ($record['plan_id'] ?? ''),
            'timestamp' => (string) ($record['timestamp'] ?? ''),
            'intent' => (string) ($record['intent'] ?? ''),
            'mode' => (string) ($record['mode'] ?? ''),
            'status' => (string) ($record['status'] ?? ''),
            'record_kind' => $this->recordKind($record),
            'template_id' => is_array($record['metadata']['template'] ?? null)
                ? (($record['metadata']['template']['template_id'] ?? null) !== null
                    ? (string) $record['metadata']['template']['template_id']
                    : null)
                : null,
            'workflow_id' => $this->workflowRecord($record)
                ? (string) ($record['workflow_id'] ?? '')
                : ($workflowLinkage['workflow_id'] ?? null),
            'workflow_source_path' => $this->workflowRecord($record)
                ? (string) ($record['source']['path'] ?? '')
                : null,
            'workflow_step_id' => $workflowLinkage['step_id'] ?? null,
            'workflow_step_index' => $workflowLinkage['step_index'] ?? null,
            'risk_level' => $record['risk_level'] ?? null,
            'interactive' => (bool) (($record['interactive']['enabled'] ?? false) === true),
            'affected_files' => count((array) ($record['affected_files'] ?? [])),
            'storage_path' => (string) ($record['storage_path'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $record
     */
    private function validateRecordShape(array $record, ?string $path = null): void
    {
        if ($this->workflowRecordCandidate($record)) {
            $this->validateWorkflowRecordShape($record, $path);
        }

        $workflowLinkage = $this->workflowStepLinkage($record);
        if ($workflowLinkage !== null) {
            $this->validateWorkflowStepLinkageShape($workflowLinkage, $record, $path);
        }
    }

    /**
     * @param list<array<string,mixed>> $records
     */
    private function validateWorkflowRecordSet(array $records): void
    {
        $workflows = [];
        $recordsByPlanId = [];

        foreach ($records as $record) {
            $recordsByPlanId[(string) ($record['plan_id'] ?? '')] = $record;

            if (!$this->workflowRecord($record)) {
                continue;
            }

            $workflowId = (string) ($record['workflow_id'] ?? '');
            $workflows[$workflowId][] = $record;
        }

        foreach ($workflows as $parents) {
            foreach ($parents as $parent) {
                foreach (array_values(array_filter((array) ($parent['steps'] ?? []), 'is_array')) as $step) {
                    $recordId = trim((string) ($step['record_id'] ?? ''));
                    if ($recordId === '') {
                        continue;
                    }

                    if (!isset($recordsByPlanId[$recordId])) {
                        throw new FoundryError(
                            'PLAN_RECORD_WORKFLOW_STEP_RECORD_MISSING',
                            'validation',
                            [
                                'plan_id' => (string) ($parent['plan_id'] ?? ''),
                                'workflow_id' => (string) ($parent['workflow_id'] ?? ''),
                                'step_id' => (string) ($step['step_id'] ?? ''),
                                'record_id' => $recordId,
                            ],
                            'Workflow plan record references a missing step record.',
                        );
                    }
                }
            }
        }

        foreach ($records as $record) {
            $workflowLinkage = $this->workflowStepLinkage($record);
            if ($workflowLinkage === null) {
                continue;
            }

            $workflowId = (string) ($workflowLinkage['workflow_id'] ?? '');
            $parents = $workflows[$workflowId] ?? [];
            if ($parents === []) {
                throw new FoundryError(
                    'PLAN_RECORD_WORKFLOW_PARENT_MISSING',
                    'validation',
                    [
                        'plan_id' => (string) ($record['plan_id'] ?? ''),
                        'workflow_id' => $workflowId,
                    ],
                    'Workflow step record references a missing workflow record.',
                );
            }

            $matched = false;
            foreach ($parents as $parent) {
                if ($this->workflowStepMatchesParent($record, $workflowLinkage, $parent)) {
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                throw new FoundryError(
                    'PLAN_RECORD_WORKFLOW_STEP_MISMATCH',
                    'validation',
                    [
                        'plan_id' => (string) ($record['plan_id'] ?? ''),
                        'workflow_id' => $workflowId,
                        'step_id' => (string) ($workflowLinkage['step_id'] ?? ''),
                        'step_index' => $workflowLinkage['step_index'] ?? null,
                    ],
                    'Workflow step record does not match any declared parent workflow step.',
                );
            }
        }
    }

    /**
     * @param array<string,mixed> $record
     */
    private function workflowRecordCandidate(array $record): bool
    {
        if ((string) ($record['mode'] ?? '') === 'workflow') {
            return true;
        }

        foreach (['schema', 'workflow_id', 'source', 'steps', 'shared_context', 'result', 'rollback_guidance'] as $key) {
            if (array_key_exists($key, $record)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $record
     */
    private function workflowRecord(array $record): bool
    {
        return (string) ($record['schema'] ?? '') === 'foundry.generate.workflow_record.v1';
    }

    /**
     * @param array<string,mixed> $record
     * @return array<string,mixed>|null
     */
    private function workflowStepLinkage(array $record): ?array
    {
        $workflow = $record['metadata']['workflow'] ?? null;

        return is_array($workflow) && (($workflow['is_workflow_step'] ?? false) === true)
            ? $workflow
            : null;
    }

    /**
     * @param array<string,mixed> $record
     */
    private function recordKind(array $record): string
    {
        if ($this->workflowRecord($record)) {
            return 'workflow';
        }

        if ($this->workflowStepLinkage($record) !== null) {
            return 'workflow_step';
        }

        return 'generate';
    }

    /**
     * @param array<string,mixed> $record
     */
    private function validateWorkflowRecordShape(array $record, ?string $path): void
    {
        if ((string) ($record['schema'] ?? '') !== 'foundry.generate.workflow_record.v1') {
            throw new FoundryError(
                'PLAN_RECORD_WORKFLOW_SCHEMA_INVALID',
                'validation',
                ['path' => $path, 'plan_id' => (string) ($record['plan_id'] ?? '')],
                'Workflow plan record must use schema foundry.generate.workflow_record.v1.',
            );
        }

        $workflowId = trim((string) ($record['workflow_id'] ?? ''));
        $source = is_array($record['source'] ?? null) ? $record['source'] : null;
        $status = (string) ($record['status'] ?? '');
        $steps = array_values(array_filter((array) ($record['steps'] ?? []), 'is_array'));
        $result = is_array($record['result'] ?? null) ? $record['result'] : null;
        $rollbackGuidance = $record['rollback_guidance'] ?? null;

        if ($workflowId === '' || $source === null || $result === null || !is_array($rollbackGuidance)) {
            throw new FoundryError(
                'PLAN_RECORD_WORKFLOW_INVALID',
                'validation',
                ['path' => $path, 'plan_id' => (string) ($record['plan_id'] ?? '')],
                'Workflow plan record is missing required canonical fields.',
            );
        }

        if ((string) ($source['type'] ?? '') !== 'repository_file' || trim((string) ($source['path'] ?? '')) === '') {
            throw new FoundryError(
                'PLAN_RECORD_WORKFLOW_SOURCE_INVALID',
                'validation',
                ['path' => $path, 'plan_id' => (string) ($record['plan_id'] ?? '')],
                'Workflow plan record source must identify a repository file path.',
            );
        }

        $sourcePath = (string) ($source['path'] ?? '');
        if (str_starts_with($sourcePath, '/')) {
            throw new FoundryError(
                'PLAN_RECORD_WORKFLOW_SOURCE_INVALID',
                'validation',
                ['path' => $path, 'plan_id' => (string) ($record['plan_id'] ?? '')],
                'Workflow plan record source path must be repository-relative.',
            );
        }

        if (!in_array($status, ['completed', 'failed'], true)) {
            throw new FoundryError(
                'PLAN_RECORD_WORKFLOW_STATUS_INVALID',
                'validation',
                ['path' => $path, 'plan_id' => (string) ($record['plan_id'] ?? '')],
                'Workflow plan record status must be completed or failed.',
            );
        }

        if (($record['started_at'] ?? null) !== null || ($record['completed_at'] ?? null) !== null) {
            throw new FoundryError(
                'PLAN_RECORD_WORKFLOW_TIMESTAMP_INVALID',
                'validation',
                ['path' => $path, 'plan_id' => (string) ($record['plan_id'] ?? '')],
                'Workflow plan record timestamps must be null in V1.',
            );
        }

        $failedSteps = [];
        $completedSteps = 0;
        $skippedSteps = 0;
        foreach ($steps as $position => $step) {
            $this->validateWorkflowStepShape($step, $position, $record, $path);
            $stepStatus = (string) ($step['status'] ?? '');
            if ($stepStatus === 'completed') {
                $completedSteps++;
            } elseif ($stepStatus === 'failed') {
                $failedSteps[] = (string) ($step['step_id'] ?? '');
            } elseif ($stepStatus === 'skipped') {
                $skippedSteps++;
            }
        }

        if ((int) ($result['completed_steps'] ?? -1) !== $completedSteps) {
            throw new FoundryError(
                'PLAN_RECORD_WORKFLOW_RESULT_INVALID',
                'validation',
                ['path' => $path, 'plan_id' => (string) ($record['plan_id'] ?? '')],
                'Workflow result completed_steps does not match the declared steps.',
            );
        }

        if ((int) ($result['skipped_steps'] ?? -1) !== $skippedSteps) {
            throw new FoundryError(
                'PLAN_RECORD_WORKFLOW_RESULT_INVALID',
                'validation',
                ['path' => $path, 'plan_id' => (string) ($record['plan_id'] ?? '')],
                'Workflow result skipped_steps does not match the declared steps.',
            );
        }

        $failedStep = $result['failed_step'] ?? null;
        if ($status === 'completed') {
            if ($failedSteps !== [] || $skippedSteps > 0 || $failedStep !== null) {
                throw new FoundryError(
                    'PLAN_RECORD_WORKFLOW_STATUS_INVALID',
                    'validation',
                    ['path' => $path, 'plan_id' => (string) ($record['plan_id'] ?? '')],
                    'Completed workflow records cannot include failed or skipped steps.',
                );
            }
        }

        if ($status === 'failed') {
            if ($failedSteps === [] || !is_string($failedStep) || trim($failedStep) === '') {
                throw new FoundryError(
                    'PLAN_RECORD_WORKFLOW_STATUS_INVALID',
                    'validation',
                    ['path' => $path, 'plan_id' => (string) ($record['plan_id'] ?? '')],
                    'Failed workflow records must record a failed step.',
                );
            }

            if (!in_array($failedStep, $failedSteps, true)) {
                throw new FoundryError(
                    'PLAN_RECORD_WORKFLOW_RESULT_INVALID',
                    'validation',
                    ['path' => $path, 'plan_id' => (string) ($record['plan_id'] ?? '')],
                    'Workflow result failed_step must match one failed workflow step.',
                );
            }
        }
    }

    /**
     * @param array<string,mixed> $step
     * @param array<string,mixed> $record
     */
    private function validateWorkflowStepShape(array $step, int $position, array $record, ?string $path): void
    {
        $stepId = trim((string) ($step['step_id'] ?? ''));
        $index = $step['index'] ?? null;
        $status = (string) ($step['status'] ?? '');
        $recordId = $step['record_id'] ?? null;
        $dependencies = $step['dependencies'] ?? null;

        if ($stepId === '' || !is_int($index) || !is_array($dependencies)) {
            throw new FoundryError(
                'PLAN_RECORD_WORKFLOW_STEP_INVALID',
                'validation',
                ['path' => $path, 'plan_id' => (string) ($record['plan_id'] ?? '')],
                'Workflow plan record step entries are missing required fields.',
            );
        }

        if ($index !== $position) {
            throw new FoundryError(
                'PLAN_RECORD_WORKFLOW_STEP_INDEX_INVALID',
                'validation',
                ['path' => $path, 'plan_id' => (string) ($record['plan_id'] ?? '')],
                'Workflow plan record step indexes must be contiguous and ordered.',
            );
        }

        if (!in_array($status, ['completed', 'failed', 'skipped'], true)) {
            throw new FoundryError(
                'PLAN_RECORD_WORKFLOW_STEP_INVALID',
                'validation',
                ['path' => $path, 'plan_id' => (string) ($record['plan_id'] ?? '')],
                'Workflow plan record step status must be completed, failed, or skipped.',
            );
        }

        $normalizedDependencies = array_values(array_filter(array_map(
            static fn(mixed $dependency): string => trim((string) $dependency),
            $dependencies,
        ), static fn(string $dependency): bool => $dependency !== ''));
        $sortedDependencies = $normalizedDependencies;
        sort($sortedDependencies);

        if ($normalizedDependencies !== array_values(array_unique($normalizedDependencies)) || $normalizedDependencies !== $sortedDependencies) {
            throw new FoundryError(
                'PLAN_RECORD_WORKFLOW_STEP_INVALID',
                'validation',
                ['path' => $path, 'plan_id' => (string) ($record['plan_id'] ?? '')],
                'Workflow plan record step dependencies must be unique and deterministically ordered.',
            );
        }

        if ($status === 'completed' && trim((string) $recordId) === '') {
            throw new FoundryError(
                'PLAN_RECORD_WORKFLOW_STEP_RECORD_REQUIRED',
                'validation',
                ['path' => $path, 'plan_id' => (string) ($record['plan_id'] ?? '')],
                'Completed workflow steps must reference a persisted step record.',
            );
        }

        if ($status === 'skipped' && $recordId !== null) {
            throw new FoundryError(
                'PLAN_RECORD_WORKFLOW_STEP_RECORD_INVALID',
                'validation',
                ['path' => $path, 'plan_id' => (string) ($record['plan_id'] ?? '')],
                'Skipped workflow steps must not reference a persisted step record.',
            );
        }
    }

    /**
     * @param array<string,mixed> $workflowLinkage
     * @param array<string,mixed> $record
     */
    private function validateWorkflowStepLinkageShape(array $workflowLinkage, array $record, ?string $path): void
    {
        $workflowId = trim((string) ($workflowLinkage['workflow_id'] ?? ''));
        $stepId = trim((string) ($workflowLinkage['step_id'] ?? ''));
        $stepIndex = $workflowLinkage['step_index'] ?? null;

        if ($workflowId === '' || $stepId === '' || !is_int($stepIndex)) {
            throw new FoundryError(
                'PLAN_RECORD_WORKFLOW_LINKAGE_INVALID',
                'validation',
                ['path' => $path, 'plan_id' => (string) ($record['plan_id'] ?? '')],
                'Workflow step plan records must include workflow_id, step_id, and integer step_index linkage.',
            );
        }
    }

    /**
     * @param array<string,mixed> $record
     * @param array<string,mixed> $workflowLinkage
     * @param array<string,mixed> $parent
     */
    private function workflowStepMatchesParent(array $record, array $workflowLinkage, array $parent): bool
    {
        foreach ((array) ($parent['steps'] ?? []) as $step) {
            if (!is_array($step)) {
                continue;
            }

            if ((string) ($step['step_id'] ?? '') !== (string) ($workflowLinkage['step_id'] ?? '')) {
                continue;
            }

            if (($step['index'] ?? null) !== ($workflowLinkage['step_index'] ?? null)) {
                continue;
            }

            return (string) ($step['record_id'] ?? '') === (string) ($record['plan_id'] ?? '');
        }

        return false;
    }

    private function plansDir(): string
    {
        return $this->paths->join('.foundry/plans');
    }

    private function ensureDirectory(): void
    {
        $dir = $this->plansDir();
        if (is_dir($dir)) {
            return;
        }

        if ($this->hasNonDirectoryAncestor($dir) || !$this->createDirectory($dir)) {
            throw new FoundryError(
                'PLAN_RECORD_DIRECTORY_CREATE_FAILED',
                'filesystem',
                ['path' => '.foundry/plans'],
                'Unable to create the persisted plan directory.',
            );
        }
    }

    private function createDirectory(string $path): bool
    {
        return $this->withoutFilesystemWarnings(
            static fn(): bool => mkdir($path, 0777, true) || is_dir($path),
        );
    }

    private function writeRecord(string $path, string $encoded): bool
    {
        if (is_dir($path)) {
            return false;
        }

        return $this->withoutFilesystemWarnings(
            static fn(): bool => file_put_contents($path, $encoded) !== false,
        );
    }

    private function hasNonDirectoryAncestor(string $path): bool
    {
        $current = dirname($path);

        while ($current !== '.' && $current !== '/' && $current !== '') {
            if (file_exists($current) && !is_dir($current)) {
                return true;
            }

            $parent = dirname($current);
            if ($parent === $current) {
                break;
            }

            $current = $parent;
        }

        return false;
    }

    private function now(): \DateTimeImmutable
    {
        if ($this->clock instanceof \Closure) {
            return ($this->clock)();
        }

        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private function fileTimestamp(\DateTimeImmutable $timestamp): string
    {
        return $timestamp->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    /**
     * @param array<string,mixed> $record
     */
    private function integrityHash(array $record): string
    {
        return hash('sha256', Json::encode($this->normalizeForHash($record)));
    }

    private function normalizeForHash(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->normalizeForHash(...), $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizeForHash($item);
        }

        return $value;
    }

    private function relativePath(string $path): string
    {
        $root = rtrim(str_replace('\\', '/', $this->paths->root()), '/') . '/';
        $normalized = str_replace('\\', '/', $path);

        return str_starts_with($normalized, $root)
            ? substr($normalized, strlen($root))
            : $normalized;
    }

    /**
     * @param \Closure():bool $operation
     */
    private function withoutFilesystemWarnings(\Closure $operation): bool
    {
        set_error_handler(static fn(): bool => true);

        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }
}
