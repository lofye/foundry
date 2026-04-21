<?php

declare(strict_types=1);

namespace Foundry\Context;

trait SectionedMarkdownDocumentNormalizer
{
    /**
     * @param list<array{title:string,body:string}> $sections
     */
    protected function sectionBodyForTitle(array $sections, string $title): string
    {
        foreach ($sections as $section) {
            if ($section['title'] === $title) {
                return $section['body'];
            }
        }

        return '';
    }

    /**
     * @param list<array{title:string,body:string}> $sections
     */
    protected function renderDocument(string $preamble, array $sections): string
    {
        $blocks = [];

        if (trim($preamble) !== '') {
            $blocks[] = rtrim($preamble);
        }

        foreach ($sections as $section) {
            $body = rtrim($section['body']);
            $blocks[] = $body === ''
                ? '## ' . $section['title']
                : '## ' . $section['title'] . "\n\n" . $body;
        }

        return implode("\n\n", $blocks) . "\n";
    }

    /**
     * @return list<string>
     */
    protected function sectionItems(string $body): array
    {
        $items = [];
        $paragraph = [];

        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                if ($paragraph !== []) {
                    $items[] = trim(implode(' ', $paragraph));
                    $paragraph = [];
                }

                continue;
            }

            if (preg_match('/^(?:[-*]|\d+\.)\s+(.+)$/', $trimmed, $matches) === 1) {
                if ($paragraph !== []) {
                    $items[] = trim(implode(' ', $paragraph));
                    $paragraph = [];
                }

                $items[] = trim((string) $matches[1]);
                continue;
            }

            $paragraph[] = $trimmed;
        }

        if ($paragraph !== []) {
            $items[] = trim(implode(' ', $paragraph));
        }

        return array_values(array_filter(array_map(
            fn(string $item): string => $this->normalizeItemText($item),
            $items,
        ), static fn(string $item): bool => $item !== ''));
    }

    protected function normalizeItemText(string $item): string
    {
        $item = preg_replace('/\s+/', ' ', trim($item)) ?? trim($item);

        return trim($item);
    }

    protected function isPlaceholder(string $value): bool
    {
        $normalized = strtolower($this->normalizeItemText($value));
        $normalized = preg_replace('/[^a-z0-9.]+/', ' ', $normalized) ?? $normalized;
        $normalized = rtrim($normalized, '. ');

        return in_array(trim($normalized), ['tbd', 'none'], true);
    }

    protected function isPlaceholderOnlyBody(string $body): bool
    {
        $items = $this->sectionItems($body);
        if ($items === []) {
            return false;
        }

        foreach ($items as $item) {
            if (!$this->isPlaceholder($item)) {
                return false;
            }
        }

        return true;
    }
}
