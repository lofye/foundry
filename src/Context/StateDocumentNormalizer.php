<?php

declare(strict_types=1);

namespace Foundry\Context;

use Foundry\Support\FoundryError;

final class StateDocumentNormalizer
{
    /**
     * @var list<string>
     */
    private const array CANONICAL_SECTION_ORDER = [
        'Current State',
        'Open Questions',
        'Next Steps',
    ];

    public function normalize(string $contents): string
    {
        $contents = str_replace("\r\n", "\n", $contents);

        if (trim($contents) === '') {
            throw new FoundryError(
                'CONTEXT_STATE_NORMALIZATION_INPUT_INVALID',
                'validation',
                [],
                'State document normalization requires non-empty state document content.',
            );
        }

        $document = $this->parseDocument($contents);

        return $this->renderDocument(
            $document['preamble'],
            $this->normalizedSections($document['sections']),
        );
    }

    /**
     * @param list<array{title:string,body:string}> $sections
     * @return list<array{title:string,body:string}>
     */
    private function normalizedSections(array $sections): array
    {
        $normalizedCanonical = [];

        foreach ($sections as $section) {
            if (!in_array($section['title'], self::CANONICAL_SECTION_ORDER, true)) {
                continue;
            }

            $normalizedCanonical[$section['title']] = $this->normalizeCanonicalSection(
                $section['title'],
                $section['body'],
                $normalizedCanonical['Current State'] ?? [],
            );
        }

        $ordered = [];
        $canonicalInserted = false;

        foreach ($sections as $section) {
            if (in_array($section['title'], self::CANONICAL_SECTION_ORDER, true)) {
                if (!$canonicalInserted) {
                    foreach (self::CANONICAL_SECTION_ORDER as $canonicalTitle) {
                        if (!array_key_exists($canonicalTitle, $normalizedCanonical)) {
                            continue;
                        }

                        $ordered[] = [
                            'title' => $canonicalTitle,
                            'body' => $this->canonicalBody(
                                $normalizedCanonical[$canonicalTitle],
                                $this->sectionBodyForTitle($sections, $canonicalTitle),
                            ),
                        ];
                    }

                    $canonicalInserted = true;
                }

                continue;
            }

            $ordered[] = [
                'title' => $section['title'],
                'body' => rtrim($section['body']),
            ];
        }

        if (!$canonicalInserted) {
            foreach (self::CANONICAL_SECTION_ORDER as $canonicalTitle) {
                if (!array_key_exists($canonicalTitle, $normalizedCanonical)) {
                    continue;
                }

                $ordered[] = [
                    'title' => $canonicalTitle,
                    'body' => $this->canonicalBody(
                        $normalizedCanonical[$canonicalTitle],
                        $this->sectionBodyForTitle($sections, $canonicalTitle),
                    ),
                ];
            }
        }

        return $ordered;
    }

    /**
     * @param list<string> $currentStateItems
     * @return list<string>
     */
    private function normalizeCanonicalSection(string $title, string $body, array $currentStateItems): array
    {
        $items = $this->deduplicatedItems($this->sectionItems($body));

        return match ($title) {
            'Current State' => array_values(array_filter(
                $items,
                fn(string $item): bool => !$this->isObviousCurrentStateNoise($item),
            )),
            'Open Questions' => $items,
            'Next Steps' => array_values(array_filter(
                $items,
                fn(string $item): bool => !$this->isObviousCompletedNextStep($item, $currentStateItems),
            )),
            default => $items,
        };
    }

    /**
     * @param list<string> $items
     */
    private function canonicalBody(array $items, string $originalBody): string
    {
        if ($items === []) {
            return $this->isPlaceholderOnlyBody($originalBody) ? '- TBD.' : '';
        }

        return implode("\n", array_map(
            static fn(string $item): string => '- ' . $item,
            $items,
        ));
    }

    /**
     * @return array{preamble:string,sections:list<array{title:string,body:string}>}
     */
    private function parseDocument(string $contents): array
    {
        $matchCount = preg_match_all('/^## (.+?)\s*$/m', $contents, $matches, PREG_OFFSET_CAPTURE);
        if ($matchCount === false || $matchCount === 0) {
            throw new FoundryError(
                'CONTEXT_STATE_NORMALIZATION_INPUT_INVALID',
                'validation',
                [],
                'State document normalization requires at least one level-two section.',
            );
        }

        $sections = [];
        $count = count($matches[0]);

        for ($index = 0; $index < $count; $index++) {
            $headingOffset = $matches[0][$index][1];
            $title = trim((string) $matches[1][$index][0]);
            $bodyStart = $headingOffset + strlen((string) $matches[0][$index][0]);
            $bodyEnd = $matches[0][$index + 1][1] ?? strlen($contents);
            $body = trim(substr($contents, $bodyStart, $bodyEnd - $bodyStart), "\n");

            $sections[] = [
                'title' => $title,
                'body' => $body,
            ];
        }

        return [
            'preamble' => rtrim(substr($contents, 0, $matches[0][0][1])),
            'sections' => $sections,
        ];
    }

    /**
     * @param list<array{title:string,body:string}> $sections
     */
    private function sectionBodyForTitle(array $sections, string $title): string
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
    private function renderDocument(string $preamble, array $sections): string
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
    private function sectionItems(string $body): array
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

    private function normalizeItemText(string $item): string
    {
        $item = preg_replace('/\s+/', ' ', trim($item)) ?? trim($item);

        return trim($item);
    }

    /**
     * @param list<string> $items
     * @return list<string>
     */
    private function deduplicatedItems(array $items): array
    {
        $seen = [];
        $deduplicated = [];

        foreach ($items as $item) {
            $key = $this->comparisonKey($item);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $deduplicated[] = $item;
        }

        return $deduplicated;
    }

    /**
     * @param list<string> $currentStateItems
     */
    private function isObviousCompletedNextStep(string $item, array $currentStateItems): bool
    {
        if ($this->isObviousCurrentStateNoise($item)) {
            return true;
        }

        $normalized = $this->normalizedSemanticKey($item);

        if (str_starts_with($normalized, 'implemented ')) {
            return true;
        }

        if ($normalized === 'current state reflects the completed bounded work') {
            return true;
        }

        foreach ($currentStateItems as $currentStateItem) {
            $itemKey = $this->comparisonKey($item);
            if ($this->comparisonKey($currentStateItem) === $itemKey) {
                return true;
            }

            if ($this->comparisonKey($this->stripImplementedPrefix($currentStateItem)) === $itemKey) {
                return true;
            }
        }

        return false;
    }

    private function isObviousCurrentStateNoise(string $item): bool
    {
        $normalized = $this->normalizedSemanticKey($item);

        if (in_array($normalized, [
            'feature spec created',
            'feature state document created',
            'decision ledger created',
        ], true)) {
            return true;
        }

        return preg_match('/^[a-z0-9.]+ implementation completed$/', $normalized) === 1;
    }

    private function isPlaceholderOnlyBody(string $body): bool
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

    private function isPlaceholder(string $value): bool
    {
        return in_array($this->normalizedSemanticKey($value), ['tbd', 'none'], true);
    }

    private function comparisonKey(string $value): string
    {
        return strtolower($this->normalizeItemText($value));
    }

    private function normalizedSemanticKey(string $value): string
    {
        $normalized = strtolower($this->normalizeItemText($value));
        $normalized = preg_replace('/[^a-z0-9.]+/', ' ', $normalized) ?? $normalized;
        $normalized = rtrim($normalized, '. ');

        return trim($normalized);
    }

    private function stripImplementedPrefix(string $value): string
    {
        return preg_replace('/^implemented\s+/i', '', $this->normalizeItemText($value)) ?? $this->normalizeItemText($value);
    }
}
