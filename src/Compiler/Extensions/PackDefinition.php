<?php
declare(strict_types=1);

namespace Foundry\Compiler\Extensions;

final class PackDefinition
{
    /**
     * @param array<int,string> $providedCapabilities
     * @param array<int,string> $requiredCapabilities
     * @param array<int,string> $generators
     * @param array<int,string> $specFormats
     * @param array<int,string> $migrationRules
     * @param array<int,string> $verifiers
     * @param array<int,string> $docsEmitters
     * @param array<int,string> $examples
     */
    public function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly string $extension,
        public readonly string $description = '',
        public readonly array $providedCapabilities = [],
        public readonly array $requiredCapabilities = [],
        public readonly string $frameworkVersionConstraint = '*',
        public readonly string $graphVersionConstraint = '*',
        public readonly array $generators = [],
        public readonly array $specFormats = [],
        public readonly array $migrationRules = [],
        public readonly array $verifiers = [],
        public readonly array $docsEmitters = [],
        public readonly array $examples = [],
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
            'extension' => $this->extension,
            'description' => $this->description,
            'provided_capabilities' => $this->sortedUnique($this->providedCapabilities),
            'required_capabilities' => $this->sortedUnique($this->requiredCapabilities),
            'framework_version_constraint' => $this->frameworkVersionConstraint,
            'graph_version_constraint' => $this->graphVersionConstraint,
            'generators' => $this->sortedUnique($this->generators),
            'spec_formats' => $this->sortedUnique($this->specFormats),
            'migration_rules' => $this->sortedUnique($this->migrationRules),
            'verifiers' => $this->sortedUnique($this->verifiers),
            'docs_emitters' => $this->sortedUnique($this->docsEmitters),
            'examples' => $this->sortedUnique($this->examples),
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
