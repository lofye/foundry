<?php

declare(strict_types=1);

namespace Foundry\Context;

use Foundry\Context\Validation\ValidationIssue;
use Foundry\Context\Validation\ValidationResult;

final class DecisionLedgerValidator
{
    private const array REQUIRED_SUBSECTIONS = [
        'Context',
        'Decision',
        'Reasoning',
        'Alternatives Considered',
        'Impact',
        'Spec Reference',
    ];

    public function __construct(
        private readonly ContextFileResolver $resolver = new ContextFileResolver(),
    ) {}

    public function validate(string $featureName, string $filePath, bool $requireExists = true): ValidationResult
    {
        $issues = [];
        $missingSections = [];
        $fileExists = is_file($filePath);

        if (!$this->hasCanonicalPath($filePath, $this->resolver->decisionsPath($featureName))) {
            $issues[] = new ValidationIssue(
                code: 'CONTEXT_DECISIONS_PATH_NON_CANONICAL',
                message: sprintf('Decision ledger path must be docs/%1$s/%1$s.decisions.md.', $featureName),
                file_path: $filePath,
            );
        }

        if (!$fileExists) {
            if ($requireExists) {
                $issues[] = new ValidationIssue(
                    code: 'CONTEXT_FILE_MISSING',
                    message: 'Decision ledger file is missing.',
                    file_path: $filePath,
                );
            }

            return ValidationResult::fromIssues($issues, $missingSections, false);
        }

        $contents = file_get_contents($filePath);
        if ($contents === false) {
            $issues[] = new ValidationIssue(
                code: 'CONTEXT_FILE_UNREADABLE',
                message: 'Decision ledger file could not be read.',
                file_path: $filePath,
            );

            return ValidationResult::fromIssues($issues, $missingSections, true);
        }

        $entries = $this->entries($contents);
        if ($entries === [] && trim($contents) !== '') {
            $issues[] = new ValidationIssue(
                code: 'CONTEXT_DECISION_ENTRY_MALFORMED',
                message: 'Decision ledger entries must start with "### Decision: <title>".',
                file_path: $filePath,
                section: 'Decision',
            );
        }

        foreach ($entries as $entry) {
            $title = $this->entryTitle($entry);
            if ($title === '') {
                $issues[] = new ValidationIssue(
                    code: 'CONTEXT_DECISION_ENTRY_MALFORMED',
                    message: 'Decision entry heading must include a title.',
                    file_path: $filePath,
                    section: 'Decision',
                );
            }

            $timestamp = $this->timestamp($entry);
            if ($timestamp === null) {
                $missingSections = $this->appendMissingSection($missingSections, 'Timestamp');
                $issues[] = new ValidationIssue(
                    code: 'CONTEXT_DECISION_TIMESTAMP_MISSING',
                    message: 'Decision entry is missing required "Timestamp: <ISO-8601>" line.',
                    file_path: $filePath,
                    section: 'Timestamp',
                );
            } elseif (!$this->isIso8601($timestamp)) {
                $issues[] = new ValidationIssue(
                    code: 'CONTEXT_DECISION_TIMESTAMP_INVALID',
                    message: 'Decision entry timestamp must be ISO-8601.',
                    file_path: $filePath,
                    section: 'Timestamp',
                );
            }

            foreach (self::REQUIRED_SUBSECTIONS as $section) {
                if ($this->hasSubsection($entry, $section)) {
                    continue;
                }

                $missingSections = $this->appendMissingSection($missingSections, $section);
                $issues[] = new ValidationIssue(
                    code: 'CONTEXT_DECISION_SUBSECTION_MISSING',
                    message: sprintf('Decision entry is missing required subsection "**%s**".', $section),
                    file_path: $filePath,
                    section: $section,
                );
            }
        }

        return ValidationResult::fromIssues($issues, $missingSections, true);
    }

    private function hasCanonicalPath(string $filePath, string $expectedPath): bool
    {
        $normalized = str_replace('\\', '/', $filePath);

        return $normalized === $expectedPath || str_ends_with($normalized, '/' . $expectedPath);
    }

    /**
     * @return array<int,string>
     */
    private function entries(string $contents): array
    {
        $matchCount = preg_match_all('/^### Decision:.*$/m', $contents, $matches, PREG_OFFSET_CAPTURE);
        if ($matchCount === false || $matchCount === 0) {
            return [];
        }

        $entries = [];
        $count = count($matches[0]);
        for ($index = 0; $index < $count; $index++) {
            $start = $matches[0][$index][1];
            $end = $matches[0][$index + 1][1] ?? strlen($contents);
            $entries[] = substr($contents, $start, $end - $start);
        }

        return $entries;
    }

    private function entryTitle(string $entry): string
    {
        if (preg_match('/^### Decision:\s*(.*)$/m', $entry, $matches) !== 1) {
            return '';
        }

        return trim($matches[1]);
    }

    private function timestamp(string $entry): ?string
    {
        if (preg_match('/^Timestamp:\s*(.+)$/m', $entry, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]);
    }

    private function isIso8601(string $timestamp): bool
    {
        if ($timestamp === '<ISO-8601>') {
            return true;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $timestamp) === 1;
    }

    private function hasSubsection(string $entry, string $section): bool
    {
        return preg_match('/^\*\*' . preg_quote($section, '/') . '\*\*\s*$/m', $entry) === 1;
    }

    /**
     * @param array<int,string> $missingSections
     * @return array<int,string>
     */
    private function appendMissingSection(array $missingSections, string $section): array
    {
        if (!in_array($section, $missingSections, true)) {
            $missingSections[] = $section;
        }

        return $missingSections;
    }
}
