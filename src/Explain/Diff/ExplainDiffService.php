<?php

declare(strict_types=1);

namespace Foundry\Explain\Diff;

use Foundry\Confidence\ConfidenceEngine;
use Foundry\Explain\Snapshot\ExplainSnapshotService;
use Foundry\Support\FoundryError;
use Foundry\Support\Json;
use Foundry\Support\Paths;

final class ExplainDiffService
{
    public function __construct(
        private readonly Paths $paths,
        private readonly ExplainSnapshotService $snapshots,
        private readonly ConfidenceEngine $confidenceEngine = new ConfidenceEngine(),
    ) {}

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @return array<string,mixed>
     */
    public function compare(array $before, array $after): array
    {
        $this->assertCompatibleSnapshots($before, $after);

        $beforeItems = $this->flattenSnapshot($before);
        $afterItems = $this->flattenSnapshot($after);
        $added = [];
        $removed = [];
        $modified = [];

        foreach ($afterItems as $key => $row) {
            if (!array_key_exists($key, $beforeItems)) {
                $added[] = $this->diffRow($row);
                continue;
            }

            if ($beforeItems[$key] !== $row) {
                $modified[] = $this->diffRow($row) + [
                    'before' => $beforeItems[$key],
                    'after' => $row,
                ];
            }
        }

        foreach ($beforeItems as $key => $row) {
            if (!array_key_exists($key, $afterItems)) {
                $removed[] = $this->diffRow($row);
            }
        }

        $added = $this->sortDiffRows($added);
        $removed = $this->sortDiffRows($removed);
        $modified = $this->sortDiffRows($modified);

        $diff = [
            'schema_version' => 1,
            'metadata' => [
                'pre_snapshot' => [
                    'label' => $before['label'] ?? 'pre-generate',
                    'schema_version' => $before['schema_version'] ?? null,
                    'explain_schema_version' => $before['metadata']['explain_schema_version'] ?? null,
                    'graph_fingerprint' => $before['metadata']['graph_fingerprint'] ?? null,
                ],
                'post_snapshot' => [
                    'label' => $after['label'] ?? 'post-generate',
                    'schema_version' => $after['schema_version'] ?? null,
                    'explain_schema_version' => $after['metadata']['explain_schema_version'] ?? null,
                    'graph_fingerprint' => $after['metadata']['graph_fingerprint'] ?? null,
                ],
            ],
            'summary' => [
                'added' => count($added),
                'removed' => count($removed),
                'modified' => count($modified),
            ],
            'added' => $added,
            'removed' => $removed,
            'modified' => $modified,
        ];

        $diff['confidence'] = $this->confidenceEngine->diff($before, $after, $diff);

        return $diff;
    }

    /**
     * @return array<string,mixed>
     */
    public function loadLast(): array
    {
        $path = $this->lastDiffPath();
        if (!is_file($path)) {
            throw new FoundryError(
                'EXPLAIN_DIFF_NOT_FOUND',
                'not_found',
                ['path' => $path],
                'No architectural diff is available yet. Run `foundry generate` first.',
            );
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new FoundryError(
                'EXPLAIN_DIFF_UNREADABLE',
                'io',
                ['path' => $path],
                'Architectural diff could not be read.',
            );
        }

        try {
            $diff = Json::decodeAssoc($content);
        } catch (FoundryError $error) {
            throw new FoundryError(
                'EXPLAIN_DIFF_CORRUPT',
                'parsing',
                ['path' => $path],
                'Architectural diff is corrupt.',
                0,
                $error,
            );
        }

        if (!is_int($diff['schema_version'] ?? null) || !is_array($diff['summary'] ?? null)) {
            throw new FoundryError(
                'EXPLAIN_DIFF_INVALID',
                'validation',
                ['path' => $path],
                'Architectural diff is invalid.',
            );
        }

        $this->assertCompatibleSnapshots(
            $this->snapshots->load('pre-generate'),
            $this->snapshots->load('post-generate'),
        );

        return $diff;
    }

    /**
     * @param array<string,mixed> $diff
     */
    public function storeLast(array $diff): void
    {
        $path = $this->lastDiffPath();
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($path, Json::encode($diff, true));
    }

    public function lastDiffPath(): string
    {
        return $this->paths->join('.foundry/diffs/last.json');
    }

    /**
     * @param array<string,mixed> $diff
     */
    public function render(array $diff): string
    {
        $lines = ['Changes since last generation:', ''];
        $confidence = is_array($diff['confidence'] ?? null) ? $diff['confidence'] : [];
        if ($confidence !== []) {
            $lines[] = sprintf(
                'Confidence: %s (%.2f)',
                str_replace('_', ' ', (string) ($confidence['band'] ?? 'unknown')),
                (float) ($confidence['score'] ?? 0.0),
            );
            $warnings = array_values(array_filter(array_map('strval', (array) ($confidence['warnings'] ?? []))));
            if ($warnings !== [] && in_array((string) ($confidence['band'] ?? ''), ['medium', 'low', 'very_low'], true)) {
                $lines[] = 'Note: ' . $warnings[0];
            }
            $lines[] = '';
        }

        foreach (['added' => 'Added', 'modified' => 'Modified', 'removed' => 'Removed'] as $key => $title) {
            $lines[] = $title . ':';
            $items = array_values(array_filter((array) ($diff[$key] ?? []), 'is_array'));
            if ($items === []) {
                $lines[] = '- none';
                $lines[] = '';
                continue;
            }

            foreach ($items as $item) {
                $lines[] = '- ' . $this->itemLabel($item);
            }
            $lines[] = '';
        }

        return rtrim(implode(PHP_EOL, $lines));
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    private function assertCompatibleSnapshots(array $before, array $after): void
    {
        $snapshotVersionBefore = (int) ($before['schema_version'] ?? 0);
        $snapshotVersionAfter = (int) ($after['schema_version'] ?? 0);
        $explainVersionBefore = (int) ($before['metadata']['explain_schema_version'] ?? 0);
        $explainVersionAfter = (int) ($after['metadata']['explain_schema_version'] ?? 0);

        if ($snapshotVersionBefore !== $snapshotVersionAfter || $explainVersionBefore !== $explainVersionAfter) {
            throw new FoundryError(
                'EXPLAIN_DIFF_SNAPSHOT_INCOMPATIBLE',
                'validation',
                [
                    'pre_snapshot' => [
                        'schema_version' => $snapshotVersionBefore,
                        'explain_schema_version' => $explainVersionBefore,
                    ],
                    'post_snapshot' => [
                        'schema_version' => $snapshotVersionAfter,
                        'explain_schema_version' => $explainVersionAfter,
                    ],
                ],
                'Unable to compute architectural diff: snapshot versions are incompatible.',
            );
        }
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array<string,array<string,mixed>>
     */
    private function flattenSnapshot(array $snapshot): array
    {
        $flattened = [];
        foreach ((array) ($snapshot['categories'] ?? []) as $category => $items) {
            if (!is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $type = (string) ($item['type'] ?? $this->categoryType((string) $category));
                $id = trim((string) ($item['id'] ?? ''));
                if ($id === '') {
                    continue;
                }

                $row = $item;
                $row['type'] = $type;
                $flattened[$type . ':' . $id] = $row;
            }
        }

        ksort($flattened);

        return $flattened;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function diffRow(array $row): array
    {
        return [
            'type' => (string) ($row['type'] ?? 'unknown'),
            'id' => (string) ($row['id'] ?? ''),
            'label' => (string) ($row['label'] ?? ($row['id'] ?? '')),
            'origin' => (string) ($row['origin'] ?? 'core'),
            'extension' => $row['extension'] ?? null,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function sortDiffRows(array $rows): array
    {
        usort($rows, static fn(array $left, array $right): int => strcmp((string) ($left['origin'] ?? 'core'), (string) ($right['origin'] ?? 'core'))
            ?: strcmp((string) ($left['extension'] ?? ''), (string) ($right['extension'] ?? ''))
            ?: strcmp((string) ($left['type'] ?? ''), (string) ($right['type'] ?? ''))
            ?: strcmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''))
            ?: strcmp((string) ($left['id'] ?? ''), (string) ($right['id'] ?? '')));

        return array_values($rows);
    }

    /**
     * @param array<string,mixed> $item
     */
    private function itemLabel(array $item): string
    {
        $label = trim((string) ($item['label'] ?? $item['id'] ?? ''));
        $extension = trim((string) ($item['extension'] ?? ''));

        if ($extension !== '' && $extension !== $label) {
            return $label . ' [' . $extension . ']';
        }

        return $label;
    }

    private function categoryType(string $category): string
    {
        return match ($category) {
            'graph_nodes' => 'graph_node',
            'graph_edges' => 'graph_edge',
            default => rtrim($category, 's'),
        };
    }
}
