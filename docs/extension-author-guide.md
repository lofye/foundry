# Foundry Extension Author Guide

Foundry 1.0 treats extensions and packs as a stable ecosystem surface. Extension authors should depend on the `extension_api` policy, not on compiler internals outside the documented contract.

For the broader framework contributor workflow, architecture model, and PR checklist, start with [Contributor Portal](contributor-portal.md).

## Stable Extension Contract

Implement `Foundry\Compiler\Extensions\CompilerExtension` or extend `AbstractCompilerExtension` for direct extension registrations. Implement `Foundry\Packs\PackServiceProvider` for locally installed packs.

Stable hooks:

- descriptor metadata through `descriptor()`
- graph integration through stage-specific compiler passes
- projection emitters
- local pack registration through `Foundry\Packs\PackContext`
- migration rules and definition formats
- codemods
- graph analyzers for `doctor`
- explain contributors for `foundry explain`
- pipeline stages and interceptors

Explain context collectors are currently framework-owned inputs wired through `src/Explain/ExplainEngineFactory.php`. Add a collector there only when extending core explain inputs; extension-facing customization remains section contributions through `ExplainContributorInterface`.

CLI-facing pack contributions should be declared through `PackContext`, not inferred from runtime side effects.

## Explain Contributions

`foundry explain` is deterministic and plan-driven. Extensions can add explanation sections by implementing `Foundry\Explain\Contributors\ExplainContributorInterface` and returning `Foundry\Explain\Contributors\ExplainContribution`.

Contributor rules:

- only add deterministic data derived from graph, projections, diagnostics, or extension metadata
- return structured `ExplainSection` rows through `ExplainContribution`, plus related commands or related docs rows when needed
- let `ExplainContributorRegistry` handle contributor selection and deterministic merge order
- do not render human-readable output directly
- do not re-run broad diagnostics unless a contribution explicitly requires it for correctness

This keeps `foundry explain` extensible without coupling renderer output to any single extension. Canonical section ordering remains owned by the assembler; contributor sections render after the canonical sections according to `sectionOrder`.

## Local Pack Manifest

Every local pack must ship a `foundry.json` manifest at the pack root:

```json
{
  "name": "vendor/pack-name",
  "version": "1.0.0",
  "description": "string",
  "entry": "Vendor\\Pack\\PackServiceProvider",
  "capabilities": []
}
```

Required manifest rules:

- `name` must match `vendor/pack-name`
- `version` must be semantic versioning
- `description` must be non-empty
- `entry` must be a valid class name implementing `Foundry\Packs\PackServiceProvider`
- `capabilities` must be an array of strings

Installed pack files are copied into `.foundry/packs/{vendor}/{pack}/{version}/`, and active versions are recorded in `.foundry/packs/installed.json`.

If a pack needs framework constraints, graph constraints, passes, projection emitters, or other extension behavior, expose those through the compiler extension registered by the pack provider, not through extra manifest keys.

Pack providers register explicitly through `Foundry\Packs\PackContext`. Current pack-context contribution declarations include:

- commands
- schemas
- workflows
- events
- guards
- generators
- docs metadata

A pack provider may either:

- call `$context->registerExtension(...)` with a `CompilerExtension`
- or implement `CompilerExtension` itself, in which case Foundry wraps it automatically as an installed pack extension

## Hosted Registry Publishing Contract

Hosted discovery is intentionally minimal and read-only. Foundry expects a public `registry.json` endpoint whose rows look like:

```json
{
  "name": "vendor/pack",
  "version": "1.0.0",
  "description": "Short description",
  "download_url": "https://example.com/packs/vendor-pack-1.0.0.zip"
}
```

Publishing rules:

- `download_url` must use HTTPS
- each row represents one version
- duplicate `name` + `version` pairs are invalid
- archive downloads must be `.zip` files with `foundry.json` at the archive root
- hosted install still runs the same local manifest validation and pack activation flow after extraction

## Extension Descriptor Metadata

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
- active local packs are then loaded from `.foundry/packs/installed.json`
- local pack discovery never uses filesystem order; active packs are sorted by pack name and active version
- graph boot uses only active pack versions
- declared command and schema contributions must remain unique across active packs
- duplicate graph node identifiers fail during graph integration instead of being overridden silently
- final runtime order remains deterministic under the extension registry

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
- `PACK_MANIFEST_INVALID`
- `PACK_REGISTRY_CORRUPT`
- `PACK_REGISTRY_INVALID`
- `PACK_SOURCE_MISSING`
- `PACK_ENTRY_CLASS_NOT_FOUND`
- `PACK_ENTRY_INVALID`
- `PACK_ENTRY_INSTANTIATION_FAILED`
- `PACK_COMMAND_CONFLICT`
- `PACK_SCHEMA_CONFLICT`
- `PACK_ACTIVATION_FAILED`

Check extension health with:

```bash
foundry pack list --json
foundry pack info vendor/pack --json
foundry inspect extension <name> --json
foundry inspect packs --json
foundry inspect compatibility --json
foundry verify extensions --json
foundry doctor --json
```

## Example

See `Foundry\Extensions\Demo\DemoCapabilityExtension` and `examples/extensions-migrations` for a minimal extension that declares descriptor metadata, a pack, and an enrich pass.
