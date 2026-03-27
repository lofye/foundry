# Foundry Extension Author Guide

Foundry 1.0 treats extensions and packs as a stable ecosystem surface. Extension authors should depend on the `extension_api` policy, not on compiler internals outside the documented contract.

## Stable Extension Contract

Implement `Foundry\Compiler\Extensions\CompilerExtension` or extend `AbstractCompilerExtension`.

Stable hooks:

- descriptor metadata through `descriptor()`
- graph integration through stage-specific compiler passes
- projection emitters
- pack declarations
- migration rules and definition formats
- codemods
- graph analyzers for `doctor`
- explain contributors for `foundry explain`
- pipeline stages and interceptors

CLI-facing contributions should be declared through metadata, not inferred from runtime side effects.

## Explain Contributions

`foundry explain` is deterministic and plan-driven. Extensions can add explanation sections by implementing `Foundry\Explain\Contributors\ExplainContributorInterface` and returning `Foundry\Explain\Contributors\ExplainContribution`.

Contributor rules:

- only add deterministic data derived from graph, projections, diagnostics, or extension metadata
- return structured `ExplainSection` rows through `ExplainContribution`, plus related commands or related docs rows when needed
- let `ExplainContributorRegistry` handle contributor selection and deterministic merge order
- do not render human-readable output directly
- do not re-run broad diagnostics unless a contribution explicitly requires it for correctness

This keeps `foundry explain` extensible without coupling renderer output to any single extension. Canonical section ordering remains owned by the assembler; contributor sections render after the canonical sections according to `sectionOrder`.

## Required Metadata

Each extension descriptor must provide:

- `name`
- `version`
- `framework_version_constraint`
- `graph_version_constraint`

Optional descriptor fields include:

- `description`
- provided node types, passes, packs, definition formats, migration rules, codemods, projection outputs, inspect surfaces, verifiers, and capabilities
- `required_extensions`
- `optional_extensions`
- `conflicts_with_extensions`

Each pack must provide:

- `name`
- `version`
- `extension`
- `framework_version_constraint`
- `graph_version_constraint`

Optional pack fields include:

- `description`
- provided and required capabilities
- `dependencies`
- `optional_dependencies`
- `conflicts_with`
- `generators`
- `inspect_surfaces`
- `definition_formats`
- `migration_rules`
- `verifiers`
- `docs_emitters`
- `examples`

Inspect the canonical schemas with:

```bash
foundry inspect extensions --json
```

## Lifecycle

Foundry resolves each extension through these stages:

1. `discovered`
2. `loaded`
3. `validated`
4. `graph_integrated`
5. `runtime_enabled`

`inspect extensions`, `inspect extension <name>`, `inspect compatibility`, `verify extensions`, and `doctor` expose lifecycle and diagnostics data.

## Deterministic Loading Rules

- built-in extensions load first
- explicit registrations from `foundry.extensions.php` and `config/foundry/extensions.php` load next
- duplicate extension ids keep the first discovered registration
- duplicate pack ids keep the first discovered owner
- required extension and pack dependencies must resolve
- conflicts disable the later conflicting registration deterministically
- final runtime order is a dependency-respecting topological order, with ties broken by extension name then version

## Diagnostics

Extension diagnostics are part of the stable authoring workflow.

Common diagnostics include:

- `FDY7010_EXTENSION_REGISTRATION_INVALID`
- `FDY7011_EXTENSION_CLASS_NOT_FOUND`
- `FDY7012_EXTENSION_CLASS_INVALID`
- `FDY7013_EXTENSION_INSTANTIATION_FAILED`
- `FDY7014_EXTENSION_DEPENDENCY_MISSING`
- `FDY7015_EXTENSION_CONFLICT`
- `FDY7016_EXTENSION_METADATA_INVALID`
- `FDY7017_PACK_METADATA_INVALID`
- `FDY7018_PACK_DEPENDENCY_MISSING`
- `FDY7019_EXTENSION_DEPENDENCY_CYCLE`
- `FDY7020_EXTENSION_GRAPH_INTEGRATION_FAILED`
- `FDY7021_DUPLICATE_PACK_ID`
- `FDY7022_PACK_CONFLICT`

Check extension health with:

```bash
foundry inspect extension <name> --json
foundry inspect compatibility --json
foundry verify extensions --json
foundry doctor --json
```

## Example

See `Foundry\Extensions\Demo\DemoCapabilityExtension` and `examples/extensions-migrations` for a minimal extension that declares descriptor metadata, a pack, and an enrich pass.
