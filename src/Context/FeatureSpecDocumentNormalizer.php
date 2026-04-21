<?php

declare(strict_types=1);

namespace Foundry\Context;

use Foundry\Support\FoundryError;

final class FeatureSpecDocumentNormalizer
{
    use SectionedMarkdownDocumentNormalizer;

    /**
     * @var list<string>
     */
    private const array CANONICAL_SECTION_ORDER = [
        'Purpose',
        'Goals',
        'Non-Goals',
        'Constraints',
        'Expected Behavior',
        'Acceptance Criteria',
        'Assumptions',
    ];

    /**
     * @var list<string>
     */
    private const array BULLET_SECTIONS = [
        'Goals',
        'Non-Goals',
        'Constraints',
        'Expected Behavior',
        'Acceptance Criteria',
        'Assumptions',
    ];

    public function normalize(string $contents): string
    {
        $contents = str_replace("\r\n", "\n", $contents);

        if (trim($contents) === '') {
            throw new FoundryError(
                'CONTEXT_SPEC_NORMALIZATION_INPUT_INVALID',
                'validation',
                [],
                'Feature spec normalization requires non-empty feature spec content.',
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
     * @return array{items:list<string>,bullet_section:bool}
     */
    private function normalizeCanonicalSection(string $title, string $body): array
    {
        return [
            'items' => $this->deduplicatedItems($this->sectionItems($body)),
            'bullet_section' => in_array($title, self::BULLET_SECTIONS, true),
        ];
    }

    /**
     * @param array{items:list<string>,bullet_section:bool} $normalizedSection
     */
    private function canonicalBody(array $normalizedSection, string $originalBody): string
    {
        $items = $normalizedSection['items'];
        $bulletSection = $normalizedSection['bullet_section'];

        if ($items === []) {
            if (!$this->isPlaceholderOnlyBody($originalBody)) {
                return '';
            }

            return $bulletSection ? '- TBD.' : 'TBD.';
        }

        if (!$bulletSection && count($items) === 1) {
            return $items[0];
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
                'CONTEXT_SPEC_NORMALIZATION_INPUT_INVALID',
                'validation',
                [],
                'Feature spec normalization requires at least one level-two section.',
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
            $key = $this->normalizeItemText($item);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $deduplicated[] = $item;
        }

        return $deduplicated;
    }
}
