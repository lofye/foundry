<?php

declare(strict_types=1);

namespace Foundry\Context;

use Foundry\Context\Validation\ValidationIssue;
use Foundry\Context\Validation\ValidationResult;

final class StateValidator
{
    private const array REQUIRED_SECTIONS = [
        'Purpose',
        'Current State',
        'Open Questions',
        'Next Steps',
    ];

    public function __construct(
        private readonly ContextFileResolver $resolver = new ContextFileResolver(),
    ) {}

    public function validate(string $featureName, string $filePath, bool $requireExists = true): ValidationResult
    {
        $issues = [];
        $missingSections = [];
        $fileExists = is_file($filePath);

        if (!$this->hasCanonicalPath($filePath, [
            $this->resolver->legacyStatePath($featureName),
            $this->resolver->canonicalStatePath($featureName),
        ])) {
            $issues[] = new ValidationIssue(
                code: 'CONTEXT_STATE_PATH_NON_CANONICAL',
                message: sprintf('State path must be canonical: docs/features/%1$s/%1$s.md or Features/%2$s/%1$s.md.', $featureName, $this->pascalFromSlug($featureName)),
                file_path: $filePath,
            );
        }

        if (!$fileExists) {
            if ($requireExists) {
                $issues[] = new ValidationIssue(
                    code: 'CONTEXT_FILE_MISSING',
                    message: 'Context state file is missing.',
                    file_path: $filePath,
                );
            }

            return ValidationResult::fromIssues($issues, $missingSections, false);
        }

        $contents = file_get_contents($filePath);
        if ($contents === false) {
            $issues[] = new ValidationIssue(
                code: 'CONTEXT_FILE_UNREADABLE',
                message: 'Context state file could not be read.',
                file_path: $filePath,
            );

            return ValidationResult::fromIssues($issues, $missingSections, true);
        }

        $expectedHeading = '# Feature: ' . $featureName;
        if ($this->firstHeading($contents) !== $expectedHeading) {
            $issues[] = new ValidationIssue(
                code: 'CONTEXT_STATE_HEADING_INVALID',
                message: sprintf('State heading must be "%s".', $expectedHeading),
                file_path: $filePath,
                section: 'Feature',
            );
        }

        foreach (self::REQUIRED_SECTIONS as $section) {
            if ($this->hasSection($contents, $section)) {
                continue;
            }

            $missingSections[] = $section;
            $issues[] = new ValidationIssue(
                code: 'CONTEXT_STATE_SECTION_MISSING',
                message: sprintf('State document is missing required section "## %s".', $section),
                file_path: $filePath,
                section: $section,
            );
        }

        return ValidationResult::fromIssues($issues, $missingSections, true);
    }

    /**
     * @param list<string> $expectedPaths
     */
    private function hasCanonicalPath(string $filePath, array $expectedPaths): bool
    {
        $normalized = str_replace('\\', '/', $filePath);

        foreach ($expectedPaths as $expectedPath) {
            if ($normalized === $expectedPath || str_ends_with($normalized, '/' . $expectedPath)) {
                return true;
            }
        }

        return false;
    }

    private function firstHeading(string $contents): ?string
    {
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            if (str_starts_with($line, '#')) {
                return rtrim($line);
            }
        }

        return null;
    }

    private function hasSection(string $contents, string $section): bool
    {
        return preg_match('/^## ' . preg_quote($section, '/') . '\s*$/m', $contents) === 1;
    }

    private function pascalFromSlug(string $slug): string
    {
        $parts = array_filter(explode('-', $slug), static fn(string $part): bool => $part !== '');

        return implode('', array_map(static fn(string $part): string => ucfirst($part), $parts));
    }
}
