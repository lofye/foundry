<?php
declare(strict_types=1);

namespace Foundry\Verification;

final class VerificationResult
{
    /**
     * @param array<int,string> $errors
     * @param array<int,string> $warnings
     */
    public function __construct(
        public readonly bool $ok,
        public readonly array $errors = [],
        public readonly array $warnings = [],
    ) {
    }

    /**
     * @return array{ok:bool,errors:array<int,string>,warnings:array<int,string>}
     */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
        ];
    }
}
