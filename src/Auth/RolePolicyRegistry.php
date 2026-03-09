<?php
declare(strict_types=1);

namespace Foundry\Auth;

use Foundry\Support\Paths;

final class RolePolicyRegistry
{
    public function __construct(private readonly Paths $paths)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function roles(): array
    {
        $path = $this->paths->join('app/.foundry/build/projections/role_index.php');
        if (!is_file($path)) {
            return [];
        }

        /** @var mixed $raw */
        $raw = require $path;

        return is_array($raw) ? $raw : [];
    }

    /**
     * @return array<string,mixed>
     */
    public function policies(): array
    {
        $path = $this->paths->join('app/.foundry/build/projections/policy_index.php');
        if (!is_file($path)) {
            return [];
        }

        /** @var mixed $raw */
        $raw = require $path;

        return is_array($raw) ? $raw : [];
    }

    public function allows(string $role, string $permission, ?string $policy = null): bool
    {
        $roles = $this->roles();
        $roleRow = $roles[$role] ?? null;
        $rolePermissions = is_array($roleRow['permissions'] ?? null) ? $roleRow['permissions'] : [];
        $rolePermissions = array_values(array_map('strval', $rolePermissions));

        if (in_array('*', $rolePermissions, true) || in_array($permission, $rolePermissions, true)) {
            return true;
        }

        if ($policy === null || $policy === '') {
            return false;
        }

        $policies = $this->policies();
        $policyRow = $policies[$policy] ?? null;
        $rules = is_array($policyRow['rules'] ?? null) ? $policyRow['rules'] : [];
        $permissions = array_values(array_map('strval', (array) ($rules[$role] ?? [])));

        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }
}
