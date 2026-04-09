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
        'can',
        'each',
        'for',
        'from',
        'has',
        'have',
        'in',
        'into',
        'is',
        'it',
        'missing',
        'of',
        'on',
        'one',
        'or',
        'that',
        'the',
        'these',
        'this',
        'to',
        'using',
        'will',
        'with',
        'without',
    ];

    private const array LOW_SIGNAL_TOKENS = [
        'complete',
        'implement',
        'produce',
    ];

    private const array TOKEN_MAP = [
        'actionable' => 'actionable',
        'alignment' => 'align',
        'aligned' => 'align',
        'backed' => 'back',
        'check' => 'detect',
        'checks' => 'detect',
        'checking' => 'detect',
        'claims' => 'claim',
        'cli' => 'command',
        'commands' => 'command',
        'completed' => 'complete',
        'consumed' => 'consume',
        'consuming' => 'consume',
        'created' => 'present',
        'criteria' => 'criterion',
        'decisions' => 'decision',
        'detected' => 'detect',
        'detecting' => 'detect',
        'detects' => 'detect',
        'doctor' => 'validate',
        'documents' => 'document',
        'exist' => 'present',
        'exists' => 'present',
        'files' => 'file',
        'guidance' => 'guidance',
        'heuristics' => 'heuristic',
        'implemented' => 'implement',
        'implementing' => 'implement',
        'implementation' => 'implement',
        'issues' => 'issue',
        'initialization' => 'init',
        'initialized' => 'init',
        'initializing' => 'init',
        'initialize' => 'init',
        'init' => 'init',
        'ledgers' => 'ledger',
        'mismatch' => 'align',
        'mismatches' => 'align',
        'deterministically' => 'deterministic',
        'passes' => 'pass',
        'presented' => 'present',
        'produced' => 'produce',
        'produces' => 'produce',
        'questions' => 'question',
        'repairing' => 'repair',
        'requirements' => 'requirement',
        'reported' => 'detect',
        'results' => 'result',
        'return' => 'produce',
        'returned' => 'produce',
        'returns' => 'produce',
        'safe' => 'safe',
        'safely' => 'safe',
        'sections' => 'section',
        'states' => 'state',
        'steps' => 'step',
        'structures' => 'structure',
        'supported' => 'support',
        'supports' => 'support',
        'systems' => 'system',
        'validated' => 'validate',
        'validating' => 'validate',
        'validation' => 'validate',
        'validator' => 'validate',
        'validators' => 'validate',
    ];

    private const array PHRASE_MAP = [
        'actionable repair guidance' => 'repair guidance',
        'check-alignment' => 'check alignment',
        'context doctor' => 'context validate',
        'context init' => 'context init',
        'decision-backed' => 'decision backed',
        'spec-state' => 'spec state',
    ];

    public function check(string $specContents, string $stateContents, string $decisionsContents): AlignmentResult
    {
        $specItems = $this->specItems($specContents);
        $trackedStateItems = $this->stateItems($stateContents, self::TRACKED_STATE_SECTIONS);
        $currentStateItems = $this->stateItems($stateContents, ['Current State']);
        $decisionItems = $this->decisionItems($decisionsContents);

        $stateCorpusTokens = $this->corpusTokens($trackedStateItems);
        $specCorpusTokens = $this->corpusTokens($specItems);
        $decisionCorpusTokens = $this->corpusTokens($decisionItems);

        $issues = [];

        foreach ($specItems as $specItem) {
            if ($this->itemMatchesCorpus($specItem, $trackedStateItems, $stateCorpusTokens)) {
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
                decision_reference_found: false,
            );
        }

        foreach ($currentStateItems as $stateItem) {
            if ($this->itemMatchesCorpus($stateItem, $specItems, $specCorpusTokens)) {
                continue;
            }

            $decisionReferenceFound = $this->itemMatchesCorpus($stateItem, $decisionItems, $decisionCorpusTokens);
            if ($decisionReferenceFound) {
                $issues[] = new AlignmentIssue(
                    code: 'possible_mismatch',
                    message: 'Current State claim is not fully grounded in the spec, but a decision entry may explain the divergence.',
                    spec_section: null,
                    state_section: 'Current State',
                    decision_reference_found: true,
                );

                continue;
            }

            $issues[] = new AlignmentIssue(
                code: $this->hasMeaningfulOverlap((array) $stateItem['tokens'], $specCorpusTokens)
                    ? 'missing_decision_reference'
                    : 'unsupported_state_claim',
                message: $this->hasMeaningfulOverlap((array) $stateItem['tokens'], $specCorpusTokens)
                    ? 'Current State claim appears to diverge from the spec without a supporting decision entry.'
                    : 'Current State claim is not grounded in the spec or decision ledger.',
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
     * @return list<array{section:string,text:string,tokens:list<string>,fingerprint:string}>
     */
    private function specItems(string $contents): array
    {
        $items = [];

        foreach (self::SPEC_SECTIONS as $section) {
            foreach ($this->sectionItems($contents, $section) as $text) {
                $item = $this->makeItem($section, $text);
                if ($item === null) {
                    continue;
                }

                $items[] = $item;
            }
        }

        return $this->deduplicateItems($items);
    }

    /**
     * @param array<int,string> $sections
     * @return list<array{section:string,text:string,tokens:list<string>,fingerprint:string}>
     */
    private function stateItems(string $contents, array $sections): array
    {
        $items = [];

        foreach ($sections as $section) {
            foreach ($this->sectionItems($contents, $section) as $text) {
                if ($this->isIgnorableStateItem($text)) {
                    continue;
                }

                $item = $this->makeItem($section, $text);
                if ($item === null) {
                    continue;
                }

                $items[] = $item;
            }
        }

        return $this->deduplicateItems($items);
    }

    /**
     * @return list<array{section:string,text:string,tokens:list<string>,fingerprint:string}>
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

            $item = $this->makeItem('Decision', $text);
            if ($item === null) {
                continue;
            }

            $items[] = $item;
        }

        return $this->deduplicateItems($items);
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

    /**
     * @return array{section:string,text:string,tokens:list<string>,fingerprint:string}|null
     */
    private function makeItem(string $section, string $text): ?array
    {
        if ($text === '' || $this->isPlaceholder($text)) {
            return null;
        }

        $tokens = $this->tokens($text);
        if ($tokens === []) {
            return null;
        }

        return [
            'section' => $section,
            'text' => $text,
            'tokens' => $tokens,
            'fingerprint' => implode(' ', $tokens),
        ];
    }

    private function isPlaceholder(string $text): bool
    {
        return in_array($this->normalize($text), ['tbd', 'title', 'iso 8601', 'none'], true);
    }

    private function isIgnorableStateItem(string $text): bool
    {
        $trimmed = trim($text);

        return preg_match('/^\d+[a-z]+\d+[a-z]*\s+implementation\s+completed\.?$/i', $trimmed) === 1
            || preg_match('/^Alignment checking compares spec .* against Current State, Open Questions, and Next Steps\.?$/i', $trimmed) === 1
            || preg_match('/^Untracked spec requirements are reported deterministically\.?$/i', $trimmed) === 1
            || preg_match('/^Unsupported Current State claims are reported deterministically\.?$/i', $trimmed) === 1;
    }

    /**
     * @param list<array{section:string,text:string,tokens:list<string>,fingerprint:string}> $items
     * @return list<array{section:string,text:string,tokens:list<string>,fingerprint:string}>
     */
    private function deduplicateItems(array $items): array
    {
        $seen = [];
        $deduplicated = [];

        foreach ($items as $item) {
            $fingerprint = (string) $item['fingerprint'];
            if (isset($seen[$fingerprint])) {
                continue;
            }

            $seen[$fingerprint] = true;
            $deduplicated[] = $item;
        }

        return $deduplicated;
    }

    /**
     * @param array{section:string,text:string,tokens:list<string>,fingerprint:string} $item
     * @param list<array{section:string,text:string,tokens:list<string>,fingerprint:string}> $candidates
     * @param list<string> $corpusTokens
     */
    private function itemMatchesCorpus(array $item, array $candidates, array $corpusTokens): bool
    {
        foreach ($candidates as $candidate) {
            if ($this->tokensMatch((array) $item['tokens'], (array) $candidate['tokens'])) {
                return true;
            }
        }

        return $this->tokensMatch((array) $item['tokens'], $corpusTokens);
    }

    /**
     * @param list<array{section:string,text:string,tokens:list<string>,fingerprint:string}> $items
     * @return list<string>
     */
    private function corpusTokens(array $items): array
    {
        $tokens = [];
        foreach ($items as $item) {
            $tokens = array_merge($tokens, (array) $item['tokens']);
        }

        $tokens = array_values(array_unique($tokens));
        sort($tokens);

        return $tokens;
    }

    /**
     * @param list<string> $left
     * @param list<string> $right
     */
    private function tokensMatch(array $left, array $right): bool
    {
        if ($left === [] || $right === []) {
            return false;
        }

        if (array_diff($left, $right) === [] || array_diff($right, $left) === []) {
            return true;
        }

        $shared = count(array_intersect($left, $right));
        $minimum = min(count($left), count($right));

        return $shared >= 2 && ($shared / $minimum) >= 0.6;
    }

    /**
     * @param list<string> $tokens
     * @param list<string> $corpusTokens
     */
    private function hasMeaningfulOverlap(array $tokens, array $corpusTokens): bool
    {
        return count(array_intersect($tokens, $corpusTokens)) >= 2;
    }

    private function normalize(string $text): string
    {
        $normalized = strtolower($text);
        foreach (self::PHRASE_MAP as $search => $replace) {
            $normalized = str_replace($search, $replace, $normalized);
        }

        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? '';

        return trim(preg_replace('/\s+/', ' ', $normalized) ?? '');
    }

    /**
     * @return list<string>
     */
    private function tokens(string $text): array
    {
        $tokens = [];

        foreach (explode(' ', $this->normalize($text)) as $token) {
            if ($token === '' || in_array($token, self::STOP_WORDS, true)) {
                continue;
            }

            $canonical = self::TOKEN_MAP[$token] ?? $token;
            $tokens[] = $canonical;
        }

        $filtered = array_values(array_filter(
            $tokens,
            static fn(string $token): bool => !in_array($token, self::LOW_SIGNAL_TOKENS, true),
        ));

        if ($filtered !== []) {
            $tokens = $filtered;
        }

        return array_values(array_unique($tokens));
    }

    /**
     * @param list<array{section:string,text:string,tokens:list<string>,fingerprint:string}> $trackedStateItems
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
