<?php
declare(strict_types=1);

namespace Forge\Auth;

final class AuthContext
{
    /**
     * @param array<int,string> $roles
     * @param array<int,string> $permissions
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        private readonly bool $authenticated,
        private readonly ?string $userId = null,
        private readonly array $roles = [],
        private readonly array $permissions = [],
        private readonly array $metadata = [],
    ) {
    }

    public static function guest(): self
    {
        return new self(false, null, [], [], []);
    }

    public function isAuthenticated(): bool
    {
        return $this->authenticated;
    }

    public function userId(): ?string
    {
        return $this->userId;
    }

    /**
     * @return array<int,string>
     */
    public function roles(): array
    {
        return $this->roles;
    }

    /**
     * @return array<int,string>
     */
    public function permissions(): array
    {
        return $this->permissions;
    }

    /**
     * @return array<string,mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }
}
