<?php
declare(strict_types=1);

namespace Foundry\Compiler\Extensions;

final class ExtensionDescriptor
{
    /**
     * @param array<int,string> $providedNodeTypes
     * @param array<int,string> $providedPasses
     * @param array<int,string> $providedPacks
     * @param array<int,string> $introducedSpecFormats
     * @param array<int,string> $providedMigrationRules
     * @param array<int,string> $providedCodemods
     * @param array<int,string> $providedProjectionOutputs
     * @param array<int,string> $providedInspectSurfaces
     * @param array<int,string> $providedVerifiers
     * @param array<int,string> $providedCapabilities
     */
    public function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly string $description = '',
        public readonly string $frameworkVersionConstraint = '*',
        public readonly string $graphVersionConstraint = '*',
        public readonly array $providedNodeTypes = [],
        public readonly array $providedPasses = [],
        public readonly array $providedPacks = [],
        public readonly array $introducedSpecFormats = [],
        public readonly array $providedMigrationRules = [],
        public readonly array $providedCodemods = [],
        public readonly array $providedProjectionOutputs = [],
        public readonly array $providedInspectSurfaces = [],
        public readonly array $providedVerifiers = [],
        public readonly array $providedCapabilities = [],
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'version' => $this->version,
            'description' => $this->description,
            'framework_version_constraint' => $this->frameworkVersionConstraint,
            'graph_version_constraint' => $this->graphVersionConstraint,
            'provides' => [
                'node_types' => $this->sortedUnique($this->providedNodeTypes),
                'passes' => $this->sortedUnique($this->providedPasses),
                'packs' => $this->sortedUnique($this->providedPacks),
                'spec_formats' => $this->sortedUnique($this->introducedSpecFormats),
                'migration_rules' => $this->sortedUnique($this->providedMigrationRules),
                'codemods' => $this->sortedUnique($this->providedCodemods),
                'projection_outputs' => $this->sortedUnique($this->providedProjectionOutputs),
                'inspect_surfaces' => $this->sortedUnique($this->providedInspectSurfaces),
                'verifiers' => $this->sortedUnique($this->providedVerifiers),
                'capabilities' => $this->sortedUnique($this->providedCapabilities),
            ],
        ];
    }

    /**
     * @param array<int,string> $values
     * @return array<int,string>
     */
    private function sortedUnique(array $values): array
    {
        $values = array_values(array_filter(array_map('strval', $values), static fn (string $value): bool => $value !== ''));
        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }
}
