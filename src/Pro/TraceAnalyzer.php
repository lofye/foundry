<?php

declare(strict_types=1);

namespace Foundry\Pro;

final class TraceAnalyzer
{
    /**
     * @return array<string,mixed>
     */
    public function analyze(string $path, ?string $target = null): array
    {
        $needle = trim((string) $target);

        if (!is_file($path)) {
            return [
                'found' => false,
                'path' => $path,
                'target' => $needle !== '' ? $needle : null,
                'total_events' => 0,
                'matched_events' => 0,
                'events' => [],
                'categories' => [],
            ];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
        $events = array_values(array_filter(
            array_map(static fn(string $line): string => trim($line), $lines),
            static fn(string $line): bool => $line !== '',
        ));

        $matched = $needle === ''
            ? $events
            : array_values(array_filter(
                $events,
                static fn(string $line): bool => stripos($line, $needle) !== false,
            ));

        $categories = [];
        foreach ($matched as $event) {
            $category = $this->category($event);
            $categories[$category] = ($categories[$category] ?? 0) + 1;
        }

        arsort($categories);

        return [
            'found' => true,
            'path' => $path,
            'target' => $needle !== '' ? $needle : null,
            'total_events' => count($events),
            'matched_events' => count($matched),
            'events' => array_slice($matched, -50),
            'categories' => $categories,
        ];
    }

    private function category(string $event): string
    {
        $firstToken = preg_split('/\s+/', trim($event), 2)[0] ?? '';
        $candidate = trim((string) $firstToken);
        if ($candidate === '') {
            return 'misc';
        }

        $candidate = explode(':', $candidate, 2)[0];
        $candidate = explode('-', $candidate, 2)[0];

        return $candidate !== '' ? $candidate : 'misc';
    }
}
