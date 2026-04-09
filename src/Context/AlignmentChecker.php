<?php

declare(strict_types=1);

namespace Foundry\Context;

final class AlignmentChecker
{
    private const array SPEC_SECTIONS = [
        'Expected Behavior',
        'Acceptance Criteria',
    ];

    private const array TRACKED_STATE_SECTIONS = [
        'Current State',
        'Open Questions',
        'Next Steps',
    ];

    private const array STOP_WORDS = [
        'a',
        'an',
        'and',
        'are',
        'as',
        'at',
        'be',
        'by',
        'for',
        'from',
        'in',
        'into',
        'is',
        'it',
        'of',
        'on',
        'or',
        'that',
        'the',
        'to',
        'will',
        'with',
        'without',
    ];

    public function check(string $specContents, string $stateContents, string $decisionsContents): AlignmentResult
    {
        $issues = [];

        $specItems = $this->specItems($specContents);
        $trackedStateItems = $this->stateItems($stateContents, self::TRACKED_STATE_SECTIONS);
        $currentStateItems = $this->stateItems($stateContents, ['Current State']);
        $decisionItems = $this->decisionItems($decisionsContents);

        foreach ($specItems as $specItem) {
            if ($this->matchesAnyItem((string) $specItem['text'], $trackedStateItems)) {
                continue;
            }

            $section = (string) $specItem['section'];
            $issues[] = new AlignmentIssue(
                code: 'untracked_spec_requirement',
                message: $section === 'Acceptance Criteria'
                    ? 'Acceptance criteria item is not reflected in Current State, Open Questions, or Next Steps.'
                    : 'Spec requirement is not reflected in Current State, Open Questions, or Next Steps.',
                spec_section: $section,
                state_section: null,
                decision_reference_found: $this->matchesAnyText((string) $specItem['text'], $decisionItems),
            );
        }

        foreach ($currentStateItems as $stateItem) {
            if ($this->matchesAnyItem((string) $stateItem['text'], $specItems)) {
                continue;
            }

            $decisionReferenceFound = $this->matchesAnyText((string) $stateItem['text'], $decisionItems);
            if ($decisionReferenceFound) {
                $issues[] = new AlignmentIssue(
                    code: 'possible_mismatch',
                    message: 'Current State claim is not grounded in the spec, but a decision entry may explain the divergence.',
                    spec_section: null,
                    state_section: 'Current State',
                    decision_reference_found: true,
                );

                continue;
            }

            $issues[] = new AlignmentIssue(
                code: 'unsupported_state_claim',
                message: 'Current State claim is not grounded in the spec or decision ledger.',
                spec_section: null,
                state_section: 'Current State',
                decision_reference_found: false,
            );
            $issues[] = new AlignmentIssue(
                code: 'missing_decision_reference',
                message: 'Divergence appears in Current State without a supporting decision entry.',
                spec_section: null,
                state_section: 'Current State',
                decision_reference_found: false,
            );
        }

        if ($issues === [] && $this->stateTrackingIsWeak($trackedStateItems)) {
            $issues[] = new AlignmentIssue(
                code: 'possible_mismatch',
                message: 'Current State, Open Questions, and Next Steps are empty or placeholder-only.',
                spec_section: null,
                state_section: null,
                decision_reference_found: false,
            );
        }

        return new AlignmentResult(
            status: $this->statusForIssues($issues),
            issues: $issues,
            required_actions: $this->requiredActionsForIssues($issues),
        );
    }

    /**
     * @return list<array{section:string,text:string}>
     */
    private function specItems(string $contents): array
    {
        $items = [];

        foreach (self::SPEC_SECTIONS as $section) {
            foreach ($this->sectionItems($contents, $section) as $item) {
                if ($this->isPlaceholder($item)) {
                    continue;
                }

                $items[] = [
                    'section' => $section,
                    'text' => $item,
                ];
            }
        }

        return $items;
    }

    /**
     * @param array<int,string> $sections
     * @return list<array{section:string,text:string}>
     */
    private function stateItems(string $contents, array $sections): array
    {
        $items = [];

        foreach ($sections as $section) {
            foreach ($this->sectionItems($contents, $section) as $item) {
                if ($this->isPlaceholder($item)) {
                    continue;
                }

                $items[] = [
                    'section' => $section,
                    'text' => $item,
                ];
            }
        }

        return $items;
    }

    /**
     * @return list<string>
     */
    private function decisionItems(string $contents): array
    {
        if (trim($contents) === '') {
            return [];
        }

        $matchCount = preg_match_all('/^### Decision:.*?(?=^### Decision:|\z)/ms', $contents, $matches);
        if ($matchCount === false || $matchCount === 0) {
            return [];
        }

        $items = [];
        foreach ($matches[0] as $entry) {
            $text = preg_replace('/^### Decision:\s*/m', '', $entry);
            $text = is_string($text) ? preg_replace('/^Timestamp:\s*.*$/m', '', $text) : null;
            $text = is_string($text) ? preg_replace('/^\*\*[^*]+\*\*\s*$/m', '', $text) : null;
            $text = is_string($text) ? trim($text) : '';

            if ($text === '' || $this->isPlaceholder($text)) {
                continue;
            }

            $items[] = $text;
        }

        return $items;
    }

    /**
     * @return list<string>
     */
    private function sectionItems(string $contents, string $section): array
    {
        $body = $this->sectionBody($contents, $section);
        if ($body === null || trim($body) === '') {
            return [];
        }

        $items = [];
        $paragraph = [];

        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                $this->flushParagraph($items, $paragraph);

                continue;
            }

            if (preg_match('/^(?:[-*]|\d+\.)\s+(.+)$/', $trimmed, $matches) === 1) {
                $this->flushParagraph($items, $paragraph);
                $items[] = trim($matches[1]);

                continue;
            }

            $paragraph[] = $trimmed;
        }

        $this->flushParagraph($items, $paragraph);

        return array_values(array_filter(
            array_map(static fn(string $item): string => trim($item), $items),
            static fn(string $item): bool => $item !== '',
        ));
    }

    private function sectionBody(string $contents, string $section): ?string
    {
        $pattern = '/^## ' . preg_quote($section, '/') . '\s*$\R(.*?)(?=^## |\z)/ms';
        if (preg_match($pattern, $contents, $matches) !== 1) {
            return null;
        }

        return rtrim($matches[1]);
    }

    /**
     * @param list<string> $items
     * @param list<string> $paragraph
     */
    private function flushParagraph(array &$items, array &$paragraph): void
    {
        if ($paragraph !== []) {
            $items[] = implode(' ', $paragraph);
            $paragraph = [];
        }
    }

    private function isPlaceholder(string $text): bool
    {
        return in_array($this->normalize($text), ['tbd', 'title', 'iso 8601'], true);
    }

    /**
     * @param list<array{section:string,text:string}> $candidates
     */
    private function matchesAnyItem(string $text, array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            if ($this->textsMatch($text, (string) $candidate['text'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $candidates
     */
    private function matchesAnyText(string $text, array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            if ($this->textsMatch($text, $candidate)) {
                return true;
            }
        }

        return false;
    }

    private function textsMatch(string $left, string $right): bool
    {
        $leftNormalized = $this->normalize($left);
        $rightNormalized = $this->normalize($right);

        if ($leftNormalized === '' || $rightNormalized === '') {
            return false;
        }

        if ($leftNormalized === $rightNormalized) {
            return true;
        }

        if (str_contains($leftNormalized, $rightNormalized) || str_contains($rightNormalized, $leftNormalized)) {
            return true;
        }

        $leftTokens = $this->significantTokens($leftNormalized);
        $rightTokens = $this->significantTokens($rightNormalized);

        if ($leftTokens === [] || $rightTokens === []) {
            return false;
        }

        $shorter = count($leftTokens) <= count($rightTokens) ? $leftTokens : $rightTokens;
        $longer = count($leftTokens) <= count($rightTokens) ? $rightTokens : $leftTokens;

        return array_diff($shorter, $longer) === [];
    }

    private function normalize(string $text): string
    {
        $normalized = strtolower($text);
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? '';

        return trim(preg_replace('/\s+/', ' ', $normalized) ?? '');
    }

    /**
     * @return list<string>
     */
    private function significantTokens(string $normalized): array
    {
        $tokens = array_values(array_filter(
            explode(' ', $normalized),
            static fn(string $token): bool => $token !== '' && !in_array($token, self::STOP_WORDS, true),
        ));

        return array_values(array_unique($tokens));
    }

    /**
     * @param list<array{section:string,text:string}> $trackedStateItems
     */
    private function stateTrackingIsWeak(array $trackedStateItems): bool
    {
        return $trackedStateItems === [];
    }

    /**
     * @param array<int,AlignmentIssue> $issues
     */
    private function statusForIssues(array $issues): string
    {
        foreach ($issues as $issue) {
            if (in_array($issue->code, ['unsupported_state_claim', 'untracked_spec_requirement', 'missing_decision_reference', 'mismatch'], true)) {
                return 'mismatch';
            }
        }

        return $issues === [] ? 'ok' : 'warning';
    }

    /**
     * @param array<int,AlignmentIssue> $issues
     * @return list<string>
     */
    private function requiredActionsForIssues(array $issues): array
    {
        $actions = [];

        foreach ($issues as $issue) {
            $action = match ($issue->code) {
                'untracked_spec_requirement' => 'Reflect the spec requirement in Current State, Open Questions, or Next Steps.',
                'unsupported_state_claim' => 'Update the feature state to reflect current implementation or remove unsupported state claims.',
                'missing_decision_reference' => 'Log divergence in the decision ledger.',
                'possible_mismatch' => $issue->decision_reference_found
                    ? 'Update the spec to reflect the decision-backed behavior if it is now intended behavior.'
                    : 'Update the feature state to reflect current implementation.',
                'mismatch' => 'Repair the feature context files before checking alignment.',
                default => null,
            };

            if ($action === null || in_array($action, $actions, true)) {
                continue;
            }

            $actions[] = $action;
        }

        return $actions;
    }
}
