<?php
declare(strict_types=1);

namespace Foundry\Upgrade;

final class FrameworkDeprecationRegistry
{
    /**
     * @var array<string,DeprecationMetadata>|null
     */
    private ?array $entries = null;

    /**
     * @return array<int,DeprecationMetadata>
     */
    public function all(): array
    {
        return array_values($this->entries());
    }

    public function get(string $id): ?DeprecationMetadata
    {
        return $this->entries()[$id] ?? null;
    }

    /**
     * @return array<string,DeprecationMetadata>
     */
    private function entries(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }

        return $this->entries = [
            'config.compatibility_alias' => new DeprecationMetadata(
                id: 'config.compatibility_alias',
                title: 'Config compatibility aliases',
                severity: 'warning',
                category: 'config',
                introducedIn: '0.4.0',
                removalVersion: '1.0.0',
                whyItMatters: 'Compatibility aliases keep older config keys working today, but the 1.0 upgrade path expects canonical schema keys.',
                migration: 'Rename legacy config keys to their canonical schema paths and keep only the normalized shape in source control.',
                reference: 'docs/upgrade-safety.md#config-compatibility-aliases',
            ),
            'cli.init_app' => new DeprecationMetadata(
                id: 'cli.init_app',
                title: 'Legacy init app CLI alias',
                severity: 'warning',
                category: 'cli',
                introducedIn: '0.4.0',
                removalVersion: '1.0.0',
                whyItMatters: 'Automation that still calls the legacy `init app` alias may break once upgrade cleanup removes that compatibility alias.',
                migration: 'Replace `init app` with `new` in scripts, docs, and onboarding snippets.',
                reference: 'docs/upgrade-safety.md#legacy-cli-aliases',
            ),
            'feature_manifest.v1' => new DeprecationMetadata(
                id: 'feature_manifest.v1',
                title: 'Feature manifest v1',
                severity: 'warning',
                category: 'migrations',
                introducedIn: '0.4.0',
                removalVersion: '1.0.0',
                whyItMatters: 'Feature manifest v2 is the canonical schema; v1 manifests need migration before the 1.0 upgrade boundary.',
                migration: 'Run the definition migrator, review the planned changes, and commit the upgraded feature manifests before upgrading the framework.',
                reference: 'docs/upgrade-safety.md#feature-manifest-v1',
            ),
            'compiler.legacy_projection_fallback' => new DeprecationMetadata(
                id: 'compiler.legacy_projection_fallback',
                title: 'Legacy projection fallback',
                severity: 'warning',
                category: 'compiler',
                introducedIn: '0.4.0',
                removalVersion: '1.0.0',
                whyItMatters: 'If runtime metadata falls back to `app/generated/*` without matching build projections, compiler/runtime upgrades are riskier and harder to verify.',
                migration: 'Recompile the graph so `app/.foundry/build/projections/*` exists for each generated compatibility projection, then stop depending on `app/generated/*` directly.',
                reference: 'docs/upgrade-safety.md#legacy-projection-fallback',
            ),
        ];
    }
}
