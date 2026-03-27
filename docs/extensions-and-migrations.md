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

## Packs and Capabilities

Packs are structured extension-owned definitions, not ad hoc folders.

Each pack declares:

- name and version
- owning extension
- provided and required capabilities
- required, optional, and conflicting pack dependencies
- framework and graph constraints
- generators/inspect surfaces/definition formats/migration rules/verifiers/docs metadata

Inspect commands:

```bash
foundry inspect packs --json
foundry inspect pack <name> --json
```

Metadata schemas are exposed through:

```bash
foundry inspect extensions --json
```

## Compatibility Model

Compatibility is explicit and enforced across:

- framework version
- graph version
- extension descriptors
- pack constraints
- definition format declarations

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
