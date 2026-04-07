<?php

declare(strict_types=1);

namespace Foundry\Context\Validation;

final readonly class ValidationResult
{
    /**
     * @param array<int,ValidationIssue> $issues
     * @param array<int,string> $missing_sections
     */
    public function __construct(
        public bool $valid,
        public array $issues = [],
        public array $missing_sections = [],
        public bool $file_exists = true,
    ) {}

    public static function valid(bool $file_exists = true): self
    {
        return new self(true, [], [], $file_exists);
    }

    /**
     * @param array<int,ValidationIssue> $issues
     * @param array<int,string> $missing_sections
     */
    public static function invalid(array $issues, array $missing_sections = [], bool $file_exists = true): self
    {
        return new self(false, $issues, $missing_sections, $file_exists);
    }

    /**
     * @param array<int,ValidationIssue> $issues
     * @param array<int,string> $missing_sections
     */
    public static function fromIssues(array $issues, array $missing_sections = [], bool $file_exists = true): self
    {
        return new self($issues === [], $issues, $missing_sections, $file_exists);
    }
}
