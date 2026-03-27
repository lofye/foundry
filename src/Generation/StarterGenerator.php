<?php
declare(strict_types=1);

namespace Foundry\Generation;

use Foundry\Support\FoundryError;
use Foundry\Support\Paths;
use Foundry\Support\Yaml;

final class StarterGenerator
{
    public function __construct(
        private readonly Paths $paths,
        private readonly FeatureGenerator $featureGenerator,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function generate(string $starter, bool $force = false, ?string $name = null): array
    {
        $starter = trim($starter);
        if (!in_array($starter, ['server-rendered', 'api'], true)) {
            throw new FoundryError(
                'STARTER_INVALID',
                'validation',
                ['starter' => $starter],
                'Starter must be server-rendered or api.',
            );
        }

        $generatedFeatures = [];
        $generatedFiles = [];
        foreach ($this->starterDefinitions($starter) as $definition) {
            $generatedFeatures[] = (string) $definition['feature'];
            foreach ($this->featureGenerator->generateFromArray($definition, $force) as $path) {
                $generatedFiles[] = $path;
            }
        }

        foreach ($this->writeStarterDefinition($starter, $generatedFeatures, $name, $force) as $path) {
            $generatedFiles[] = $path;
        }

        foreach ($this->writeStarterMigrations($starter, $force) as $path) {
            $generatedFiles[] = $path;
        }

        sort($generatedFeatures);
        sort($generatedFiles);

        return [
            'starter' => $starter,
            'name' => $name,
            'features' => array_values(array_unique($generatedFeatures)),
            'files' => array_values(array_unique($generatedFiles)),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function starterDefinitions(string $starter): array
    {
        return $starter === 'server-rendered'
            ? $this->serverRenderedDefinitions()
            : $this->apiDefinitions();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function serverRenderedDefinitions(): array
    {
        return [
            $this->featureDefinition('register_user', 'POST', '/register', false, ['session'], [], true),
            $this->featureDefinition('login_user', 'POST', '/login', false, ['session'], [], true),
            $this->featureDefinition('logout_user', 'POST', '/logout', true, ['session'], [], true),
            $this->featureDefinition('request_password_reset', 'POST', '/password/forgot', false, ['session'], [], true),
            $this->featureDefinition('reset_password', 'POST', '/password/reset', false, ['session'], [], true),
            $this->featureDefinition('verify_email', 'POST', '/email/verify', true, ['session'], ['users.verify_email'], true),
            $this->featureDefinition('view_account_settings', 'GET', '/account/settings', true, ['session'], ['users.settings.view'], false),
            $this->featureDefinition('update_account_settings', 'POST', '/account/settings', true, ['session'], ['users.settings.update'], true),
            $this->featureDefinition('view_dashboard', 'GET', '/dashboard', true, ['session'], ['users.dashboard.view'], false),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function apiDefinitions(): array
    {
        return [
            $this->featureDefinition('api_login', 'POST', '/api/login', false, ['bearer'], [], false),
            $this->featureDefinition('api_logout', 'POST', '/api/logout', true, ['bearer'], ['api.logout'], false),
            $this->featureDefinition('api_revoke_token', 'POST', '/api/tokens/revoke', true, ['bearer'], ['api.tokens.revoke'], false),
            $this->featureDefinition('api_me', 'GET', '/api/me', true, ['bearer'], ['api.me.view'], false),
        ];
    }

    /**
     * @param array<int,string> $strategies
     * @param array<int,string> $permissions
     * @return array<string,mixed>
     */
    private function featureDefinition(
        string $feature,
        string $method,
        string $path,
        bool $authRequired,
        array $strategies,
        array $permissions,
        bool $csrfRequired,
    ): array {
        return [
            'feature' => $feature,
            'kind' => 'http',
            'description' => 'Generated starter feature for ' . $feature . '.',
            'route' => [
                'method' => $method,
                'path' => $path,
            ],
            'input' => [
                'fields' => [
                    'email' => ['type' => 'string', 'required' => false, 'form' => 'email'],
                    'password' => ['type' => 'string', 'required' => false, 'form' => 'password'],
                ],
            ],
            'output' => [
                'fields' => [
                    'status' => ['type' => 'string', 'required' => true],
                    'message' => ['type' => 'string', 'required' => false],
                ],
            ],
            'auth' => [
                'required' => $authRequired,
                'public' => !$authRequired,
                'strategies' => $strategies,
                'permissions' => $permissions,
            ],
            'csrf' => [
                'required' => $csrfRequired,
            ],
            'database' => [
                'reads' => [],
                'writes' => [],
                'transactions' => 'required',
                'queries' => [$feature],
            ],
            'cache' => [
                'reads' => [],
                'writes' => [],
                'invalidate' => [],
            ],
            'events' => [
                'emit' => [],
                'subscribe' => [],
            ],
            'jobs' => [
                'dispatch' => [],
            ],
            'rate_limit' => [
                'strategy' => 'ip',
                'bucket' => $feature,
                'cost' => 1,
            ],
            'tests' => [
                'required' => ['contract', 'feature', 'auth', 'integration'],
            ],
            'ui' => [
                'flash_messages' => true,
                'error_page_pattern' => true,
            ],
        ];
    }

    /**
     * @param array<int,string> $features
     * @return array<int,string>
     */
    private function writeStarterDefinition(string $starter, array $features, ?string $name, bool $force): array
    {
        $dir = $this->paths->join('app/definitions/starters');
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $path = $dir . '/' . $starter . '.starter.yaml';
        if (is_file($path) && !$force) {
            throw new FoundryError(
                'STARTER_DEFINITION_EXISTS',
                'io',
                ['path' => $path],
                'Starter definition already exists. Use --force to overwrite.',
            );
        }

        $document = [
            'version' => 1,
            'starter' => $starter,
            'name' => $name ?? 'foundry-' . $starter,
            'auth_mode' => $starter === 'api' ? 'token' : 'session',
            'features' => array_values(array_unique(array_map('strval', $features))),
            'pipeline_defaults' => [
                'csrf' => $starter !== 'api',
                'rate_limit' => true,
                'validation' => true,
            ],
        ];

        file_put_contents($path, Yaml::dump($document));

        return [$path];
    }

    /**
     * @return array<int,string>
     */
    private function writeStarterMigrations(string $starter, bool $force): array
    {
        $dir = $this->paths->join('database/migrations');
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $migrations = [
            '0010_create_users.sql' => "CREATE TABLE IF NOT EXISTS users (\n    id INTEGER PRIMARY KEY AUTOINCREMENT,\n    email TEXT NOT NULL UNIQUE,\n    password_hash TEXT NOT NULL,\n    email_verified_at TEXT NULL,\n    created_at TEXT NOT NULL\n);\n",
            '0011_create_password_reset_tokens.sql' => "CREATE TABLE IF NOT EXISTS password_reset_tokens (\n    id INTEGER PRIMARY KEY AUTOINCREMENT,\n    email TEXT NOT NULL,\n    token_hash TEXT NOT NULL,\n    expires_at TEXT NOT NULL,\n    created_at TEXT NOT NULL\n);\n",
            '0012_create_email_verifications.sql' => "CREATE TABLE IF NOT EXISTS email_verifications (\n    id INTEGER PRIMARY KEY AUTOINCREMENT,\n    user_id INTEGER NOT NULL,\n    token_hash TEXT NOT NULL,\n    expires_at TEXT NOT NULL,\n    created_at TEXT NOT NULL\n);\n",
        ];

        if ($starter === 'server-rendered') {
            $migrations['0013_create_sessions.sql'] = "CREATE TABLE IF NOT EXISTS sessions (\n    id TEXT PRIMARY KEY,\n    user_id INTEGER NULL,\n    payload TEXT NOT NULL,\n    expires_at TEXT NOT NULL,\n    created_at TEXT NOT NULL\n);\n";
        } else {
            $migrations['0013_create_api_tokens.sql'] = "CREATE TABLE IF NOT EXISTS api_tokens (\n    id INTEGER PRIMARY KEY AUTOINCREMENT,\n    user_id INTEGER NOT NULL,\n    token_hash TEXT NOT NULL UNIQUE,\n    revoked_at TEXT NULL,\n    expires_at TEXT NULL,\n    created_at TEXT NOT NULL\n);\n";
        }

        ksort($migrations);

        $written = [];
        foreach ($migrations as $file => $sql) {
            $path = $dir . '/' . $file;
            if (is_file($path) && !$force) {
                continue;
            }
            file_put_contents($path, $sql);
            $written[] = $path;
        }

        sort($written);

        return $written;
    }
}
