<?php

declare(strict_types=1);

namespace Foundry\Compiler\Codemod;

use Foundry\Support\Paths;
use Foundry\Support\Yaml;

final class PlatformDefinitionNormalizeCodemod implements Codemod
{
    public function id(): string
    {
        return 'platform-definition-v1-normalize';
    }

    public function description(): string
    {
        return 'Normalize platform definitions by enforcing version: 1 and canonical key ordering.';
    }

    public function sourceType(): string
    {
        return 'platform_definition';
    }

    public function run(Paths $paths, bool $write = false, ?string $path = null): CodemodResult
    {
        $changes = [];
        $diagnostics = [];

        foreach ($this->definitionPaths($paths, $path) as $absolute) {
            $relative = $this->relativePath($paths, $absolute);
            try {
                $document = Yaml::parseFile($absolute);
            } catch (\Throwable $error) {
                $diagnostics[] = [
                    'code' => 'FDY2485_PLATFORM_DEFINITION_PARSE_ERROR',
                    'severity' => 'error',
                    'category' => 'migrations',
                    'message' => $error->getMessage(),
                    'source_path' => $relative,
                ];
                continue;
            }

            $normalized = $this->normalizeDocument($document);
            if ($normalized === $document) {
                continue;
            }

            $changes[] = [
                'path' => $relative,
                'from_version' => (int) ($document['version'] ?? 0),
                'to_version' => (int) ($normalized['version'] ?? 1),
                'format' => $this->formatForPath($relative),
                'write' => $write,
            ];

            if ($write) {
                file_put_contents($absolute, Yaml::dump($normalized));
            }
        }

        return new CodemodResult(
            codemod: $this->id(),
            written: $write,
            changes: $changes,
            diagnostics: $diagnostics,
            pathFilter: $path,
        );
    }

    /**
     * @return array<int,string>
     */
    private function definitionPaths(Paths $paths, ?string $path): array
    {
        if ($path !== null && $path !== '') {
            $candidate = str_starts_with($path, $paths->root() . '/') ? $path : $paths->join($path);

            return is_file($candidate) ? [$candidate] : [];
        }

        $files = array_merge(
            glob($paths->join('app/definitions/billing/*.billing.yaml')) ?: [],
            glob($paths->join('app/definitions/workflows/*.workflow.yaml')) ?: [],
            glob($paths->join('app/definitions/orchestrations/*.orchestration.yaml')) ?: [],
            glob($paths->join('app/definitions/search/*.search.yaml')) ?: [],
            glob($paths->join('app/definitions/streams/*.stream.yaml')) ?: [],
            glob($paths->join('app/definitions/locales/*.locale.yaml')) ?: [],
            glob($paths->join('app/definitions/roles/*.roles.yaml')) ?: [],
            glob($paths->join('app/definitions/policies/*.policy.yaml')) ?: [],
            glob($paths->join('app/definitions/inspect-ui/*.inspect-ui.yaml')) ?: [],
        );
        sort($files);

        return $files;
    }

    /**
     * @param array<string,mixed> $document
     * @return array<string,mixed>
     */
    private function normalizeDocument(array $document): array
    {
        $normalized = $this->normalizeValue($document);
        if (!array_key_exists('version', $normalized)) {
            $normalized = ['version' => 1] + $normalized;
        }

        return $normalized;
    }

    /**
     * @return mixed
     */
    private function normalizeValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            $rows = [];
            foreach ($value as $item) {
                $rows[] = $this->normalizeValue($item);
            }

            return $rows;
        }

        $normalized = [];
        $keys = array_keys($value);
        $keys = array_values(array_map('strval', $keys));
        sort($keys);

        foreach ($keys as $key) {
            $normalized[$key] = $this->normalizeValue($value[$key]);
        }

        return $normalized;
    }

    private function relativePath(Paths $paths, string $absolute): string
    {
        $root = rtrim($paths->root(), '/') . '/';

        return str_starts_with($absolute, $root)
            ? substr($absolute, strlen($root))
            : $absolute;
    }

    private function formatForPath(string $path): string
    {
        return match (true) {
            str_ends_with($path, '.billing.yaml') => 'billing_definition',
            str_ends_with($path, '.workflow.yaml') => 'workflow_definition',
            str_ends_with($path, '.orchestration.yaml') => 'orchestration_definition',
            str_ends_with($path, '.search.yaml') => 'search_definition',
            str_ends_with($path, '.stream.yaml') => 'stream_definition',
            str_ends_with($path, '.locale.yaml') => 'locale_definition',
            str_ends_with($path, '.roles.yaml') => 'roles_definition',
            str_ends_with($path, '.policy.yaml') => 'policy_definition',
            str_ends_with($path, '.inspect-ui.yaml') => 'inspect_ui_definition',
            default => 'platform_definition',
        };
    }
}
