<?php

declare(strict_types=1);

namespace Foundry\Auth;

final class PermissionRegistry
{
    /**
     * @var array<string,true>
     */
    private array $permissions = [];

    /**
     * @param array<int,string> $permissions
     */
    public function registerMany(array $permissions): void
    {
        foreach ($permissions as $permission) {
            $this->permissions[$permission] = true;
        }
    }

    public function register(string $permission): void
    {
        $this->permissions[$permission] = true;
    }

    public function has(string $permission): bool
    {
        return isset($this->permissions[$permission]);
    }

    /**
     * @return array<int,string>
     */
    public function all(): array
    {
        $keys = array_keys($this->permissions);
        sort($keys);

        return $keys;
    }
}
