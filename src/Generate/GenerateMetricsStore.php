<?php

declare(strict_types=1);

namespace Foundry\Generate;

use Foundry\Support\FoundryError;
use Foundry\Support\Json;
use Foundry\Support\Paths;
use Foundry\Support\Uuid;

final class GenerateMetricsStore
{
    public function __construct(
        private readonly Paths $paths,
    ) {}

    public function enabled(): bool
    {
        $path = $this->paths->join('.foundry/config/metrics.json');
        if (!is_file($path)) {
            return false;
        }

        $contents = file_get_contents($path);
        if (!is_string($contents) || trim($contents) === '') {
            return false;
        }

        try {
            $payload = Json::decodeAssoc($contents);
        } catch (\Throwable) {
            return false;
        }

        return (($payload['metrics']['enabled'] ?? false) === true);
    }

    /**
     * @param array<string,mixed> $record
     */
    public function append(array $record): void
    {
        $records = $this->loadRecords();
        $last = $records === [] ? null : $records[array_key_last($records)];
        $record['entry_index'] = count($records);
        $record['previous_digest'] = is_array($last) ? ($last['entry_digest'] ?? null) : null;
        $records[] = $this->normalizeRecord($record);
        $this->writeRecords($records);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function list(): array
    {
        $records = $this->loadRecords();
        $normalized = [];
        foreach ($records as $record) {
            $normalized[] = $this->normalizeRecord($record);
        }

        return $normalized;
    }

    /**
     * @return array<string,mixed>
     */
    public function aggregates(): array
    {
        $records = $this->list();
        $totalRuns = count($records);
        $failures = 0;
        $workflowCount = 0;
        $workflowSteps = 0;
        $templates = [];
        $approvals = ['required' => 0, 'approved' => 0, 'pending' => 0, 'rejected' => 0];
        $policyViolations = 0;

        foreach ($records as $record) {
            if (($record['status'] ?? '') === 'failed') {
                $failures++;
            }
            if (($record['type'] ?? '') === 'workflow') {
                $workflowCount++;
                $workflowSteps += (int) ($record['steps'] ?? 0);
            }
            $templateId = $record['template_id'] ?? null;
            if (is_string($templateId) && $templateId !== '') {
                $templates[$templateId] = (int) ($templates[$templateId] ?? 0) + 1;
            }
            if (($record['approval_required'] ?? false) === true) {
                $approvals['required']++;
                $status = (string) ($record['approval_status'] ?? 'pending');
                if (array_key_exists($status, $approvals)) {
                    $approvals[$status]++;
                }
            }
            $policyViolations += max(0, (int) ($record['policy_violations'] ?? 0));
        }

        ksort($templates);
        $failureRate = $totalRuns === 0 ? 0.0 : round(($failures / $totalRuns) * 100, 2);
        $averageSteps = $workflowCount === 0 ? 0.0 : round($workflowSteps / $workflowCount, 2);

        return [
            'total_runs' => $totalRuns,
            'failures' => $failures,
            'failure_rate' => $failureRate,
            'average_steps' => $averageSteps,
            'templates' => $templates,
            'approvals' => $approvals,
            'policy_violations' => $policyViolations,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function verify(): array
    {
        $records = $this->loadRecords();
        $issues = [];
        $expectedPreviousDigest = null;

        foreach ($records as $index => $record) {
            if (!is_array($record)) {
                $issues[] = ['code' => 'GENERATE_METRICS_RECORD_INVALID', 'record_index' => $index];
                continue;
            }

            try {
                $normalized = $this->normalizeRecord($record);
            } catch (FoundryError) {
                $issues[] = ['code' => 'GENERATE_METRICS_SCHEMA_INVALID', 'record_index' => $index];
                continue;
            }

            if (($normalized['entry_index'] ?? null) !== $index) {
                $issues[] = ['code' => 'GENERATE_METRICS_ORDER_CORRUPTED', 'record_index' => $index];
            }
            if (($normalized['previous_digest'] ?? null) !== $expectedPreviousDigest) {
                $issues[] = ['code' => 'GENERATE_METRICS_ORDER_CORRUPTED', 'record_index' => $index];
            }
            $expectedDigest = $this->entryDigest($normalized);
            if (($normalized['entry_digest'] ?? null) !== $expectedDigest) {
                $issues[] = ['code' => 'GENERATE_METRICS_RECORD_MUTATED', 'record_index' => $index];
            }
            $expectedPreviousDigest = $expectedDigest;
        }

        return $issues;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadRecords(): array
    {
        $path = $this->recordsPath();
        if (!is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);
        if (!is_string($contents) || trim($contents) === '') {
            return [];
        }

        $payload = Json::decodeAssoc($contents);
        $records = $payload['records'] ?? [];

        return array_values(array_filter((array) $records, 'is_array'));
    }

    /**
     * @param list<array<string,mixed>> $records
     */
    private function writeRecords(array $records): void
    {
        $this->ensureDirectory();
        $payload = ['schema' => 'foundry.generate.metrics_store.v1', 'records' => $records];
        $path = $this->recordsPath();
        if (file_put_contents($path, Json::encode($payload, true) . PHP_EOL) === false) {
            throw new FoundryError(
                'GENERATE_METRICS_WRITE_FAILED',
                'filesystem',
                ['path' => '.foundry/metrics/generate-metrics.json'],
                'Unable to persist generate metrics.',
            );
        }
    }

    /**
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private function normalizeRecord(array $record): array
    {
        $type = (string) ($record['type'] ?? '');
        $status = (string) ($record['status'] ?? '');
        if (!in_array($type, ['single', 'workflow'], true) || !in_array($status, ['completed', 'failed'], true)) {
            throw new FoundryError(
                'GENERATE_METRICS_SCHEMA_INVALID',
                'validation',
                ['type' => $type, 'status' => $status],
                'Generate metrics record type or status is invalid.',
            );
        }

        $normalized = [
            'schema' => 'foundry.generate.metrics_record.v1',
            'record_id' => (string) ($record['record_id'] ?? Uuid::v4()),
            'type' => $type,
            'template_id' => is_string($record['template_id'] ?? null) ? (string) $record['template_id'] : null,
            'workflow_id' => is_string($record['workflow_id'] ?? null) ? (string) $record['workflow_id'] : null,
            'steps' => max(0, (int) ($record['steps'] ?? 0)),
            'status' => $status,
            'policy_violations' => max(0, (int) ($record['policy_violations'] ?? 0)),
            'approval_required' => ($record['approval_required'] ?? false) === true,
            'approval_status' => is_string($record['approval_status'] ?? null) ? (string) $record['approval_status'] : null,
            'timestamp' => null,
            'entry_index' => max(0, (int) ($record['entry_index'] ?? 0)),
            'previous_digest' => $record['previous_digest'] ?? null,
        ];
        $normalized['entry_digest'] = $this->entryDigest($normalized);

        return $normalized;
    }

    /**
     * @param array<string,mixed> $record
     */
    private function entryDigest(array $record): string
    {
        $digestPayload = [
            'schema' => $record['schema'] ?? null,
            'record_id' => $record['record_id'] ?? null,
            'type' => $record['type'] ?? null,
            'template_id' => $record['template_id'] ?? null,
            'workflow_id' => $record['workflow_id'] ?? null,
            'steps' => $record['steps'] ?? null,
            'status' => $record['status'] ?? null,
            'policy_violations' => $record['policy_violations'] ?? null,
            'approval_required' => $record['approval_required'] ?? null,
            'approval_status' => $record['approval_status'] ?? null,
            'timestamp' => $record['timestamp'] ?? null,
            'entry_index' => $record['entry_index'] ?? null,
            'previous_digest' => $record['previous_digest'] ?? null,
        ];

        return hash('sha256', Json::encode($digestPayload));
    }

    private function ensureDirectory(): void
    {
        $dir = $this->paths->join('.foundry/metrics');
        if (is_dir($dir)) {
            return;
        }

        if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new FoundryError(
                'GENERATE_METRICS_DIRECTORY_CREATE_FAILED',
                'filesystem',
                ['path' => '.foundry/metrics'],
                'Unable to create generate metrics directory.',
            );
        }
    }

    private function recordsPath(): string
    {
        return $this->paths->join('.foundry/metrics/generate-metrics.json');
    }
}
