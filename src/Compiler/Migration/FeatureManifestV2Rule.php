<?php

declare(strict_types=1);

namespace Foundry\Compiler\Migration;

final class FeatureManifestV2Rule implements MigrationRule
{
    public function id(): string
    {
        return 'FDY_MIGRATE_FEATURE_MANIFEST_V2';
    }

    public function description(): string
    {
        return 'Upgrade feature manifests to version 2 (llm.risk -> llm.risk_level, normalize auth strategies and route method).';
    }

    public function sourceType(): string
    {
        return 'feature_manifest';
    }

    public function fromVersion(): int
    {
        return 1;
    }

    public function toVersion(): int
    {
        return 2;
    }

    /**
     * @param array<string,mixed> $document
     */
    public function applies(string $path, array $document): bool
    {
        if (!str_ends_with($path, '/feature.yaml')) {
            return false;
        }

        $version = $document['version'] ?? 1;
        if ((is_int($version) && $version < 2) || (!is_int($version) && (int) $version < 2)) {
            return true;
        }

        $llm = is_array($document['llm'] ?? null) ? $document['llm'] : [];
        if (isset($llm['risk']) && !isset($llm['risk_level'])) {
            return true;
        }

        $auth = is_array($document['auth'] ?? null) ? $document['auth'] : [];
        if (isset($auth['strategy']) && !isset($auth['strategies'])) {
            return true;
        }

        $route = is_array($document['route'] ?? null) ? $document['route'] : [];

        return isset($route['method']) && strtoupper((string) $route['method']) !== (string) $route['method'];
    }

    /**
     * @param array<string,mixed> $document
     * @return array<string,mixed>
     */
    public function migrate(string $path, array $document): array
    {
        $next = $document;
        $next['version'] = 2;

        $llm = is_array($next['llm'] ?? null) ? $next['llm'] : [];
        if (isset($llm['risk']) && !isset($llm['risk_level'])) {
            $llm['risk_level'] = (string) $llm['risk'];
            unset($llm['risk']);
        }
        if ($llm !== []) {
            $next['llm'] = $llm;
        }

        $auth = is_array($next['auth'] ?? null) ? $next['auth'] : [];
        if (isset($auth['strategy']) && !isset($auth['strategies'])) {
            $value = (string) $auth['strategy'];
            $auth['strategies'] = $value !== '' ? [$value] : [];
            unset($auth['strategy']);
        }
        if ($auth !== []) {
            $next['auth'] = $auth;
        }

        $route = is_array($next['route'] ?? null) ? $next['route'] : [];
        if (isset($route['method'])) {
            $route['method'] = strtoupper((string) $route['method']);
            $next['route'] = $route;
        }

        return $next;
    }
}
