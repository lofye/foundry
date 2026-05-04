<?php

declare(strict_types=1);

namespace Foundry\Context;

use Foundry\Support\FeatureNaming;

final class ContextFileResolver
{
    public function __construct(
        private readonly ?string $workspaceRoot = null,
    ) {}

    public function legacySpecPath(string $featureName): string
    {
        $featureName = FeatureNaming::canonical($featureName);

        return 'docs/features/' . $featureName . '/' . $featureName . '.spec.md';
    }

    public function canonicalSpecPath(string $featureName): string
    {
        $featureName = FeatureNaming::canonical($featureName);

        return 'Features/' . $this->pascalFromSlug($featureName) . '/' . $featureName . '.spec.md';
    }

    public function specPath(string $featureName): string
    {
        return $this->preferredPath(
            canonical: $this->canonicalSpecPath($featureName),
            legacy: $this->legacySpecPath($featureName),
        );
    }

    public function legacyStatePath(string $featureName): string
    {
        $featureName = FeatureNaming::canonical($featureName);

        return 'docs/features/' . $featureName . '/' . $featureName . '.md';
    }

    public function canonicalStatePath(string $featureName): string
    {
        $featureName = FeatureNaming::canonical($featureName);

        return 'Features/' . $this->pascalFromSlug($featureName) . '/' . $featureName . '.md';
    }

    public function statePath(string $featureName): string
    {
        return $this->preferredPath(
            canonical: $this->canonicalStatePath($featureName),
            legacy: $this->legacyStatePath($featureName),
        );
    }

    public function legacyDecisionsPath(string $featureName): string
    {
        $featureName = FeatureNaming::canonical($featureName);

        return 'docs/features/' . $featureName . '/' . $featureName . '.decisions.md';
    }

    public function canonicalDecisionsPath(string $featureName): string
    {
        $featureName = FeatureNaming::canonical($featureName);

        return 'Features/' . $this->pascalFromSlug($featureName) . '/' . $featureName . '.decisions.md';
    }

    public function decisionsPath(string $featureName): string
    {
        return $this->preferredPath(
            canonical: $this->canonicalDecisionsPath($featureName),
            legacy: $this->legacyDecisionsPath($featureName),
        );
    }

    /**
     * @return array{spec:string,state:string,decisions:string}
     */
    public function paths(string $featureName): array
    {
        return [
            'spec' => $this->specPath($featureName),
            'state' => $this->statePath($featureName),
            'decisions' => $this->decisionsPath($featureName),
        ];
    }

    /**
     * @return array{spec:string,state:string,decisions:string}
     */
    public function canonicalPaths(string $featureName): array
    {
        return [
            'spec' => $this->canonicalSpecPath($featureName),
            'state' => $this->canonicalStatePath($featureName),
            'decisions' => $this->canonicalDecisionsPath($featureName),
        ];
    }

    /**
     * @return array{spec:string,state:string,decisions:string}
     */
    public function legacyPaths(string $featureName): array
    {
        return [
            'spec' => $this->legacySpecPath($featureName),
            'state' => $this->legacyStatePath($featureName),
            'decisions' => $this->legacyDecisionsPath($featureName),
        ];
    }

    private function pascalFromSlug(string $slug): string
    {
        $parts = array_filter(explode('-', $slug), static fn(string $part): bool => $part !== '');

        return implode('', array_map(static fn(string $part): string => ucfirst($part), $parts));
    }

    private function preferredPath(string $canonical, string $legacy): string
    {
        if ($this->isFile($canonical)) {
            return $canonical;
        }

        if ($this->isFile($legacy)) {
            return $legacy;
        }

        if ($this->isDirectory(dirname($canonical))) {
            return $canonical;
        }

        if ($this->isDirectory(dirname($legacy))) {
            return $legacy;
        }

        return $legacy;
    }

    private function isFile(string $relativePath): bool
    {
        $absolutePath = $this->absolutePath($relativePath);
        if ($absolutePath === null) {
            return false;
        }

        return is_file($absolutePath);
    }

    private function isDirectory(string $relativePath): bool
    {
        $absolutePath = $this->absolutePath($relativePath);
        if ($absolutePath === null) {
            return false;
        }

        return is_dir($absolutePath);
    }

    private function absolutePath(string $relativePath): ?string
    {
        if ($this->workspaceRoot === null || $this->workspaceRoot === '') {
            return null;
        }

        return rtrim($this->workspaceRoot, '/\\') . '/' . ltrim($relativePath, '/\\');
    }
}
