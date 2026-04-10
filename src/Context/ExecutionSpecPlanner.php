<?php

declare(strict_types=1);

namespace Foundry\Context;

final class ExecutionSpecPlanner
{
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
        'this',
        'to',
        'with',
    ];

    private const array SLUG_NOISE = [
        'canonical',
        'context',
        'current',
        'decision',
        'decisions',
        'deterministic',
        'docs',
        'execution',
        'feature',
        'implementation',
        'implement',
        'ledger',
        'next',
        'spec',
        'state',
        'step',
        'steps',
        'work',
    ];

    /**
     * @param array{
     *     feature:string,
     *     mode:string,
     *     paths:array{spec:string,state:string,decisions:string,feature_base:string,manifest:string,prompts:string},
     *     spec:array<string,string>,
     *     state:array<string,string>,
     *     decisions:list<array<string,string>>,
     *     spec_tracking_items:list<string>,
     *     description:string,
     *     execution_summary:string
     * } $executionInput
     * @return array{
     *     slug:string,
     *     purpose:string,
     *     scope:list<string>,
     *     constraints:list<string>,
     *     requested_changes:list<string>
     * }|null
     */
    public function plan(string $featureName, array $executionInput): ?array
    {
        $ignoredTokens = array_values(array_unique(array_merge(
            array_values(array_filter(explode('-', $featureName))),
            ['completed', 'feature', 'features', 'implement', 'implementation', 'pending', 'state', 'step', 'steps'],
        )));

        $currentStateItems = $this->meaningfulItems((string) ($executionInput['state']['Current State'] ?? ''));
        $nextStepItems = $this->meaningfulItems((string) ($executionInput['state']['Next Steps'] ?? ''));
        $specTrackingItems = array_values(array_map(
            'strval',
            (array) ($executionInput['spec_tracking_items'] ?? []),
        ));

        $candidate = $this->firstUnimplementedNextStep($nextStepItems, $currentStateItems, $ignoredTokens)
            ?? $this->firstUnimplementedSpecItem($specTrackingItems, $currentStateItems, $ignoredTokens);

        if ($candidate === null) {
            return null;
        }

        return [
            'slug' => $this->slugFromText($candidate, $featureName),
            'purpose' => 'Advance the next bounded implementation step for ' . $featureName . ' from canonical feature context.',
            'scope' => [$candidate],
            'constraints' => [
                'Keep canonical feature context authoritative.',
                'Keep generated execution specs secondary to canonical feature truth.',
                'Keep this work deterministic and bounded to one coherent step.',
                'Respect prior decisions recorded in docs/features/' . $featureName . '.decisions.md.',
            ],
            'requested_changes' => [$candidate],
        ];
    }

    /**
     * @param array{
     *     slug:string,
     *     purpose:string,
     *     scope:list<string>,
     *     constraints:list<string>,
     *     requested_changes:list<string>
     * } $plan
     */
    public function render(string $specId, string $featureName, array $plan): string
    {
        $lines = [
            '# Execution Spec: ' . $specId,
            '',
            '## Feature',
            '- ' . $featureName,
            '',
            '## Purpose',
            '- ' . $plan['purpose'],
            '',
            '## Scope',
        ];

        foreach ($plan['scope'] as $item) {
            $lines[] = '- ' . $item;
        }

        $lines[] = '';
        $lines[] = '## Constraints';
        foreach ($plan['constraints'] as $item) {
            $lines[] = '- ' . $item;
        }

        $lines[] = '';
        $lines[] = '## Requested Changes';
        foreach ($plan['requested_changes'] as $item) {
            $lines[] = '- ' . $item;
        }

        $lines[] = '';

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param list<string> $nextStepItems
     * @param list<string> $currentStateItems
     * @param list<string> $ignoredTokens
     */
    private function firstUnimplementedNextStep(array $nextStepItems, array $currentStateItems, array $ignoredTokens): ?string
    {
        foreach ($nextStepItems as $item) {
            if (!$this->matchesAny($item, $currentStateItems, $ignoredTokens)) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param list<string> $specTrackingItems
     * @param list<string> $currentStateItems
     * @param list<string> $ignoredTokens
     */
    private function firstUnimplementedSpecItem(array $specTrackingItems, array $currentStateItems, array $ignoredTokens): ?string
    {
        foreach ($specTrackingItems as $item) {
            if (!$this->matchesAny($item, $currentStateItems, $ignoredTokens)) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function meaningfulItems(string $body): array
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

        return array_values(array_filter(
            array_map('trim', $items),
            static fn(string $item): bool => $item !== '',
        ));
    }

    /**
     * @param list<string> $items
     * @param list<string> $ignoredTokens
     */
    private function matchesAny(string $candidate, array $items, array $ignoredTokens): bool
    {
        foreach ($items as $item) {
            if ($this->itemsMatch($candidate, $item, $ignoredTokens)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $ignoredTokens
     */
    private function itemsMatch(string $left, string $right, array $ignoredTokens): bool
    {
        $leftNormalized = $this->normalizedText($left);
        $rightNormalized = $this->normalizedText($right);

        if ($leftNormalized === '' || $rightNormalized === '') {
            return false;
        }

        if ($leftNormalized === $rightNormalized) {
            return true;
        }

        if (str_contains($leftNormalized, $rightNormalized) || str_contains($rightNormalized, $leftNormalized)) {
            return true;
        }

        $leftTokens = $this->tokens($leftNormalized, $ignoredTokens);
        $rightTokens = $this->tokens($rightNormalized, $ignoredTokens);
        if ($leftTokens === [] || $rightTokens === []) {
            return false;
        }

        $overlap = count(array_intersect($leftTokens, $rightTokens));
        $threshold = min(count($leftTokens), count($rightTokens), 3);

        return $overlap >= max(2, $threshold);
    }

    private function slugFromText(string $text, string $featureName): string
    {
        $featureTokens = array_values(array_filter(explode('-', $featureName)));
        $ignored = array_values(array_unique(array_merge(
            self::STOP_WORDS,
            self::SLUG_NOISE,
            $featureTokens,
        )));

        $tokens = array_values(array_filter(
            $this->tokens($this->normalizedText($text)),
            static fn(string $token): bool => !in_array($token, $ignored, true),
        ));

        $tokens = array_slice($tokens, 0, 4);

        return $tokens === [] ? 'initial' : implode('-', $tokens);
    }

    /**
     * @param list<string> $ignoredTokens
     * @return list<string>
     */
    private function tokens(string $text, array $ignoredTokens = []): array
    {
        preg_match_all('/[a-z0-9]+/', strtolower($text), $matches);

        return array_values(array_filter(
            array_map('strval', $matches[0]),
            static fn(string $token): bool => $token !== ''
                && !in_array($token, self::STOP_WORDS, true)
                && !in_array($token, $ignoredTokens, true),
        ));
    }

    private function normalizedText(string $text): string
    {
        $text = strtolower(trim($text));

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $text));
    }
}
