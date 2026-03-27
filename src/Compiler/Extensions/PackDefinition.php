<?php
declare(strict_types=1);

namespace Foundry\Compiler\Extensions;

use Foundry\Config\ConfigSchemaCatalog;

final class PackDefinition
{
    /**
     * @param array<int,string> $providedCapabilities
     * @param array<int,string> $requiredCapabilities
     * @param array<int,string> $dependencies
     * @param array<int,string> $optionalDependencies
     * @param array<int,string> $conflictsWith
     * @param array<int,string> $generators
     * @param array<int,string> $inspectSurfaces
     * @param array<int,string> $definitionFormats
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
        public readonly array $dependencies = [],
        public readonly array $optionalDependencies = [],
        public readonly array $conflictsWith = [],
        public readonly string $frameworkVersionConstraint = '*',
        public readonly string $graphVersionConstraint = '*',
        public readonly array $generators = [],
        public readonly array $inspectSurfaces = [],
        public readonly array $definitionFormats = [],
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
            'schema_version' => 1,
            'name' => $this->name,
            'version' => $this->version,
            'extension' => $this->extension,
            'description' => $this->description,
            'provided_capabilities' => $this->sortedUnique($this->providedCapabilities),
            'required_capabilities' => $this->sortedUnique($this->requiredCapabilities),
            'dependencies' => $this->sortedUnique($this->dependencies),
            'optional_dependencies' => $this->sortedUnique($this->optionalDependencies),
            'conflicts_with' => $this->sortedUnique($this->conflictsWith),
            'framework_version_constraint' => $this->frameworkVersionConstraint,
            'graph_version_constraint' => $this->graphVersionConstraint,
            'generators' => $this->sortedUnique($this->generators),
            'inspect_surfaces' => $this->sortedUnique($this->inspectSurfaces),
            'definition_formats' => $this->sortedUnique($this->definitionFormats),
            'migration_rules' => $this->sortedUnique($this->migrationRules),
            'verifiers' => $this->sortedUnique($this->verifiers),
            'docs_emitters' => $this->sortedUnique($this->docsEmitters),
            'examples' => $this->sortedUnique($this->examples),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function schema(): array
    {
        return (new ConfigSchemaCatalog())->schemas()['extension.pack'];
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
