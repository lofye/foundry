<?php

declare(strict_types=1);

namespace Foundry\Context\Validation;

final readonly class ValidationIssue
{
    public function __construct(
        public string $code,
        public string $message,
        public string $file_path,
        public ?string $section = null,
    ) {}
}
