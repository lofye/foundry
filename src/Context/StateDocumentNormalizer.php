<?php

declare(strict_types=1);

namespace Foundry\Context;

use Foundry\Support\FoundryError;

final class StateDocumentNormalizer
{
    use SectionedMarkdownDocumentNormalizer;

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
        $currentStateItems = $this->normalizeCanonicalSection(
            'Current State',
            $this->sectionBodyForTitle($sections, 'Current State'),
            [],
        );
        $normalizedCanonical = [];

        foreach ($sections as $section) {
            if (!in_array($section['title'], self::CANONICAL_SECTION_ORDER, true)) {
                continue;
            }

            $normalizedCanonical[$section['title']] = $this->normalizeCanonicalSection(
                $section['title'],
                $section['body'],
                $currentStateItems,
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
