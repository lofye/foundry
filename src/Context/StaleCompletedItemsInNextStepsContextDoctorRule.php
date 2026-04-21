<?php

declare(strict_types=1);

namespace Foundry\Context;

final class StaleCompletedItemsInNextStepsContextDoctorRule implements ContextDoctorDiagnosticRule
{
    private const string CODE = 'STALE_COMPLETED_ITEMS_IN_NEXT_STEPS';
    private const string MESSAGE = 'Next Steps contains work that is already reflected as implemented in Current State.';

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
        'using',
        'with',
        'without',
    ];

    private const array LOW_SIGNAL_TOKENS = [
        'complete',
        'implement',
        'pending',
    ];

    private const array TOKEN_MAP = [
        'completed' => 'complete',
        'exists' => 'present',
        'exist' => 'present',
        'implemented' => 'implement',
        'implementation' => 'implement',
        'presented' => 'present',
        'supports' => 'support',
        'supported' => 'support',
    ];

    public function evaluate(ContextDoctorDiagnosticRuleContext $context): ?ContextDoctorDiagnosticRuleResult
    {
        if (!$context->hasUsableFiles('state')) {
            return null;
        }

        $currentStateItems = $this->sectionItems($context->fileContents('state'), 'Current State');
        $nextStepItems = $this->sectionItems($context->fileContents('state'), 'Next Steps');

        if ($currentStateItems === [] || $nextStepItems === []) {
            return null;
        }

        $currentStateFingerprints = array_values(array_filter(array_map(
            fn(string $item): string => $this->fingerprint($item),
            $currentStateItems,
        )));

        foreach ($nextStepItems as $item) {
            $fingerprint = $this->fingerprint($item);
            if ($fingerprint === '' || !in_array($fingerprint, $currentStateFingerprints, true)) {
                continue;
            }

            return new ContextDoctorDiagnosticRuleResult(
                code: self::CODE,
                message: self::MESSAGE,
                targets: [
                    new ContextDoctorDiagnosticTarget(
                        bucket: 'state',
                        filePath: $context->filePath('state'),
                    ),
                ],
                requiredActions: [
                    'Remove already implemented work from Next Steps in ' . $context->filePath('state') . '.',
                ],
                requiresRepair: true,
            );
        }

        return null;
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

                $items[] = trim($matches[1]);

                continue;
            }

            $paragraph[] = $trimmed;
        }

        if ($paragraph !== []) {
            $items[] = trim(implode(' ', $paragraph));
        }

        return array_values(array_filter(
            array_map(static fn(string $item): string => trim($item), $items),
            static fn(string $item): bool => $item !== '' && !in_array(strtolower($item), ['none.', 'none', 'tbd.', 'tbd'], true),
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

    private function fingerprint(string $text): string
    {
        $tokens = [];
        $normalized = strtolower($text);
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? '';
        $normalized = trim(preg_replace('/\s+/', ' ', $normalized) ?? '');

        foreach (explode(' ', $normalized) as $token) {
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

        $tokens = array_values(array_unique($tokens));
        sort($tokens);

        return implode(' ', $tokens);
    }
}
