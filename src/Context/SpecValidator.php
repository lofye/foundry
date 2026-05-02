<?php

declare(strict_types=1);

namespace Foundry\Context;

use Foundry\Context\Validation\ValidationIssue;
use Foundry\Context\Validation\ValidationResult;

final class SpecValidator
{
    private const array REQUIRED_SECTIONS = [
        'Purpose',
        'Goals',
        'Non-Goals',
        'Constraints',
        'Expected Behavior',
        'Acceptance Criteria',
        'Assumptions',
    ];

    public function __construct(
        private readonly ContextFileResolver $resolver = new ContextFileResolver(),
    ) {}

    public function validate(string $featureName, string $filePath, bool $requireExists = true): ValidationResult
    {
        $issues = [];
        $missingSections = [];
        $fileExists = is_file($filePath);

        if (!$this->hasCanonicalPath($filePath, $this->resolver->specPath($featureName))) {
            $issues[] = new ValidationIssue(
                code: 'CONTEXT_SPEC_PATH_NON_CANONICAL',
                message: sprintf('Spec path must be docs/%1$s/%1$s.spec.md.', $featureName),
                file_path: $filePath,
            );
        }

        if (!$fileExists) {
            if ($requireExists) {
                $issues[] = new ValidationIssue(
                    code: 'CONTEXT_FILE_MISSING',
                    message: 'Context spec file is missing.',
                    file_path: $filePath,
                );
            }

            return ValidationResult::fromIssues($issues, $missingSections, false);
        }

        $contents = file_get_contents($filePath);
        if ($contents === false) {
            $issues[] = new ValidationIssue(
                code: 'CONTEXT_FILE_UNREADABLE',
                message: 'Context spec file could not be read.',
                file_path: $filePath,
            );

            return ValidationResult::fromIssues($issues, $missingSections, true);
        }

        $expectedHeading = '# Feature Spec: ' . $featureName;
        if ($this->firstHeading($contents) !== $expectedHeading) {
            $issues[] = new ValidationIssue(
                code: 'CONTEXT_SPEC_HEADING_INVALID',
                message: sprintf('Spec heading must be "%s".', $expectedHeading),
                file_path: $filePath,
                section: 'Feature Spec',
            );
        }

        foreach (self::REQUIRED_SECTIONS as $section) {
            if ($this->hasSection($contents, $section)) {
                continue;
            }

            $missingSections[] = $section;
            $issues[] = new ValidationIssue(
                code: 'CONTEXT_SPEC_SECTION_MISSING',
                message: sprintf('Spec is missing required section "## %s".', $section),
                file_path: $filePath,
                section: $section,
            );
        }

        return ValidationResult::fromIssues($issues, $missingSections, true);
    }

    private function hasCanonicalPath(string $filePath, string $expectedPath): bool
    {
        $normalized = str_replace('\\', '/', $filePath);

        return $normalized === $expectedPath || str_ends_with($normalized, '/' . $expectedPath);
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
}
