<?php
declare(strict_types=1);

namespace Forge\Support;

final class Result
{
    private function __construct(
        public readonly bool $ok,
        public readonly mixed $value = null,
        public readonly ?string $error = null,
    ) {
    }

    public static function ok(mixed $value = null): self
    {
        return new self(true, $value, null);
    }

    public static function err(string $error): self
    {
        return new self(false, null, $error);
    }
}
