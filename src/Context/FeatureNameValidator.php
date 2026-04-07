<?php

declare(strict_types=1);

namespace Foundry\Context;

use Foundry\Context\Validation\ValidationIssue;
use Foundry\Context\Validation\ValidationResult;

final class FeatureNameValidator
{
    public function validate(string $featureName): ValidationResult
    {
        $issues = [];

        if ($featureName === '') {
            $issues[] = $this->issue(
                'CONTEXT_FEATURE_NAME_EMPTY',
                'Feature name must not be empty.',
            );
        }

        if (preg_match('/[A-Z]/', $featureName) === 1) {
            $issues[] = $this->issue(
                'CONTEXT_FEATURE_NAME_UPPERCASE',
                'Feature name must be lowercase.',
            );
        }

        if (str_contains($featureName, '_')) {
            $issues[] = $this->issue(
                'CONTEXT_FEATURE_NAME_UNDERSCORE',
                'Feature name must use kebab-case and must not contain underscores.',
            );
        }

        if (preg_match('/\s/', $featureName) === 1) {
            $issues[] = $this->issue(
                'CONTEXT_FEATURE_NAME_WHITESPACE',
                'Feature name must not contain whitespace.',
            );
        }

        if (str_starts_with($featureName, '-')) {
            $issues[] = $this->issue(
                'CONTEXT_FEATURE_NAME_LEADING_DASH',
                'Feature name must not start with a dash.',
            );
        }

        if (str_ends_with($featureName, '-')) {
            $issues[] = $this->issue(
                'CONTEXT_FEATURE_NAME_TRAILING_DASH',
                'Feature name must not end with a dash.',
            );
        }

        if (str_contains($featureName, '--')) {
            $issues[] = $this->issue(
                'CONTEXT_FEATURE_NAME_REPEATED_DASH',
                'Feature name must not contain repeated dashes.',
            );
        }

        if (preg_match('/[^A-Za-z0-9 _-]/', $featureName) === 1) {
            $issues[] = $this->issue(
                'CONTEXT_FEATURE_NAME_INVALID_CHARACTER',
                'Feature name must only contain lowercase letters, numbers, and dashes.',
            );
        }

        if ($issues === [] && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $featureName) !== 1) {
            $issues[] = $this->issue(
                'CONTEXT_FEATURE_NAME_INVALID',
                'Feature name must be lowercase kebab-case.',
            );
        }

        return ValidationResult::fromIssues($issues);
    }

    private function issue(string $code, string $message): ValidationIssue
    {
        return new ValidationIssue(
            code: $code,
            message: $message,
            file_path: '',
        );
    }
}
