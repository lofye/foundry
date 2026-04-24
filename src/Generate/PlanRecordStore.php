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
        $records = [];

        foreach ($this->recordPaths() as $path) {
            $records[] = $this->loadRecord($path);
        }

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

        foreach ($this->recordPaths() as $path) {
            $record = $this->loadRecord($path);
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

        return $record;
    }

    /**
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private function summary(array $record): array
    {
        return [
            'plan_id' => (string) ($record['plan_id'] ?? ''),
            'timestamp' => (string) ($record['timestamp'] ?? ''),
            'intent' => (string) ($record['intent'] ?? ''),
            'mode' => (string) ($record['mode'] ?? ''),
            'status' => (string) ($record['status'] ?? ''),
            'risk_level' => $record['risk_level'] ?? null,
            'interactive' => (bool) (($record['interactive']['enabled'] ?? false) === true),
            'affected_files' => count((array) ($record['affected_files'] ?? [])),
            'storage_path' => (string) ($record['storage_path'] ?? ''),
        ];
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
