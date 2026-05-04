# Feature Spec: extension-system

## Purpose

- Define the deterministic extension contract that lets Foundry load framework extensions and installed packs into compiler, generate, inspect, explain, doctor, and pipeline workflows.
- Keep extension registration explicit, inspectable, versioned, and compatible with the graph-first compilation model.
- Treat extension metadata, load order, compatibility, and lifecycle state as first-class framework data rather than hidden runtime behavior.

## Goals

- Provide one canonical registry that assembles built-in extensions, explicit extension registrations, and active installed packs.
- Guarantee deterministic extension discovery, validation, conflict handling, load ordering, and lifecycle reporting for the same repository state.
- Let extensions contribute compiler passes, packs, migration rules, codemods, projection emitters, doctor checks, graph analyzers, pipeline stages, pipeline interceptors, and inspectable metadata through stable contracts.
- Let installed packs register one compiler extension entrypoint plus declared pack contributions through a narrow `PackContext`.
- Expose extension and pack state consistently through compile diagnostics, inspect, explain, doctor, and generate surfaces.
- Allow GenerateEngine to discover pack-provided generators and pack capability metadata without creating a second extension subsystem.

## Non-Goals

- The extension system does not perform automatic filesystem discovery outside explicit registration files and the installed-pack registry.
- The extension system does not define a publishing workflow for hosted registries.
- The extension system does not execute remote code.
- The extension system does not silently resolve duplicate extension identifiers, missing dependencies, conflicts, or graph integration failures.
- The extension system does not infer runtime registrations from undocumented side effects.

## Core Concepts

### Compiler Extension

- A compiler extension is a PHP implementation of `Foundry\Compiler\Extensions\CompilerExtension`.
- A compiler extension owns a stable extension name and version, exposes an `ExtensionDescriptor`, and may contribute stage-specific compiler passes plus related framework integrations.

### Extension Descriptor

- `Foundry\Compiler\Extensions\ExtensionDescriptor` is the canonical metadata contract for an extension.
- The descriptor declares the extension identifier, version, version constraints, provided surfaces, required extensions, optional extensions, and extension conflicts.

### Pack Definition

- `Foundry\Compiler\Extensions\PackDefinition` is the canonical metadata contract for pack capabilities contributed by an extension.
- A pack definition declares pack ownership, capabilities, pack dependencies, optional pack dependencies, conflicts, version constraints, generator ids, inspect surfaces, definition formats, migration rules, verifiers, docs emitters, and examples.

### Pack Manifest

- `foundry.json` is the canonical manifest for an installed pack source tree.
- The manifest declares the pack name, version, description, entry class, capabilities, checksum, and signature.

### Pack Service Provider

- `Foundry\Packs\PackServiceProvider` is the canonical entrypoint contract for an installed pack.
- A provider receives `Foundry\Packs\PackContext` and may register one compiler extension entrypoint plus declared pack contributions.

### Pack Context

- `Foundry\Packs\PackContext` is the only supported registration surface for a pack provider.
- `PackContext` may register:
  - one compiler extension
  - commands
  - schemas
  - workflows
  - events
  - guards
  - generators
  - docs metadata

### Extension Registry

- `Foundry\Compiler\Extensions\ExtensionRegistry` is the canonical in-memory assembly of all discovered extensions.
- The registry owns validation, conflict resolution, dependency handling, lifecycle state, deterministic load order, compatibility reporting, and surfaced integration rows.

## Architecture

### Inputs

- Built-in framework extensions registered directly by `ExtensionRegistry::forPaths()`.
- Explicit extension registration arrays from:
  - `foundry.extensions.php`
  - `config/foundry/extensions.php`
- Active installed packs recorded in `.foundry/packs/installed.json` and stored under `.foundry/packs/{vendor}/{pack}/{version}/`.

### Internal Boundaries

- `ExtensionRegistrationLoader` only loads explicit class registrations from supported PHP files.
- `LocalPackLoader` only loads active installed packs from the installed-pack registry and validates their manifests, checksums, and providers before activation.
- `ExtensionMetadataValidator` validates extension descriptors and pack definitions before runtime enablement.
- `ExtensionRegistry` resolves duplicates, dependencies, conflicts, pack ownership, lifecycle state, and final load order.
- `CompatibilityChecker` evaluates framework-version, graph-version, capability, node-type, projection, and definition-format compatibility from the enabled registry state.

### Outbound Integration Boundaries

- `GraphCompiler` consumes the registry for compiler passes, compatibility diagnostics, pack rows, and extension-owned graph integration.
- `GenerateEngine` consumes the registry for pack capability inspection, pack requirement resolution, extension rows in explain snapshots, and pack-provided generators through `GeneratorRegistry::forExtensions()`.
- Inspect, explain, and doctor surfaces consume registry rows, diagnostics, lifecycle state, compatibility reports, and pack metadata.
- Pack install and removal commands consume pack-management services but do not bypass registry validation when a graph rebuild is requested.

## Lifecycle

### 1. Registration

- Built-in extensions are registered first in fixed framework order.
- Explicit extension registration files are then read in fixed path order.
- Active installed packs are then read from `.foundry/packs/installed.json`, sorted by pack name and active version, and activated through `LocalPackLoader`.
- A registration source is valid only when it is one of the supported files or the installed-pack registry.

### 2. Discovery

- Explicit registration files must return arrays of extension class names.
- Each registered extension class must exist, instantiate successfully, and implement `CompilerExtension`.
- Each active pack must have:
  - an install directory
  - a valid `foundry.json`
  - a checksum that matches the installed files
  - an entry class that exists, instantiates, and implements `PackServiceProvider`
- A valid pack manifest must include:
  - a non-empty description
  - a 64-character SHA-256 checksum
  - a signature value that is either `null` or a non-empty string
  - non-empty capability strings with deterministic uniqueness
- Extension descriptors and pack definitions are validated before enablement.
- A pack provider may register one compiler extension through `PackContext`.
- If a pack provider implements `CompilerExtension` and does not call `registerExtension()`, the provider itself becomes the pack extension entrypoint.

### 3. Validation

- Every discovered extension descriptor and pack definition is validated before runtime enablement.
- Invalid metadata produces extension diagnostics and blocks enablement.
- Duplicate extension identifiers disable later duplicates.
- Missing required extensions disable the dependent extension.
- Missing required pack dependencies disable the dependent extension.
- Declared extension conflicts disable the deterministic loser.
- Duplicate pack ids disable the deterministic loser.
- Declared pack conflicts disable the deterministic loser.
- Dependency cycles disable every extension participating in the cycle.
- Declared pack command conflicts and schema conflicts produce diagnostics during pack loading and remain visible through compile and inspect surfaces.

### 4. Runtime Enablement

- The enabled registry is topologically ordered from required and optional extension dependencies plus required and optional pack dependencies.
- Only enabled extensions contribute runtime integrations.
- Disabled extensions remain inspectable with diagnostics and lifecycle state.

### 5. Execution

- `GraphCompiler` executes enabled extension passes by compiler stage in deterministic order.
- Extension pass exceptions are converted into `FDY7020_EXTENSION_GRAPH_INTEGRATION_FAILED` diagnostics when the failing pass belongs to an extension.
- Enabled extensions contribute migration rules, definition formats, codemods, projection emitters, graph analyzers, doctor checks, pipeline stages, and pipeline interceptors through the registry.
- Enabled installed packs contribute pack metadata and pack-provided generators through the registry.

## Integration Points

### Compiler

- The graph compiler must construct or receive `ExtensionRegistry::forPaths($paths)` before compiling the graph.
- Compiler compatibility diagnostics must include extension and pack compatibility failures.
- Enabled extension passes participate in the `discovery`, `normalize`, `link`, `validate`, `enrich`, `analyze`, and `emit` stages only through the registry.

### Graph

- Extension-provided graph behavior is valid only through registered compiler passes and compatible metadata.
- Duplicate graph node insertion or other extension-caused graph failures must surface as extension diagnostics rather than silent override behavior.
- Inspect surfaces must expose extension descriptors, lifecycle state, diagnostics, load order, and pack rows derived from the registry.

### GenerateEngine

- `GenerateEngine` must resolve extension state through `ExtensionRegistry::forPaths($paths)` before planning and before post-apply verification snapshots.
- `GenerateEngine` resolves pack requirements through the registry `PackRegistry`.
- Pack capability and pack-installation decisions must use the registry’s `PackRegistry`.
- Pack-provided generators must be discovered only from enabled `InstalledPackExtension` entries through `GeneratorRegistry::forExtensions()`.
- Generate explain snapshots and plan context must surface installed pack rows and extension rows derived from the registry instead of ad hoc pack inspection.

## Constraints

- Extension behavior must be deterministic for the same repository contents, installed-pack state, and framework version.
- Extension discovery must be explicit. No other files or directories may be scanned as extension registration sources.
- Pack activation must be checksum-gated and manifest-gated before provider execution.
- A pack may register at most one compiler extension entrypoint.
- Registration diagnostics and lifecycle rows must remain available even when an extension is disabled.
- Only enabled extensions may contribute runtime integrations.
- Duplicate extension ids, missing dependencies, conflicts, invalid metadata, and dependency cycles must never be resolved silently.
- Hosted registry lookups must remain data-only; code becomes executable only after archive download, extraction, local manifest validation, and normal local pack activation.
- Remove operations deactivate packs; they do not implicitly delete installed pack files.
- JSON inspection output must remain complete enough to reconstruct registration sources, diagnostics, lifecycle state, compatibility state, and load order.

## Expected Behavior

- Foundry always includes the built-in `core`, `foundation`, `integration`, and `platform` compiler extensions in the registry baseline.
- `foundry.extensions.php` and `config/foundry/extensions.php` may each return explicit extension class names, and invalid payloads produce structured diagnostics.
- `.foundry/packs/installed.json` is the only source of active installed pack activation.
- Installed packs are loaded in deterministic pack-name and version order, not filesystem order.
- Every active installed pack is wrapped as an installed pack extension named `pack.<vendor>.<pack>`.
- Installed pack manifests must match the active registry entry name and version exactly.
- Installed pack manifests must reject empty descriptions, invalid checksum fields, invalid signature fields, and duplicate or empty capability values.
- `PackContext` supports registration of commands, schemas, workflows, events, guards, generators, and docs metadata.
- If a pack provider implements `CompilerExtension` and does not call `registerExtension()`, the provider itself becomes the pack extension entrypoint.
- Extension descriptors and pack definitions are validated before enablement.
- Installed pack commands and schemas are checked for cross-pack conflicts during local pack loading.
- Extension lifecycle rows must expose the stages `discovered`, `loaded`, `validated`, `graph_integrated`, and `runtime_enabled`.
- Disabled extensions remain inspectable and lifecycle-visible while only enabled extensions contribute runtime integrations.
- Compatibility reports must include:
  - framework-version compatibility
  - graph-version compatibility
  - conflicting node-type providers
  - conflicting projection providers
  - missing required pack capabilities
  - conflicting definition-format current versions
- Graph compiler execution must consume only enabled registry outputs.
- Extension pass exceptions are converted into `FDY7020_EXTENSION_GRAPH_INTEGRATION_FAILED` diagnostics when the failing pass belongs to an extension.
- `foundry inspect extensions`, `foundry inspect extension <name>`, `foundry inspect packs`, `foundry inspect compatibility`, `foundry verify extensions`, and `foundry doctor` must all describe the same canonical registry state.
- `foundry pack install`, `foundry pack search`, `foundry pack info`, `foundry pack list`, and `foundry pack remove` must operate through pack-management services that preserve deterministic registry activation rules.
- Hosted registry discovery must cache registry payloads under `.foundry/cache/registry.json` and resolve exact `vendor/pack` and `vendor/pack@version` references deterministically.
- `GenerateEngine` integrates with the extension system by loading `ExtensionRegistry::forPaths($paths)` before planning and verification.
- GenerateEngine resolves pack requirements through the registry `PackRegistry`.
- Generate planning and execution must reuse the same extension registry and pack registry rather than re-discovering generators or pack capabilities through separate code paths.

## Acceptance Criteria

- The registry loads built-in extensions, explicit registrations, and active installed packs through one deterministic assembly path.
- Invalid explicit registration files, missing classes, invalid classes, invalid manifests, checksum mismatches, invalid providers, duplicate ids, missing dependencies, conflicts, and dependency cycles all produce structured diagnostics.
- Disabled extensions remain inspectable and lifecycle-visible, and enabled extensions remain deterministically load-ordered.
- Compatibility reports expose deterministic diagnostics, version matrices, lifecycle rows, and enabled load order.
- Compiler passes, codemods, migration rules, definition formats, projection emitters, graph analyzers, doctor checks, pipeline stages, and pipeline interceptors are all gathered only from enabled extensions.
- Installed packs can register one compiler extension entrypoint and declared pack contributions through `PackContext`.
- `PackContext` supports registration of commands, schemas, workflows, events, guards, generators, and docs metadata.
- If a pack provider implements `CompilerExtension` and does not call `registerExtension()`, the provider itself becomes the pack extension entrypoint.
- Extension descriptors and pack definitions are validated before enablement.
- Pack-provided generators become available to `GenerateEngine` only through enabled installed pack extensions.
- Inspect, explain, doctor, and generate surfaces expose registry-backed extension and pack data instead of private runtime state.
- Extension pass exceptions become `FDY7020_EXTENSION_GRAPH_INTEGRATION_FAILED` diagnostics instead of silent or unstructured failures.
- Pack removal deactivates the pack without deleting the installed version directory.
- Hosted pack search and hosted pack installation remain deterministic and validation-gated.
- `GenerateEngine` integrates with the extension system by loading `ExtensionRegistry::forPaths($paths)` before planning and verification.
- GenerateEngine resolves pack requirements through the registry `PackRegistry`.

## Assumptions

- Framework-owned built-in extensions remain the baseline runtime required for graph compilation.
- Extension names and pack names are stable identity keys and are compared case-sensitively after validation.
- The repository-local installed-pack directory and installed-pack registry remain the source of truth for pack activation.
- Pack providers run inside the current PHP process after local validation; the extension system does not provide an isolation sandbox.
