# Execution Spec: 007-modular-docs-feature-layout

## Purpose

Restructure Foundry documentation so each feature owns its intent, context, decision ledger, execution specs, and future plan artifacts under one feature-local documentation directory.

This changes documentation from centralized type-based folders to modular feature-based folders.

## Save Location

Save this spec as:

```text
docs/specs/execution-spec-system/drafts/004-modular-docs-feature-layout.md
```

Promote it to active execution when ready:

```text
docs/specs/execution-spec-system/004-modular-docs-feature-layout.md
```

## Current Structure

```text
docs/features/<id>-<slug>.md
docs/features/<id>-<slug>.decisions.md
docs/features/<id>-<slug>.spec.md
docs/specs/<feature>/<id>-<slug>.md
```

## Target Structure

```text
docs/<feature>/<id>-<slug>.md
docs/<feature>/<id>-<slug>.decisions.md
docs/<feature>/<id>-<slug>.spec.md
docs/<feature>/specs/<id>-<slug>.md
```

Draft execution specs must move to:

```text
docs/<feature>/specs/drafts/<id>-<slug>.md
```

## Goals

1. Move feature context documents from `docs/features/` into feature-local directories.
2. Move execution specs from `docs/specs/<feature>/` into `docs/<feature>/specs/`.
3. Preserve all existing spec IDs, filenames, headings, and document contents except for path references that must change.
4. Update spec tooling to read and write the new layout.
5. Update validation so the old layout is rejected after migration.
6. Update docs, stubs, and agent guidance to describe the new layout.

## Non-Goals

- Do not change execution spec ID semantics.
- Do not change filename-driven identity rules.
- Do not change heading rules.
- Do not introduce nested per-spec directories.
- Do not introduce internal `id`, `parent`, or `status` metadata.
- Do not rename feature slugs unless required by existing validation.

## Required Migration

For every feature currently represented by files under `docs/features/`:

1. Create `docs/<feature>/`.
2. Move:

```text
docs/features/<feature>.md
```

or any equivalent current feature-context filename to:

```text
docs/<feature>/<feature>.md
```

3. Move:

```text
docs/features/<feature>.decisions.md
```

to:

```text
docs/<feature>/<feature>.decisions.md
```

4. Move:

```text
docs/features/<feature>.spec.md
```

to:

```text
docs/<feature>/<feature>.spec.md
```

5. Move active execution specs from:

```text
docs/specs/<feature>/<id>-<slug>.md
```

to:

```text
docs/<feature>/specs/<id>-<slug>.md
```

6. Move draft execution specs from:

```text
docs/specs/<feature>/drafts/<id>-<slug>.md
```

to:

```text
docs/<feature>/specs/drafts/<id>-<slug>.md
```

If current feature context filenames already include numeric IDs, preserve the existing filenames exactly when moving them.

## Tooling Changes

Update all code paths that read or write feature docs, execution specs, or draft specs.

At minimum, update:

- execution spec discovery
- draft spec discovery
- `spec:new`
- `spec:validate`
- any parser tests that assume `docs/specs/<feature>/...`
- any feature-context inspection commands that assume `docs/features/...`
- documentation site import/build logic if it reads these paths directly

## `spec:new` Contract

`spec:new <feature> "<title>"` must now create drafts at:

```text
docs/<feature>/specs/drafts/<id>-<slug>.md
```

ID allocation must scan both:

```text
docs/<feature>/specs/*.md
docs/<feature>/specs/drafts/*.md
```

It must not scan the old `docs/specs/<feature>/` location after this migration.

Plain-text and JSON outputs must be updated to report the new path.

## `spec:validate` Contract

`spec:validate` must validate the new layout only.

It must reject:

```text
docs/features/*.md
docs/specs/<feature>/*.md
docs/specs/<feature>/drafts/*.md
```

unless those files are explicitly documented non-spec root files already allowed by the validator.

Validation must continue enforcing:

- padded hierarchical IDs
- filename-only headings
- duplicate ID detection within each feature
- forbidden internal metadata fields
- deterministic ordering
- stable plain-text output
- stable JSON output
- non-zero exit on violations

## Path Reference Updates

Update hard-coded references in:

- `README.md`
- `AGENTS.md`
- `APP-AGENTS.md`
- docs under `docs/`
- stubs under `stubs/`
- tests
- fixture files
- expected CLI snapshots

Replace old references with the new layout.

## Backward Compatibility

No backward compatibility is required for the old docs layout after this spec is implemented.

The old layout must fail validation so drift is caught immediately.

## Tests

Add or update tests proving:

1. `spec:new` creates drafts under `docs/<feature>/specs/drafts/`.
2. ID allocation includes active and draft specs in the new location.
3. `spec:validate` accepts valid specs in `docs/<feature>/specs/`.
4. `spec:validate` accepts valid drafts in `docs/<feature>/specs/drafts/`.
5. `spec:validate` rejects old `docs/specs/<feature>/` execution specs.
6. `spec:validate` rejects old `docs/features/` feature docs.
7. Existing hierarchical spec IDs still parse correctly.
8. Existing filename-only heading checks still work.
9. CLI JSON output remains deterministic.
10. CLI plain-text output remains deterministic.

## Acceptance Criteria

- No active feature documentation remains under `docs/features/`.
- No active or draft execution specs remain under `docs/specs/<feature>/`.
- All feature-owned documentation lives under `docs/<feature>/`.
- All execution specs live under `docs/<feature>/specs/`.
- All draft execution specs live under `docs/<feature>/specs/drafts/`.
- `spec:new` writes to the new draft location.
- `spec:validate --json` passes for the migrated repository.
- Old layout files fail validation.
- Existing spec IDs, headings, and filenames remain valid.
- Test coverage for changed and added code meets the project threshold.

## Required Verification

Run and pass:

```bash
php bin/foundry spec:validate --json
php bin/foundry verify contracts --json
php bin/foundry verify graph --json
php bin/foundry verify pipeline --json
```

Run the project test suite and confirm coverage remains at or above the required threshold.

## Implementation Log

After implementation, append the required entry to:

```text
docs/specs/implementation-log.md
```

If this file is migrated by this spec, append to its new canonical location and update all references accordingly.
