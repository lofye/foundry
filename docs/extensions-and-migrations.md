# Foundry Extensions And Migrations

The compiler core established Foundry as a semantic compiler with a canonical graph. The extensions and migrations layer extends that architecture so extensions, packs, migrations, and codemods remain graph-native and deterministic.

## Design Rules

- Source-of-truth files remain under `app/features/*`.
- The canonical compiled graph remains authoritative.
- Extensions and packs register through explicit registries.
- Compatibility is validated and inspectable.
- Spec migrations and codemods run on source-of-truth files and emit structured diagnostics.

## Explicit Extension Registration

Foundry loads extension classes from explicit registration files (if present):

- `foundry.extensions.php`
- `app/platform/foundry/extensions.php`

The file must return class names implementing `Foundry\Compiler\Extensions\CompilerExtension`.

```php
<?php
declare(strict_types=1);

return [
    \Foundry\Extensions\Demo\DemoCapabilityExtension::class,
];
```

Core extension support is always registered, and explicit extension files are layered on top deterministically.

## Extension Lifecycle in the Compiler

Extension contributions are integrated into the same compiler lifecycle:

1. registration
2. compatibility checks
3. pass collection and deterministic ordering
4. compile execution
5. projection emission
6. diagnostics emission
7. inspect/verify surfaces
8. migration and codemod contributions

Pass ordering is deterministic by:

1. compiler stage
2. extension-declared priority
3. extension name
4. pass class name

## Packs and Capabilities

Packs are structured extension-owned definitions, not ad hoc folders.

Each pack declares:

- name and version
- owning extension
- provided and required capabilities
- framework and graph constraints
- generators/spec formats/migration rules/verifiers metadata

Inspect commands:

```bash
php vendor/bin/foundry inspect packs --json
php vendor/bin/foundry inspect pack <name> --json
```

## Compatibility Model

Compatibility is explicit and enforced across:

- framework version
- graph version
- extension descriptors
- pack constraints
- spec format declarations

Diagnostics include:

- `FDY7001_INCOMPATIBLE_EXTENSION_VERSION`
- `FDY7002_INCOMPATIBLE_GRAPH_VERSION`
- `FDY7003_UNSUPPORTED_SPEC_VERSION`
- `FDY7004_NO_MIGRATION_PATH`
- `FDY7005_DUPLICATE_EXTENSION_ID`
- `FDY7006_CONFLICTING_NODE_PROVIDER`
- `FDY7007_CONFLICTING_PROJECTION_PROVIDER`
- `FDY7008_INCOMPATIBLE_PACK_VERSION`
- `FDY7009_PACK_CAPABILITY_MISSING`

Inspect/verify commands:

```bash
php vendor/bin/foundry inspect compatibility --json
php vendor/bin/foundry verify extensions --json
php vendor/bin/foundry verify compatibility --json
```

## Spec Migration Framework

Spec migrations are version-aware and deterministic.

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
php vendor/bin/foundry inspect migrations --json
php vendor/bin/foundry inspect spec-format feature_manifest --json
php vendor/bin/foundry migrate specs --dry-run --json
php vendor/bin/foundry migrate specs --path=<path> --dry-run --json
php vendor/bin/foundry migrate specs --write --json
```

## Codemod Engine

Codemods are explicit, deterministic rewrite operations contributed by extensions.

Built-in codemod:

- `feature-manifest-v1-to-v2`

Command:

```bash
php vendor/bin/foundry codemod run feature-manifest-v1-to-v2 --dry-run --json
php vendor/bin/foundry codemod run feature-manifest-v1-to-v2 --write --json
```

## Build Artifacts and Inspection

extensions and migrations layer metadata is surfaced through existing build and inspect channels, including extension descriptors, packs, spec formats, codemods, and compatibility summaries.

## Canonical Workflow

1. Edit source-of-truth specs/files.
2. `php vendor/bin/foundry compile graph --json`
3. Inspect diagnostics/impact/compatibility.
4. Verify graph/extensions/compatibility.
5. Run tests.
