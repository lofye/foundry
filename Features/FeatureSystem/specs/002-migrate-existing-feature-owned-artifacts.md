# Execution Spec: 002-migrate-existing-feature-owned-artifacts

## Purpose

Migrate existing feature-owned artifacts into the localized feature runtime layout introduced by the feature-system refactor.

The previous refactor made the runtime/verifier aware of the new layout, but the repository still contains existing feature-owned docs, specs, plans, source files, manifests, tests, or references in legacy locations.

This spec completes the transition by moving existing artifacts into their canonical feature-local destinations and updating deterministic references so the repository itself obeys the new layout contract.

## Background

Foundry now treats a feature as the boundary for related implementation, documentation, planning, runtime context, and verification artifacts.

The intended localized layout is:

```text
Features/<feature>/
  README.md
  implementation.log
  src/
  tests/
  docs/
  specs/
  plans/
```

Existing global or legacy locations may still contain feature-owned files, such as:

```text
docs/features/<feature>.*
docs/features/<feature>/...
docs/specs/<feature>/...
docs/plans/<feature>/...
app/features/<feature>/...
src/<feature-owned runtime classes>
tests/<feature-owned tests>
```

This spec must migrate those artifacts without changing their meaning.

## Non-Goals

Do not redesign any feature.

Do not rewrite specs, docs, or plans except for path/reference normalization required by the move.

Do not introduce the SQLite state store.

Do not introduce a new feature registry format unless existing code already requires one and the migration exposes a bug.

Do not fix unrelated PHPStan, style, or doctor failures unless they are directly caused by this migration.

Do not opportunistically rename features, specs, plans, classes, tests, commands, or public CLI surfaces.

Do not change generated artifacts by hand. Fix generators, verifiers, manifests, or source inputs instead.

## Required Canonical Layout

Each migrated feature must use this shape where applicable:

```text
Features/<feature>/
  README.md
  implementation.log
  src/
  tests/
  docs/
  specs/
  plans/
```

Only create subdirectories that contain migrated or required files.

`Features/<feature>/README.md` is the feature-local state/context document.

`Features/<feature>/implementation.log` is the feature-local implementation history log.

`Features/<feature>/docs/` contains feature-owned explanatory docs that are not execution specs or implementation plans.

`Features/<feature>/specs/` contains execution specs for that feature.

`Features/<feature>/plans/` contains generated or authored implementation plans for that feature, when such plans exist.

`Features/<feature>/src/` contains feature-owned runtime/source files that belong to the feature boundary.

`Features/<feature>/tests/` contains feature-owned tests that belong to the feature boundary.

## Migration Rules

### 1. Preserve Meaning

The migration must be path/layout-only unless a content change is required to keep references, manifests, docs, tests, or verifiers correct after the move.

Allowed content changes:

- Update relative paths.
- Update documented canonical locations.
- Update manifests or registries that point at moved files.
- Update tests that assert old paths.
- Update verifiers that still scan old paths as canonical.
- Update generated examples if they are produced from source inputs.

Disallowed content changes:

- Rewording specs for style.
- Rewriting feature docs for clarity.
- Renaming feature IDs or spec IDs.
- Changing public CLI contracts unless the old contract explicitly exposed obsolete paths and the new contract already requires localized paths.
- Collapsing, compacting, or rewriting decision history.

### 2. Preserve Spec Identity

Execution spec identity remains filename-based.

When moving specs from legacy locations into `Features/<feature>/specs/`, preserve filenames exactly unless the filename already violates the currently enforced spec naming contract.

Do not renumber existing specs.

Do not duplicate feature names, IDs, parent IDs, or status metadata inside spec bodies.

The first heading of each spec must remain the filename-only heading format expected by `spec:validate`.

### 3. Preserve Decision History

If a legacy feature had a decision ledger, move it without compacting, rewriting, summarizing, reordering, or deleting entries.

If a decision record is needed for this migration, append one new entry only.

### 4. Preserve Implementation History

If a legacy implementation log exists for a feature, move it to `Features/<feature>/implementation.log`.

If only a global implementation log exists and it contains entries for multiple features, do not split historical global entries unless existing code already supports feature-local extraction deterministically.

Instead:

- keep the global log as a documented global/root exception if needed;
- create feature-local implementation logs for new migrated feature activity where required;
- update docs/verifiers so the long-term canonical write target is feature-local.

### 5. Do Not Leave Silent Duplicates

After migration, the same feature-owned artifact must not exist in both a legacy location and a feature-local location.

Legacy directories may remain only if they contain documented global/root exceptions or non-feature-owned files.

A legacy docs/specs/plans directory must not be counted as a duplicate feature unless it contains actual legacy feature-owned files.

### 6. Update Verifiers And Discovery

Update feature discovery, context verification, spec validation, plan discovery, graph discovery, and any relevant inspect/export/explain surfaces so they treat localized feature paths as canonical.

Legacy locations may be accepted only as compatibility inputs when explicitly documented and only when they do not conflict with localized canonical files.

Canonical output must prefer localized paths.

JSON output ordering must remain deterministic.

Text output ordering must remain deterministic.

Do not emit timestamps, random values, absolute machine-local paths, or environment-specific values in stable outputs.

### 7. Update Tests

Update or add tests that prove:

- migrated feature docs are discovered from `Features/<feature>/docs/` or `Features/<feature>/README.md` as applicable;
- migrated execution specs are discovered from `Features/<feature>/specs/`;
- migrated plans are discovered from `Features/<feature>/plans/` where applicable;
- duplicate detection does not falsely fail for empty legacy directories;
- duplicate detection does fail when the same feature-owned artifact exists in both canonical and legacy locations;
- deterministic CLI JSON outputs use canonical localized paths;
- old path assertions have been removed or updated;
- generated compatibility projections are not hand-edited.

## Required Audit

Before making changes, Codex must audit the repository for feature-owned artifacts in legacy locations.

At minimum, inspect these areas:

```bash
find docs/features -maxdepth 3 -type f | sort
find docs/specs -maxdepth 4 -type f | sort
find docs/plans -maxdepth 4 -type f | sort
find app/features -maxdepth 5 -type f | sort
find src -maxdepth 5 -type f | sort
find tests -maxdepth 5 -type f | sort
```

Codex must identify which files are feature-owned and which are global/root exceptions.

Do not move a file merely because it is near a feature. Move it only when it is owned by that feature boundary.

## Required Migration Work

### Step 1 — Inventory Legacy Feature-Owned Artifacts

Create an internal migration inventory while implementing.

The final code change does not need to commit a temporary inventory file unless the repository already has a canonical place for migration notes.

The inventory must classify each candidate as one of:

- move to `Features/<feature>/README.md`;
- move to `Features/<feature>/implementation.log`;
- move to `Features/<feature>/docs/`;
- move to `Features/<feature>/specs/`;
- move to `Features/<feature>/plans/`;
- move to `Features/<feature>/src/`;
- move to `Features/<feature>/tests/`;
- keep as documented global/root exception;
- leave unchanged because it is generated output or not feature-owned.

### Step 2 — Move Files

Move files into the new localized feature layout.

Preserve filenames unless a currently enforced naming rule requires normalization.

Preserve file contents except for required reference/path updates.

Remove obsolete empty legacy directories where safe.

### Step 3 — Update Internal References

Update all references to moved paths in:

- docs;
- specs;
- plans;
- manifests;
- feature registries;
- test fixtures;
- CLI output expectations;
- verifier expectations;
- generated source inputs;
- README or AGENTS references only if they are inside the scope of this migration.

Do not update unrelated public-facing prose as part of this spec unless it directly points to obsolete canonical paths.

### Step 4 — Update Feature Discovery And Verification

Ensure all relevant services, commands, verifiers, and tests use the localized layout as the source of truth.

Compatibility handling for legacy paths must be explicit, deterministic, and covered by tests.

### Step 5 — Update Implementation Logging

Append a completion entry to the canonical implementation log required by the repository after this spec is implemented.

If the repository now requires feature-local implementation logs, append to the relevant feature-local log.

If the repository still requires a global `docs/specs/implementation-log.md`, append there too only if current AGENTS/spec rules require it.

Do not rewrite existing historical entries.

## Required Commands

Run these before reporting completion:

```bash
php bin/foundry compile graph --json
php bin/foundry verify graph --json
php bin/foundry verify graph-integrity --json
php bin/foundry verify pipeline --json
php bin/foundry verify contracts --json
php bin/foundry verify features --json
php bin/foundry verify context --json
php bin/foundry spec:validate --json
php vendor/bin/phpunit
php -d xdebug.mode=coverage vendor/bin/phpunit --coverage-text
```

Also run these audit commands and report their results:

```bash
find docs/features -maxdepth 3 -type f | sort
find docs/specs -maxdepth 4 -type f | sort
find docs/plans -maxdepth 4 -type f | sort
find Features -maxdepth 4 -type f | sort
```

If PHPStan/style/doctor are already failing repo-wide before this spec, do not make them required completion gates unless this migration introduces new findings.

If Codex runs them, report whether failures are pre-existing or newly introduced:

```bash
php vendor/bin/phpstan analyse --no-progress
php bin/foundry doctor --cli --graph --static --style --quality --tests --json
```

## Completion Criteria

This spec is complete only when all of the following are true:

1. Existing feature-owned artifacts have been migrated into `Features/<feature>/...` canonical locations.
2. Legacy global docs/specs/plans locations contain no silent feature-owned duplicates.
3. Any remaining legacy files are documented global/root exceptions or compatibility fixtures.
4. Feature discovery prefers localized feature paths.
5. Spec validation accepts localized feature specs and rejects invalid duplicates deterministically.
6. Context verification accepts localized feature context docs and rejects invalid duplicates deterministically.
7. Plans, manifests, and path references point to localized canonical paths where applicable.
8. Runtime graph, graph integrity, pipeline, contracts, features, context, spec validation, PHPUnit, and coverage gates pass.
9. Skipped external-service tests remain acceptable only when caused by unavailable external services and not by this migration.
10. Any remaining PHPStan/style/doctor failures are documented as pre-existing or explicitly outside this spec.

## Codex Implementation Instructions

Implement this as a focused stabilization migration.

Start by auditing the repository. Then move the files. Then update references and verifiers. Then update tests. Then run the required gates.

Do not ask for confirmation about each file move. Use the repository’s current contracts and tests to classify ownership.

Prefer the smallest deterministic change that makes the repository conform to the localized feature layout.

Report final results with:

- moved files grouped by feature;
- files left in legacy/global locations and why;
- tests added or updated;
- commands run and exit status;
- remaining failures, if any, with whether they are pre-existing or introduced.
