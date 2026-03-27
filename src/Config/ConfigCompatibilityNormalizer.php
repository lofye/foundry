<?php
declare(strict_types=1);

namespace Foundry\Config;

final class ConfigCompatibilityNormalizer
{
    /**
     * @param array<string,mixed> $config
     * @return array{normalized:array<string,mixed>,issues:array<int,ConfigValidationIssue>}
     */
    public function normalize(string $schemaId, array $config, string $sourcePath): array
    {
        return match ($schemaId) {
            'config.database' => $this->normalizeDatabase($config, $sourcePath),
            'config.storage' => $this->normalizeStorage($config, $sourcePath),
            'config.ai' => $this->normalizeAi($config, $sourcePath),
            default => ['normalized' => $config, 'issues' => []],
        };
    }

    /**
     * @param array<string,mixed> $config
     * @return array{normalized:array<string,mixed>,issues:array<int,ConfigValidationIssue>}
     */
    private function normalizeDatabase(array $config, string $sourcePath): array
    {
        $normalized = $config;
        $issues = [];

        $legacyConnections = [];
        foreach ($config as $key => $value) {
            if (in_array($key, ['default', 'connections'], true)) {
                continue;
            }

            if (is_array($value) && array_key_exists('dsn', $value)) {
                $legacyConnections[$key] = $value;
                unset($normalized[$key]);
                $issues[] = new ConfigValidationIssue(
                    code: 'FDY1704_CONFIG_COMPATIBILITY_ALIAS_USED',
                    severity: 'warning',
                    category: 'config',
                    schemaId: 'config.database',
                    message: sprintf('Legacy database connection key %s was normalized into $.connections.%s.', $key, $key),
                    sourcePath: $sourcePath,
                    configPath: '$.' . $key,
                    expected: '$.connections.' . $key,
                    actual: '$.' . $key,
                    suggestedFix: 'Move the connection config under $.connections.' . $key . '.',
                );
            }
        }

        if ($legacyConnections !== []) {
            $connections = is_array($normalized['connections'] ?? null) ? $normalized['connections'] : [];
            $normalized['connections'] = array_merge($legacyConnections, $connections);
        }

        return ['normalized' => $normalized, 'issues' => $issues];
    }

    /**
     * @param array<string,mixed> $config
     * @return array{normalized:array<string,mixed>,issues:array<int,ConfigValidationIssue>}
     */
    private function normalizeStorage(array $config, string $sourcePath): array
    {
        $normalized = $config;
        $issues = [];

        if (isset($config['local_root']) && !isset($config['root'])) {
            $normalized['root'] = $config['local_root'];
            $issues[] = new ConfigValidationIssue(
                code: 'FDY1704_CONFIG_COMPATIBILITY_ALIAS_USED',
                severity: 'warning',
                category: 'config',
                schemaId: 'config.storage',
                message: 'Legacy storage key $.local_root was normalized into $.root.',
                sourcePath: $sourcePath,
                configPath: '$.local_root',
                expected: '$.root',
                actual: '$.local_root',
                suggestedFix: 'Rename $.local_root to $.root.',
            );
        }

        unset($normalized['local_root']);

        return ['normalized' => $normalized, 'issues' => $issues];
    }

    /**
     * @param array<string,mixed> $config
     * @return array{normalized:array<string,mixed>,issues:array<int,ConfigValidationIssue>}
     */
    private function normalizeAi(array $config, string $sourcePath): array
    {
        $normalized = $config;
        $issues = [];

        if (isset($config['default_provider']) && !isset($config['default'])) {
            $normalized['default'] = $config['default_provider'];
            $issues[] = new ConfigValidationIssue(
                code: 'FDY1704_CONFIG_COMPATIBILITY_ALIAS_USED',
                severity: 'warning',
                category: 'config',
                schemaId: 'config.ai',
                message: 'Legacy AI config key $.default_provider was normalized into $.default.',
                sourcePath: $sourcePath,
                configPath: '$.default_provider',
                expected: '$.default',
                actual: '$.default_provider',
                suggestedFix: 'Rename $.default_provider to $.default.',
            );
        }

        unset($normalized['default_provider']);

        return ['normalized' => $normalized, 'issues' => $issues];
    }
}
