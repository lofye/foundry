# Foundry Extensions And Migrations

The compiler core established Foundry as a semantic compiler with a canonical graph. The extensions and migrations layer extends that architecture so extensions, packs, migrations, and codemods remain graph-native and deterministic.

## Design Rules

- Source-of-truth files remain under `app/features/*`.
- The canonical compiled graph remains authoritative.
- Extensions and packs register through explicit registries.
- Compatibility is validated and inspectable.
- Definition migrations and codemods run on source-of-truth files and emit structured diagnostics.

## Explicit Extension Registration

Foundry loads extension classes from explicit registration files (if present):

- `foundry.extensions.php`
- `config/foundry/extensions.php`

The file must return class names implementing `Foundry\Compiler\Extensions\CompilerExtension`.

```php
<?php
declare(strict_types=1);

return [
    \Foundry\Extensions\Demo\DemoCapabilityExtension::class,
];
```

Core extension support is always registered, and explicit extension files are layered on top deterministically.

## Stable Extension Lifecycle

Every extension is resolved through explicit lifecycle stages:

1. `discovered`
2. `loaded`
3. `validated`
4. `graph_integrated`
5. `runtime_enabled`

`inspect extensions --json` and `inspect compatibility --json` expose lifecycle rows, diagnostics, and final load order.

Compiler pass ordering is deterministic by:

1. compiler stage
2. extension-declared priority
3. resolved extension load order
4. pass class name

## Local Packs

Packs are explicit, deterministic extension units, not ad hoc folders or hidden runtime plugins.

Each local pack source must include `foundry.json`:

```json
{
  "name": "vendor/pack-name",
  "version": "1.0.0",
  "description": "string",
  "entry": "Vendor\\Pack\\PackServiceProvider",
  "capabilities": []
}
```

Rules:

- install sources are copied into `.foundry/packs/{vendor}/{pack}/{version}/`
- installed files stay immutable once copied
- active versions are tracked in `.foundry/packs/installed.json`
- graph boot reads only active pack versions
- pack manifests must declare `checksum` and `signature`, and installs fail when the package checksum does not match
- pack entry classes must implement `Foundry\Packs\PackServiceProvider`
- pack providers register graph-visible behavior explicitly through `Foundry\Packs\PackContext`

If a pack needs compiler passes, projection emitters, doctor checks, or compatibility constraints, it should register a `CompilerExtension` through the pack provider.

CLI commands:

```bash
foundry pack search <query> --json
foundry pack install vendor/pack --json
foundry pack install vendor/pack@1.2.0 --json
foundry pack install <path-or-name> --json
foundry pack remove <vendor/pack> --json
foundry pack list --json
foundry pack info <vendor/pack> --json
foundry inspect packs --json
```

Activation remains deterministic:

- local pack order is sorted by pack name then active version
- declared command and schema contributions must be unique across active packs
- duplicate graph node identifiers fail explicitly during graph integration
- no remote dependency is required for install, remove, list, info, or graph loading

## Hosted Registry Contract

Foundry can also discover packs through an optional public registry endpoint:

```text
GET /packs
```

Registry response shape:

```json
[
  {
    "name": "vendor/pack",
    "version": "1.0.0",
    "description": "Short description",
    "download_url": "https://example.com/packs/vendor-pack-1.0.0.zip",
    "checksum": "sha256-hex",
    "signature": null,
    "verified": true
  }
]
```

Rules:

- the registry is read-only metadata, not an execution or access-control layer
- entries are normalized deterministically by name then version
- duplicate `name` + `version` rows fail validation
- `download_url` must use HTTPS
- `foundry pack install vendor/pack` resolves the highest semantic version deterministically
- `foundry pack install vendor/pack@1.2.0` resolves that exact version or fails structurally
- registry checksums must match the downloaded package manifest checksum before local install proceeds

Downloaded archives must be `.zip` files with `foundry.json` and `src/` at the archive root. Extraction must reject directory traversal and still delegates into the same local install pipeline after validation.

## Compatibility Model

Compatibility is explicit and enforced across:

- framework version
- graph version
- extension descriptors
- pack constraints
- definition format declarations

For local packs, framework and graph constraints come from the compiler extension registered by the pack provider. The `foundry.json` manifest itself stays intentionally minimal. Hosted registry entries only add discovery metadata and download locations.

Diagnostics include:

- `FDY7001_INCOMPATIBLE_EXTENSION_VERSION`
- `FDY7002_INCOMPATIBLE_GRAPH_VERSION`
- `FDY7003_UNSUPPORTED_DEFINITION_VERSION`
- `FDY7004_NO_MIGRATION_PATH`
- `FDY7005_DUPLICATE_EXTENSION_ID`
- `FDY7006_CONFLICTING_NODE_PROVIDER`
- `FDY7007_CONFLICTING_PROJECTION_PROVIDER`
- `FDY7008_INCOMPATIBLE_PACK_VERSION`
- `FDY7009_PACK_CAPABILITY_MISSING`
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

Inspect/verify commands:

```bash
foundry inspect compatibility --json
foundry verify extensions --json
foundry verify compatibility --json
foundry doctor --json
```

## Definition Migration Framework

Definition migrations are version-aware and deterministic.

Current built-in format:

- `feature_manifest` (current: v2)

Built-in migration:

- `FDY_MIGRATE_FEATURE_MANIFEST_V2` (1 -> 2)

Migration behavior:

- path-scoped or full project scanning
- migration planning (`plans`)
- structured diagnostics for outdated/unsupported/missing migration paths
- dry-run and write modes

Commands:

```bash
foundry inspect migrations --json
foundry inspect definition-format feature_manifest --json
foundry migrate definitions --dry-run --json
foundry migrate definitions --path=<path> --dry-run --json
foundry migrate definitions --write --json
```

## Codemod Engine

Codemods are explicit, deterministic rewrite operations contributed by extensions.

Built-in codemod:

- `feature-manifest-v1-to-v2`

Command:

```bash
foundry codemod run feature-manifest-v1-to-v2 --dry-run --json
foundry codemod run feature-manifest-v1-to-v2 --write --json
```

## Build Artifacts and Inspection

extensions and migrations layer metadata is surfaced through existing build and inspect channels, including extension descriptors, pack schemas, lifecycle rows, dependency diagnostics, definition formats, codemods, and compatibility summaries.

## Canonical Workflow

1. Edit source-of-truth definitions/files.
2. `foundry compile graph --json`
3. Inspect diagnostics/impact/compatibility.
4. Verify graph/extensions/compatibility.
5. Run tests.
