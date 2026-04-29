# Feature: extension-system

## Purpose

- Provide the current implementation context for Foundry’s deterministic extension and pack loading subsystem.

## Current State

- `ExtensionRegistry::forPaths()` is the canonical assembly path for extension state.
- The registry always seeds four built-in compiler extensions:
  - `core`
  - `foundation`
  - `integration`
  - `platform`
- Explicit extension registration is implemented through:
  - `foundry.extensions.php`
  - `config/foundry/extensions.php`
- Both registration files are loaded through `ExtensionRegistrationLoader`, and each must return an array of extension class names.
- Installed pack activation is implemented through `LocalPackLoader` and `.foundry/packs/installed.json`.
- Active pack versions are loaded from `.foundry/packs/{vendor}/{pack}/{version}/foundry.json`.
- Pack manifests are validated for pack name format, semantic version format, non-empty descriptions, valid entry class names, SHA-256 checksum presence and format, signature presence and format, and sorted unique capability strings.
- Pack activation verifies that the installed directory exists, the manifest matches the active registry entry, and the manifest checksum matches the installed files.
- Pack providers must implement `Foundry\Packs\PackServiceProvider`.
- `PackContext` currently supports registration of:
  - one compiler extension
  - commands
  - schemas
  - workflows
  - events
  - guards
  - generators
  - docs metadata
- If a pack provider implements `CompilerExtension` and does not call `registerExtension()`, the provider itself becomes the pack extension entrypoint.
- Installed packs are wrapped as `InstalledPackExtension` instances whose extension names use the `pack.<vendor>.<pack>` format.
- Extension descriptors and pack definitions are validated before enablement.
- The registry currently resolves and reports duplicate extension identifiers, invalid extension metadata, invalid pack metadata, missing required extensions, missing required pack dependencies, extension conflicts, duplicate pack ids, pack conflicts, and extension dependency cycles.
- Compatibility reporting currently covers incompatible framework versions, incompatible graph versions, conflicting node-type providers, conflicting projection providers, missing required pack capabilities, and conflicting definition-format versions.
- Lifecycle state is implemented per extension with the stages:
  - `discovered`
  - `loaded`
  - `validated`
  - `graph_integrated`
  - `runtime_enabled`
- Disabled extensions remain visible in inspect and lifecycle output, while only enabled extensions contribute runtime integrations.
- `GraphCompiler` consumes the registry for compatibility diagnostics and extension passes across all compile stages.
- Extension pass exceptions are converted into `FDY7020_EXTENSION_GRAPH_INTEGRATION_FAILED` diagnostics when the failing pass belongs to an extension.
- Pack install, hosted-registry install, search, info, list, and remove operations are implemented today.
- Hosted registry discovery is implemented through `HostedPackRegistry`, cached at `.foundry/cache/registry.json`, and accepts exact `vendor/pack` and `vendor/pack@version` lookups.
- `pack remove` currently deactivates the active version in `.foundry/packs/installed.json` and leaves installed pack files on disk.
- Inspect and verification surfaces currently expose extension-system state through:
  - `inspect extensions`
  - `inspect extension <name>`
  - `inspect packs`
  - `inspect compatibility`
  - `verify extensions`
  - `doctor`
- Explain surfaces currently normalize extension and pack rows from registry-backed metadata, including declared pack contributions and graph-node ownership.
- `GenerateEngine` currently integrates with the extension system by:
  - loading `ExtensionRegistry::forPaths($paths)` before planning and verification
  - resolving pack requirements through the registry `PackRegistry`
  - discovering pack generators through `GeneratorRegistry::forExtensions()`
  - surfacing installed-pack rows and extension rows in explain-backed planning context

## Known Limitations

- Pack contribution declarations for `workflows`, `events`, `guards`, and `docs_metadata` are recorded and surfaced through inspect/explain metadata, but they do not currently create standalone runtime registries the way compiler extensions and pack generators do.
- Cross-pack conflict detection is currently implemented only for declared commands and schemas during local pack loading.
- Hosted registry support covers search, resolve, download, and install; it does not include publishing workflows.
- Pack references resolve only by exact pack name or exact version string. Range-based dependency solving is not implemented.
- Signature fields are stored and validated for shape, but cryptographic signature verification is not implemented.
- Pack activation executes provider code in the current PHP process after manifest and checksum validation. No sandbox isolation layer exists.
- `pack remove` is deactivate-only. The current implementation does not delete installed pack directories.

## Open Questions

- Whether manifest signatures will remain informational metadata or gain cryptographic verification is unresolved.
- Whether `PackContext` contributions other than compiler extensions and generators will become executable framework registries or remain metadata-only is unresolved.
- Whether pack removal should continue to be deactivate-only or gain an explicit uninstall operation is unresolved.

## Next Steps

- Decide the long-term contract for manifest signature verification.
- Decide the execution contract for non-generator `PackContext` contribution types.
- Decide whether uninstall semantics should be added separately from deactivation.
