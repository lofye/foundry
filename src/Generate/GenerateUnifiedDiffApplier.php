<?php

declare(strict_types=1);

namespace Foundry\Generate;

use Foundry\Support\FoundryError;

final class GenerateUnifiedDiffApplier
{
    public function reverse(?string $currentAfter, string $patch, bool $beforeExists): ?string
    {
        $lines = $this->patchLines($patch);
        if ($lines === []) {
            return $currentAfter;
        }

        if (($lines[2] ?? '') === '@@ no textual changes @@') {
            return $currentAfter;
        }

        $operations = array_slice($lines, 3);
        $afterLines = $this->contentLines($currentAfter);
        $cursor = 0;
        $result = [];

        foreach ($operations as $operation) {
            if ($operation === '') {
                continue;
            }

            $prefix = $operation[0];
            $text = substr($operation, 1);

            if ($prefix === ' ') {
                if (($afterLines[$cursor] ?? null) !== $text) {
                    throw new FoundryError(
                        'PLAN_UNDO_PATCH_CONTEXT_MISMATCH',
                        'validation',
                        ['line' => $text],
                        'Persisted rollback patch no longer matches the current file contents.',
                    );
                }

                $result[] = $text;
                $cursor++;

                continue;
            }

            if ($prefix === '+') {
                if (($afterLines[$cursor] ?? null) !== $text) {
                    throw new FoundryError(
                        'PLAN_UNDO_PATCH_CONTEXT_MISMATCH',
                        'validation',
                        ['line' => $text],
                        'Persisted rollback patch no longer matches the current file contents.',
                    );
                }

                $cursor++;

                continue;
            }

            if ($prefix === '-') {
                $result[] = $text;
                continue;
            }
        }

        if ($cursor !== count($afterLines)) {
            throw new FoundryError(
                'PLAN_UNDO_PATCH_CONTEXT_MISMATCH',
                'validation',
                ['remaining_lines' => array_slice($afterLines, $cursor)],
                'Persisted rollback patch no longer matches the current file contents.',
            );
        }

        if ($result === []) {
            return $beforeExists ? '' : null;
        }

        return implode(PHP_EOL, $result) . PHP_EOL;
    }

    /**
     * @return list<string>
     */
    private function patchLines(string $patch): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $patch);
        $trimmed = rtrim($normalized, "\n");
        if ($trimmed === '') {
            return [];
        }

        return explode("\n", $trimmed);
    }

    /**
     * @return list<string>
     */
    private function contentLines(?string $content): array
    {
        if ($content === null || $content === '') {
            return [];
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $content);
        $trimmed = rtrim($normalized, "\n");
        if ($trimmed === '') {
            return [];
        }

        return explode("\n", $trimmed);
    }
}
