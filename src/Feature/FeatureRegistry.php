<?php
declare(strict_types=1);

namespace Foundry\Feature;

interface FeatureRegistry
{
    /**
     * @return array<string,FeatureDefinition>
     */
    public function all(): array;

    public function has(string $feature): bool;

    public function get(string $feature): FeatureDefinition;
}
