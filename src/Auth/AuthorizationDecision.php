<?php
declare(strict_types=1);

namespace Foundry\Auth;

final readonly class AuthorizationDecision
{
    public function __construct(
        public readonly bool $allowed,
        public readonly string $reason,
    ) {
    }

    public static function allow(string $reason = 'allowed'): self
    {
        return new self(true, $reason);
    }

    public static function deny(string $reason): self
    {
        return new self(false, $reason);
    }
}
