<?php

declare(strict_types=1);

namespace Foundry\Tooling;

use Foundry\Compiler\BuildLayout;
use Foundry\Compiler\CompileResult;
use Foundry\Support\Json;

final class BuildArtifactStore
{
    public function __construct(private readonly BuildLayout $layout) {}

    /**
     * @return array<string,mixed>
     */
    public function persistBuildSummary(CompileResult $compileResult): array
    {
        $payload = [
            'graph_version' => $compileResult->graph->graphVersion(),
            'framework_version' => $compileResult->graph->frameworkVersion(),
            'source_hash' => $compileResult->graph->sourceHash(),
            'diagnostics_summary' => $compileResult->diagnostics->summary(),
            'cache' => $compileResult->cache,
            'plan' => $compileResult->plan->toArray(),
            'node_counts' => $compileResult->graph->nodeCountsByType(),
            'edge_counts' => $compileResult->graph->edgeCountsByType(),
        ];

        return $this->persistRecord(
            kind: 'build',
            payload: $payload,
            latestPath: null,
            label: 'Build ' . $compileResult->graph->sourceHash(),
            sourceHash: $compileResult->graph->sourceHash(),
        );
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function persistQualityReport(array $payload): array
    {
        $this->persistJson($this->layout->qualitySummaryPath(), $payload);
        $this->persistJson($this->layout->qualityStaticAnalysisPath(), (array) ($payload['static_analysis'] ?? []));
        $this->persistJson($this->layout->qualityStylePath(), (array) ($payload['style_violations'] ?? []));

        if (is_array($payload['test_summary'] ?? null)) {
            $this->persistJson($this->layout->qualityTestSummaryPath(), (array) $payload['test_summary']);
        }

        return $this->persistRecord(
            kind: 'quality',
            payload: $payload,
            latestPath: null,
            label: 'Quality ' . (string) ($payload['source_hash'] ?? 'current'),
            sourceHash: isset($payload['source_hash']) ? (string) $payload['source_hash'] : null,
        );
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function persistTraceReport(array $payload): array
    {
        $this->persistJson($this->layout->observeTracePath(), $payload);

        return $this->persistRecord(
            kind: 'trace',
            payload: $payload,
            latestPath: null,
            label: 'Trace ' . (string) (($payload['target']['feature'] ?? $payload['target']['route_signature'] ?? 'all')),
            sourceHash: isset($payload['source_hash']) ? (string) $payload['source_hash'] : null,
        );
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function persistProfileReport(array $payload): array
    {
        $this->persistJson($this->layout->observeProfilePath(), $payload);

        return $this->persistRecord(
            kind: 'profile',
            payload: $payload,
            latestPath: null,
            label: 'Profile ' . (string) (($payload['target']['feature'] ?? $payload['target']['route_signature'] ?? 'all')),
            sourceHash: isset($payload['source_hash']) ? (string) $payload['source_hash'] : null,
        );
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function persistComparisonReport(array $payload): array
    {
        $this->persistJson($this->layout->observeComparePath(), $payload);

        return $this->persistRecord(
            kind: 'comparison',
            payload: $payload,
            latestPath: null,
            label: sprintf(
                'Compare %s -> %s',
                (string) (($payload['run_a']['id'] ?? 'a')),
                (string) (($payload['run_b']['id'] ?? 'b')),
            ),
            sourceHash: null,
        );
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function persistGenerateRecord(array $payload): array
    {
        $intent = is_array($payload['intent'] ?? null) ? $payload['intent'] : [];
        $plan = is_array($payload['plan'] ?? null) ? $payload['plan'] : [];
        $target = trim((string) ($payload['target'] ?? ''));

        return $this->persistRecord(
            kind: 'generate',
            payload: $payload,
            latestPath: null,
            label: sprintf(
                'Generate %s%s',
                (string) ($intent['mode'] ?? 'new'),
                $target !== '' ? ' ' . $target : '',
            ),
            sourceHash: isset($payload['source_hash']) ? (string) $payload['source_hash'] : (isset($plan['metadata']['source_hash']) ? (string) $plan['metadata']['source_hash'] : null),
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listHistory(?string $kind = null): array
    {
        $index = $this->readIndex();
        $records = array_values(array_filter(
            array_values((array) ($index['records'] ?? [])),
            static fn(mixed $row): bool => is_array($row),
        ));

        if ($kind !== null && $kind !== '') {
            $records = array_values(array_filter(
                $records,
                static fn(array $row): bool => (string) ($row['kind'] ?? '') === $kind,
            ));
        }

        usort(
            $records,
            static fn(array $a, array $b): int => ((int) ($b['sequence'] ?? 0)) <=> ((int) ($a['sequence'] ?? 0)),
        );

        return $records;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function loadHistory(string $id): ?array
    {
        $path = $this->layout->historyRecordPath($id);
        if (!is_file($path)) {
            return null;
        }

        $json = file_get_contents($path);
        if (!is_string($json) || $json === '') {
            return null;
        }

        return Json::decodeAssoc($json);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function latestRecord(string $kind): ?array
    {
        $record = $this->listHistory($kind)[0] ?? null;
        if (!is_array($record)) {
            return null;
        }

        return $this->loadHistory((string) ($record['id'] ?? ''));
    }

    /**
     * @return array<string,mixed>|null
     */
    public function previousRecord(string $kind, string $excludeId): ?array
    {
        foreach ($this->listHistory($kind) as $record) {
            if ((string) ($record['id'] ?? '') === $excludeId) {
                continue;
            }

            return $this->loadHistory((string) ($record['id'] ?? ''));
        }

        return null;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function persistRecord(
        string $kind,
        array $payload,
        ?string $latestPath,
        string $label,
        ?string $sourceHash,
    ): array {
        $this->layout->ensureDirectories();

        if ($latestPath !== null) {
            $this->persistJson($latestPath, $payload);
        }

        $id = $kind . '-' . substr(hash('sha256', Json::encode($this->normalizeForHash([
            'kind' => $kind,
            'label' => $label,
            'payload' => $payload,
            'source_hash' => $sourceHash,
        ]))), 0, 16);

        $existing = $this->findIndexRecord($id);
        $sequence = $existing !== null
            ? (int) ($existing['sequence'] ?? 0)
            : ((int) ($this->readIndex()['next_sequence'] ?? 1));

        $record = [
            'schema_version' => 1,
            'id' => $id,
            'kind' => $kind,
            'label' => $label,
            'source_hash' => $sourceHash,
            'sequence' => $sequence,
            'summary' => $this->historySummary($kind, $payload),
            'payload' => $payload,
        ];

        $this->persistJson($this->layout->historyRecordPath($id), $record);
        $this->updateIndex($record, $existing === null);

        return $record;
    }

    /**
     * @param array<string,mixed> $record
     */
    private function updateIndex(array $record, bool $isNew): void
    {
        $index = $this->readIndex();
        $rows = array_values(array_filter(
            array_values((array) ($index['records'] ?? [])),
            static fn(mixed $row): bool => is_array($row),
        ));

        $entry = [
            'id' => (string) ($record['id'] ?? ''),
            'kind' => (string) ($record['kind'] ?? ''),
            'label' => (string) ($record['label'] ?? ''),
            'sequence' => (int) ($record['sequence'] ?? 0),
            'source_hash' => $record['source_hash'] ?? null,
            'summary' => $record['summary'] ?? [],
            'path' => 'app/.foundry/build/history/' . (string) ($record['id'] ?? '') . '.json',
        ];

        $replaced = false;
        foreach ($rows as $indexKey => $row) {
            if ((string) ($row['id'] ?? '') !== (string) ($entry['id'] ?? '')) {
                continue;
            }

            $rows[$indexKey] = $entry;
            $replaced = true;
            break;
        }

        if (!$replaced) {
            $rows[] = $entry;
        }

        usort(
            $rows,
            static fn(array $a, array $b): int => ((int) ($a['sequence'] ?? 0)) <=> ((int) ($b['sequence'] ?? 0)),
        );

        $nextSequence = (int) ($index['next_sequence'] ?? 1);
        if ($isNew) {
            $nextSequence++;
        }

        $this->persistJson($this->layout->historyIndexPath(), [
            'schema_version' => 1,
            'next_sequence' => $nextSequence,
            'records' => $rows,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function readIndex(): array
    {
        $path = $this->layout->historyIndexPath();
        if (!is_file($path)) {
            return [
                'schema_version' => 1,
                'next_sequence' => 1,
                'records' => [],
            ];
        }

        $json = file_get_contents($path);
        if (!is_string($json) || $json === '') {
            return [
                'schema_version' => 1,
                'next_sequence' => 1,
                'records' => [],
            ];
        }

        return Json::decodeAssoc($json);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findIndexRecord(string $id): ?array
    {
        foreach ((array) ($this->readIndex()['records'] ?? []) as $record) {
            if (!is_array($record) || (string) ($record['id'] ?? '') !== $id) {
                continue;
            }

            return $record;
        }

        return null;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function historySummary(string $kind, array $payload): array
    {
        return match ($kind) {
            'build' => [
                'errors' => (int) (($payload['diagnostics_summary']['error'] ?? 0)),
                'warnings' => (int) (($payload['diagnostics_summary']['warning'] ?? 0)),
                'node_count' => array_sum(array_map('intval', (array) ($payload['node_counts'] ?? []))),
                'edge_count' => array_sum(array_map('intval', (array) ($payload['edge_counts'] ?? []))),
            ],
            'quality' => [
                'errors' => (int) (($payload['diagnostics_summary']['error'] ?? 0)),
                'warnings' => (int) (($payload['diagnostics_summary']['warning'] ?? 0)),
                'static_issues' => (int) (($payload['static_analysis']['summary']['total'] ?? 0)),
                'style_issues' => (int) (($payload['style_violations']['summary']['total'] ?? 0)),
            ],
            'trace' => [
                'execution_paths' => count((array) ($payload['execution_paths'] ?? [])),
                'guards' => (int) (($payload['summary']['guards'] ?? 0)),
                'interceptors' => (int) (($payload['summary']['interceptors'] ?? 0)),
            ],
            'profile' => [
                'compile_ms' => (float) (($payload['timings']['compile_ms'] ?? 0.0)),
                'peak_memory_bytes' => (int) (($payload['memory']['peak_bytes'] ?? 0)),
                'hotspots' => count((array) ($payload['hotspots'] ?? [])),
            ],
            'comparison' => [
                'regressions' => count((array) ($payload['regressions'] ?? [])),
                'changed_execution_paths' => count((array) ($payload['changed_execution_paths'] ?? [])),
            ],
            'generate' => [
                'mode' => (string) (($payload['intent']['mode'] ?? 'new')),
                'affected_files' => count((array) ($payload['plan']['affected_files'] ?? [])),
                'actions' => count((array) ($payload['actions_taken'] ?? [])),
                'dry_run' => (bool) (($payload['metadata']['dry_run'] ?? false)),
                'verification_ok' => (bool) (($payload['verification_results']['ok'] ?? false)),
                'git_available' => (bool) (($payload['git']['available'] ?? false)),
                'git_dirty' => (bool) (($payload['git']['before']['dirty'] ?? false)),
                'git_committed' => (bool) (($payload['git']['commit']['created'] ?? false)),
            ],
            default => [],
        };
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function persistJson(string $path, array $payload): void
    {
        $this->layout->ensureDirectories();
        file_put_contents($path, Json::encode($payload, true) . PHP_EOL);
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
}
