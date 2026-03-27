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
        $definitionDir = $this->paths->join('app/definitions/roles');
        if (!is_dir($definitionDir)) {
            mkdir($definitionDir, 0777, true);
        }

        $definitionPath = $definitionDir . '/default.roles.yaml';
        if (is_file($definitionPath) && !$force) {
            throw new FoundryError('ROLES_DEFINITION_EXISTS', 'io', ['path' => $definitionPath], 'Roles definition already exists. Use --force to overwrite.');
        }

        $definition = [
            'version' => 1,
            'set' => 'default',
            'roles' => [
                'admin' => ['permissions' => ['*']],
                'editor' => ['permissions' => ['posts.view', 'posts.create', 'posts.update']],
                'viewer' => ['permissions' => ['posts.view']],
            ],
        ];
        file_put_contents($definitionPath, Yaml::dump($definition));

        $migrationPath = $this->paths->join('database/migrations/20260309_create_roles_tables.sql');
        $migrationDir = dirname($migrationPath);
        if (!is_dir($migrationDir)) {
            mkdir($migrationDir, 0777, true);
        }

        if (!is_file($migrationPath) || $force) {
            file_put_contents($migrationPath, <<<'SQL'
-- Foundry roles scaffolding
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
            'definition' => $definitionPath,
            'files' => [$definitionPath, $migrationPath],
        ];
    }
}
