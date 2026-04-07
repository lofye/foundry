<?php

declare(strict_types=1);

namespace Foundry\Context;

final class ContextFileResolver
{
    public function specPath(string $featureName): string
    {
        return 'docs/features/' . $featureName . '.spec.md';
    }

    public function statePath(string $featureName): string
    {
        return 'docs/features/' . $featureName . '.md';
    }

    public function decisionsPath(string $featureName): string
    {
        return 'docs/features/' . $featureName . '.decisions.md';
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
