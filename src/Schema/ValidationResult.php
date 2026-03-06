<?php
declare(strict_types=1);

namespace Forge\Schema;

final class ValidationResult
{
    /**
     * @param array<int,ValidationError> $errors
     */
    public function __construct(
        public readonly bool $isValid,
        public readonly array $errors = [],
    ) {
    }

    public static function valid(): self
    {
        return new self(true, []);
    }

    /**
     * @param array<int,ValidationError> $errors
     */
    public static function invalid(array $errors): self
    {
        return new self(false, $errors);
    }
}
