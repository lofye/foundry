<?php

declare(strict_types=1);

namespace Foundry\Generate;

final class GenerateUnifiedDiffRenderer
{
    public function render(string $path, ?string $before, ?string $after): string
    {
        $beforeLines = $this->lines($before);
        $afterLines = $this->lines($after);

        $lines = [
            '--- ' . ($before === null ? '/dev/null' : 'a/' . $path),
            '+++ ' . ($after === null ? '/dev/null' : 'b/' . $path),
        ];

        if ($beforeLines === $afterLines) {
            $lines[] = '@@ no textual changes @@';

            return implode(PHP_EOL, $lines) . PHP_EOL;
        }

        $lines[] = sprintf('@@ -1,%d +1,%d @@', count($beforeLines), count($afterLines));

        foreach ($this->operations($beforeLines, $afterLines) as $operation) {
            $prefix = match ($operation['type']) {
                'add' => '+',
                'remove' => '-',
                default => ' ',
            };

            $lines[] = $prefix . $operation['line'];
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * @return list<string>
     */
    private function lines(?string $content): array
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

    /**
     * @param list<string> $before
     * @param list<string> $after
     * @return list<array{type:string,line:string}>
     */
    private function operations(array $before, array $after): array
    {
        $m = count($before);
        $n = count($after);
        $lcs = array_fill(0, $m + 1, array_fill(0, $n + 1, 0));

        for ($i = $m - 1; $i >= 0; $i--) {
            for ($j = $n - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $before[$i] === $after[$j]
                    ? $lcs[$i + 1][$j + 1] + 1
                    : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        $operations = [];
        $i = 0;
        $j = 0;

        while ($i < $m && $j < $n) {
            if ($before[$i] === $after[$j]) {
                $operations[] = ['type' => 'equal', 'line' => $before[$i]];
                $i++;
                $j++;

                continue;
            }

            if ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $operations[] = ['type' => 'remove', 'line' => $before[$i]];
                $i++;

                continue;
            }

            $operations[] = ['type' => 'add', 'line' => $after[$j]];
            $j++;
        }

        while ($i < $m) {
            $operations[] = ['type' => 'remove', 'line' => $before[$i]];
            $i++;
        }

        while ($j < $n) {
            $operations[] = ['type' => 'add', 'line' => $after[$j]];
            $j++;
        }

        return $operations;
    }
}
