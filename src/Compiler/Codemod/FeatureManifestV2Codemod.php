<?php

declare(strict_types=1);

namespace Foundry\Compiler\Codemod;

use Foundry\Compiler\Migration\DefinitionMigrator;
use Foundry\Compiler\Migration\FeatureManifestV2Rule;
use Foundry\Compiler\Migration\ManifestVersionResolver;
use Foundry\Support\Paths;

final class FeatureManifestV2Codemod implements Codemod
{
    public function id(): string
    {
        return 'feature-manifest-v1-to-v2';
    }

    public function description(): string
    {
        return 'Rewrites feature manifests to v2 shape (llm.risk_level, auth.strategies, route method normalization).';
    }

    public function sourceType(): string
    {
        return 'feature_manifest';
    }

    public function run(Paths $paths, bool $write = false, ?string $path = null): CodemodResult
    {
        $migrator = new DefinitionMigrator(
            paths: $paths,
            resolver: new ManifestVersionResolver(),
            rules: [new FeatureManifestV2Rule()],
            formats: [],
        );

        $result = $migrator->migrate($write, $path);

        return new CodemodResult(
            codemod: $this->id(),
            written: $write,
            changes: $result->changes,
            diagnostics: $result->diagnostics,
            pathFilter: $path,
        );
    }
}
