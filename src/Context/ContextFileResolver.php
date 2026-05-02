<?php

declare(strict_types=1);

namespace Foundry\Context;

use Foundry\Support\FeatureNaming;

final class ContextFileResolver
{
    public function specPath(string $featureName): string
    {
        $featureName = FeatureNaming::canonical($featureName);

        return 'docs/features/' . $featureName . '/' . $featureName . '.spec.md';
    }

    public function statePath(string $featureName): string
    {
        $featureName = FeatureNaming::canonical($featureName);

        return 'docs/features/' . $featureName . '/' . $featureName . '.md';
    }

    public function decisionsPath(string $featureName): string
    {
        $featureName = FeatureNaming::canonical($featureName);

        return 'docs/features/' . $featureName . '/' . $featureName . '.decisions.md';
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
}
