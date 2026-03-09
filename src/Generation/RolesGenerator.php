<?php
declare(strict_types=1);

namespace Foundry\Generation;

use Foundry\Support\FoundryError;
use Foundry\Support\Paths;
use Foundry\Support\Yaml;

final class RolesGenerator
{
    public function __construct(private readonly Paths $paths)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function generate(bool $force = false): array
    {
        $specDir = $this->paths->join('app/specs/roles');
        if (!is_dir($specDir)) {
            mkdir($specDir, 0777, true);
        }

        $specPath = $specDir . '/default.roles.yaml';
        if (is_file($specPath) && !$force) {
            throw new FoundryError('ROLES_SPEC_EXISTS', 'io', ['path' => $specPath], 'Roles spec already exists. Use --force to overwrite.');
        }

        $spec = [
            'version' => 1,
            'set' => 'default',
            'roles' => [
                'admin' => ['permissions' => ['*']],
                'editor' => ['permissions' => ['posts.view', 'posts.create', 'posts.update']],
                'viewer' => ['permissions' => ['posts.view']],
            ],
        ];
        file_put_contents($specPath, Yaml::dump($spec));

        $migrationPath = $this->paths->join('app/platform/migrations/20260309_create_roles_tables.sql');
        if (!is_file($migrationPath) || $force) {
            file_put_contents($migrationPath, <<<'SQL'
-- Foundry Phase 3 roles scaffolding
CREATE TABLE IF NOT EXISTS roles (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL UNIQUE,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS user_roles (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id TEXT NOT NULL,
  role_name TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
SQL);
        }

        return [
            'spec' => $specPath,
            'files' => [$specPath, $migrationPath],
        ];
    }
}
