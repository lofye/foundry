<?php

declare(strict_types=1);

namespace Foundry\Context;

final class ExecutionSpecPlanner
{
    private const array GENERIC_FALLBACK_SLUGS = [
        'ensure',
        'improve',
        'initial',
        'support',
        'update',
    ];

    private const array ACTIONABLE_LEADING_VERBS = [
        'add',
        'append',
        'block',
        'create',
        'derive',
        'detect',
        'generate',
        'initialize',
        'inspect',
        'load',
        'map',
        'parse',
        'record',
        'resolve',
        'return',
        'reuse',
        'update',
        'validate',
        'verify',
        'write',
    ];

    private const array GENERIC_LEADING_WORDS = [
        'ensure',
        'future',
        'improve',
        'keep',
        'later',
        'maintain',
        'preserve',
        'remain',
        'support',
    ];

    private const array LOW_INFORMATION_TOKENS = [
        'behavior',
        'canonical',
        'change',
        'changes',
        'feature',
        'handling',
        'improve',
        'initial',
        'logic',
        'output',
        'planner',
        'planning',
        'step',
        'steps',
        'support',
        'system',
        'systems',
        'update',
        'work',
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
        'later',
        'next',
        'spec',
        'state',
        'step',
        'steps',
        'work',
    ];

    private const array SUBJECT_PREFIXES = [
        'Plan feature ' => 'plan feature',
        'Implement spec ' => 'implement spec',
        'Implement feature ' => 'implement feature',
        'Inspect context ' => 'inspect context',
        'Verify context ' => 'verify context',
        'CLI commands can ' => 'cli',
        'CLI can ' => 'cli',
        'Validators can ' => 'validators',
        'Alignment results include ' => 'alignment',
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
     *     requested_changes:list<string>,
     *     non_goals:list<string>,
     *     completion_signals:list<string>,
     *     post_execution_expectations:list<string>
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
        $unimplementedSpecItems = [];

        foreach (array_values(array_map(
            'strval',
            (array) ($executionInput['spec_tracking_items'] ?? []),
        )) as $item) {
            if ($this->matchesAny($item, $currentStateItems, $ignoredTokens)) {
                continue;
            }

            $unimplementedSpecItems[] = $item;
        }

        foreach ($this->candidateGaps($unimplementedSpecItems, $nextStepItems, $ignoredTokens) as $candidate) {
            $plan = $this->planFromCandidate($featureName, $candidate);
            if ($plan !== null) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * @param list<string> $specItems
     * @param list<string> $nextStepItems
     * @param list<string> $ignoredTokens
     * @return list<string>
     */
    private function candidateGaps(array $specItems, array $nextStepItems, array $ignoredTokens): array
    {
        $preferred = [];
        $fallback = [];

        foreach ($specItems as $item) {
            if (!$this->isMeaningfulCandidate($item)) {
                continue;
            }

            if ($this->matchesAny($item, $nextStepItems, $ignoredTokens)) {
                $preferred[] = $item;
            } else {
                $fallback[] = $item;
            }
        }

        return array_values(array_merge($preferred, $fallback));
    }

    /**
     * @return array{
     *     slug:string,
     *     purpose:string,
     *     scope:list<string>,
     *     constraints:list<string>,
     *     requested_changes:list<string>,
     *     non_goals:list<string>,
     *     completion_signals:list<string>,
     *     post_execution_expectations:list<string>
     * }|null
     */
    private function planFromCandidate(string $featureName, string $candidate): ?array
    {
        $requestedChange = $this->requestedChangeFromCandidate($candidate);
        if ($requestedChange === null) {
            return null;
        }

        $focus = $this->focusFromText($requestedChange);
        if (!$this->hasConcreteContent($focus, $featureName)) {
            return null;
        }

        $scope = $this->scopeFromCandidate($featureName, $candidate, $focus);
        $purpose = $this->purposeFromFocus($focus);
        $slug = $this->slugFromText($focus === '' ? $requestedChange : $focus, $featureName);
        $completionSignals = $this->completionSignals($featureName, $requestedChange, $focus);

        if ($slug === null
            || $this->isTautologicalOutput($purpose, $scope, $requestedChange)
            || !$this->hasConcreteContent($purpose, $featureName)
            || !$this->hasConcreteContent($scope, $featureName)
            || $this->completionSignalsAreTooGeneric($completionSignals, $featureName)
        ) {
            return null;
        }

        return [
            'slug' => $slug,
            'purpose' => $purpose,
            'scope' => [$scope],
            'constraints' => [
                'Keep canonical feature context authoritative.',
                'Keep generated execution specs secondary to canonical feature truth.',
                'Keep this work deterministic and bounded to one coherent step.',
                'Respect prior decisions recorded in docs/features/' . $featureName . '/' . $featureName . '.decisions.md.',
            ],
            'requested_changes' => [$requestedChange],
            'non_goals' => $this->nonGoals($scope),
            'completion_signals' => $completionSignals,
            'post_execution_expectations' => $this->postExecutionExpectations($featureName),
        ];
    }

    /**
     * @return list<string>
     */
    private function nonGoals(string $scope): array
    {
        return [
            'Do not broaden this step beyond ' . rtrim($scope, '.') . '.',
            'Do not change canonical feature context authority.',
        ];
    }

    /**
     * @return list<string>
     */
    private function completionSignals(string $featureName, string $requestedChange, string $focus): array
    {
        $signals = [
            $requestedChange,
        ];

        $focus = trim(rtrim($focus, '.'));
        if ($focus !== '') {
            $signals[] = 'docs/features/' . $featureName . '/' . $featureName . '.md reflects ' . $focus . '.';
        }

        return $signals;
    }

    /**
     * @return list<string>
     */
    private function postExecutionExpectations(string $featureName): array
    {
        return [
            'Current State reflects the completed bounded work.',
            'Meaningful execution decisions are appended to docs/features/' . $featureName . '/' . $featureName . '.decisions.md when needed.',
            'Canonical feature context remains authoritative for later work.',
        ];
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

    private function requestedChangeFromCandidate(string $candidate): ?string
    {
        $candidate = trim($candidate);
        if ($candidate === '' || !$this->isMeaningfulCandidate($candidate)) {
            return null;
        }

        return $candidate;
    }

    private function focusFromText(string $text): string
    {
        $text = trim($text);

        foreach (array_keys(self::SUBJECT_PREFIXES) as $prefix) {
            if (str_starts_with($text, $prefix)) {
                $text = substr($text, strlen($prefix));

                break;
            }
        }

        $text = preg_replace(
            '/^(?:' . implode('|', self::ACTIONABLE_LEADING_VERBS) . ')\s+/i',
            '',
            $text,
        ) ?? $text;

        return rtrim(trim($text), '.');
    }

    private function purposeFromFocus(string $focus): string
    {
        $focus = trim($focus);

        return 'Current State does not yet reflect ' . $focus . ', so this is the next bounded step now.';
    }

    private function scopeFromCandidate(string $featureName, string $candidate, string $focus): string
    {
        $lower = strtolower($candidate);

        if (str_contains($lower, 'plan feature') || str_contains($lower, 'planner')) {
            return 'Plan feature command and execution-spec generation.';
        }

        if (str_contains($lower, 'implement spec') || str_contains($lower, 'execution spec')) {
            return 'Execution-spec generation and spec-driven execution orchestration.';
        }

        if (str_contains($lower, 'implement feature')) {
            return 'Canonical feature execution orchestration.';
        }

        if (str_contains($lower, 'verify context')) {
            return 'Verify context status mapping and output.';
        }

        if (str_contains($lower, 'inspect context')) {
            return 'Inspect context aggregation and output.';
        }

        if (str_contains($lower, 'alignment')) {
            return 'Alignment detection and reporting.';
        }

        if (str_contains($lower, 'validator') || str_contains($lower, 'validate')) {
            return 'Context validation behavior.';
        }

        if (str_contains($lower, 'contract test')) {
            return $this->humanizeFeatureName($featureName) . ' contract-test coverage and generated verification.';
        }

        if ($focus !== '') {
            return ucfirst($focus) . '.';
        }

        return 'Canonical feature planning behavior.';
    }

    private function isTautologicalOutput(string $purpose, string $scope, string $requestedChange): bool
    {
        $normalizedScope = $this->normalizedText($scope);
        $normalizedRequestedChange = $this->normalizedText($requestedChange);
        $normalizedPurpose = $this->normalizedText($purpose);

        if ($normalizedScope === '' || $normalizedRequestedChange === '' || $normalizedPurpose === '') {
            return true;
        }

        return $normalizedScope === $normalizedRequestedChange
            || $normalizedPurpose === $normalizedRequestedChange
            || $normalizedPurpose === $normalizedScope;
    }

    private function isMeaningfulCandidate(string $candidate): bool
    {
        $candidate = trim($candidate);
        if ($candidate === '') {
            return false;
        }

        $lower = strtolower($candidate);
        $firstWord = strtok($lower, ' ');
        if (is_string($firstWord) && in_array($firstWord, self::GENERIC_LEADING_WORDS, true)) {
            return false;
        }

        foreach (array_keys(self::SUBJECT_PREFIXES) as $prefix) {
            if (str_starts_with($candidate, $prefix)) {
                return true;
            }
        }

        return preg_match(
            '/^(?:' . implode('|', self::ACTIONABLE_LEADING_VERBS) . ')\b/i',
            $candidate,
        ) === 1;
    }

    private function humanizeFeatureName(string $featureName): string
    {
        return ucfirst(str_replace('-', ' ', $featureName));
    }

    private function slugFromText(string $text, string $featureName): ?string
    {
        $featureTokens = array_values(array_filter(explode('-', $featureName)));
        $ignored = array_values(array_unique(array_merge(
            self::STOP_WORDS,
            self::SLUG_NOISE,
            self::GENERIC_LEADING_WORDS,
            self::ACTIONABLE_LEADING_VERBS,
            self::LOW_INFORMATION_TOKENS,
            $featureTokens,
        )));

        $tokens = array_values(array_filter(
            $this->tokens($this->normalizedText($text)),
            static fn(string $token): bool => !in_array($token, $ignored, true),
        ));

        $tokens = array_slice($tokens, 0, 4);
        if ($tokens === []) {
            return null;
        }

        $slug = implode('-', $tokens);

        return in_array($slug, self::GENERIC_FALLBACK_SLUGS, true) ? null : $slug;
    }

    /**
     * @return list<string>
     */
    private function meaningfulContentTokens(string $text, string $featureName): array
    {
        $featureTokens = array_values(array_filter(explode('-', $featureName)));
        $ignored = array_values(array_unique(array_merge(
            self::STOP_WORDS,
            self::GENERIC_LEADING_WORDS,
            self::ACTIONABLE_LEADING_VERBS,
            $featureTokens,
        )));

        return array_values(array_filter(
            $this->tokens($this->normalizedText($text), $ignored),
            static fn(string $token): bool => $token !== '',
        ));
    }

    private function hasConcreteContent(string $text, string $featureName): bool
    {
        $tokens = $this->meaningfulContentTokens($text, $featureName);
        if ($tokens === []) {
            return false;
        }

        return array_diff($tokens, self::LOW_INFORMATION_TOKENS) !== [];
    }

    /**
     * @param list<string> $completionSignals
     */
    private function completionSignalsAreTooGeneric(array $completionSignals, string $featureName): bool
    {
        foreach ($completionSignals as $signal) {
            $normalized = $this->normalizedText($signal);
            if ($normalized === '' || str_contains($normalized, 'verify context ')) {
                return true;
            }

            if (!$this->hasConcreteContent($signal, $featureName)) {
                return true;
            }
        }

        return false;
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
