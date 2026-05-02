# Spec Naming and Placement Policy

## Canonical identity

A spec’s canonical identity is its filename.

Format:

<id>-<slug>.md

Examples:

- 015-state-normalization-pass-and-canonical-ordering.md
- 015.001-hierarchical-spec-ids-with-padded-segments.md
- 015.002-another-child.md
- 015.002.001-grandchild.md
- 016-planner-generic-fallback-blocking-and-slug-hardening.md

## Placement rules

Specs are organized by feature.

Paths:

- `docs/features/<feature-name>/specs/drafts/<id>-<slug>.md` = draft, not executable
- `docs/features/<feature-name>/specs/<id>-<slug>.md` = active, executable

Examples:

- `docs/features/execution-spec-system/specs/001-hierarchical-spec-ids-with-padded-segments.md`
- `docs/features/execution-spec-system/specs/drafts/002-next-id-allocation.md`
- `docs/features/execution-spec-system/specs/drafts/002.001-third-id-allocation.md`

The feature path provides context and execution state.
The filename provides identity.

Canonical feature context remains separate from execution specs:

- `docs/features/<feature>/<feature>.spec.md` → authoritative feature intent
- `docs/features/<feature>/<feature>.md` → current state
- `docs/features/<feature>/<feature>.decisions.md` → append-only decision history
- `docs/features/<feature>/specs/*.md` → execution specs (planning artifacts, non-authoritative after implementation)

## Heading rules

The first line inside a spec file must mirror the filename only.

Format:

`# Execution Spec: <id>-<slug>`

Example:

`# Execution Spec: 001-hierarchical-spec-ids-with-padded-segments`

Do not include the feature path in the heading.
Do not use filename-only headings such as `# 001-my-spec`.

Invalid example:

`# Execution Spec: execution-spec-system/001-hierarchical-spec-ids-with-padded-segments`

Invalid example:

`# 001-hierarchical-spec-ids-with-padded-segments`

## ID rules

- IDs are immutable once assigned.
- IDs use 3-digit segments.
- Segments are separated by `.`.
- Root specs have one segment: `015`
- Child specs append a segment: `015.001`
- Deeper descendants continue similarly: `015.002.001`
- IDs must be unique within a feature, not necessarily across the whole project.

## Hierarchy rules

Hierarchy is inferred entirely from the ID.

- Parent of `015.001` is `015`
- Parent of `015.001.023` is `015.001`
- Parent of `015.001.023.004` is `015.001.023`

The parent is obtained by removing the final segment.

## Slug rules

- Slugs use lowercase kebab-case.
- Slugs are descriptive but not authoritative.
- The numeric ID is the true immutable address.
- Slugs do not need to be unique within a feature.
- Within a feature, identity uniqueness is enforced by ID, not by slug.

## Status rules

Specs do not store status in file metadata.

Status is inferred from path:

- `docs/features/<feature-name>/specs/drafts/<id>-<slug>.md` = draft, not executable
- `docs/features/<feature-name>/specs/<id>-<slug>.md` = active, executable

Moving a spec from `drafts/` to the feature root promotes it from draft to executable without changing its contents.

---

## Draft to Spec Lifecycle

### Draft Definition

A draft spec is any spec located in:

`docs/features/<feature>/specs/drafts/<id>-<slug>.md`

Drafts:
- are not executed
- may be incomplete
- may be replaced, merged, or deleted
- must still follow canonical naming rules

Standard creation path:

`foundry spec:new <feature> "<slug>"`

Standard validation path:

`foundry spec:validate`

Standard implementation-log suggestion path:

`foundry spec:log-entry <feature>/<id>-<slug>`

or:

`foundry spec:log-entry <feature> <id>`

Validation also enforces required implementation-log coverage for active specs:
- active specs must have a matching `- spec: <feature>/<id>-<slug>.md` entry in `docs/features/implementation-log.md`
- drafts remain exempt
- matching is exact rather than fuzzy

### ID Assignment Rules

- IDs must be unique within a feature.
- Draft specs must not share the same ID.
- An ID is considered reserved once a draft spec exists with that ID.
- Reusing a slug is allowed as long as the ID is different.

### Promotion to Active Spec

A draft becomes an active spec when it is moved to:

`docs/features/<feature>/specs/<id>-<slug>.md`

Promotion rules:
- The filename must not change during promotion.
- The spec must be complete and implementable.
- The spec must conform to all naming and formatting rules.

### Implementation

After an active spec is implemented:

- The agent must append a correctly formatted entry to:
  `docs/features/implementation-log.md`

### Deletion and Replacement

- Draft specs may be deleted freely.
- If a draft is replaced by a more complete version:
  - the new version should reuse the same ID if appropriate
  - or use a new ID if it represents a different bounded step

### Invariant

At all times:

- Each `<feature>` must not contain more than one spec (draft or active) with the same ID.

---

## Spec ID Allocation Rules

### Purpose

Define how new spec IDs are assigned deterministically to avoid collisions and preserve append-only behavior.

---

### Root Spec Allocation

To create a new root spec within a feature:

1. Collect all root-segment IDs already in use (active and drafts).
   - Root IDs are single-segment (e.g. `001`, `002`).
   - Child specs reserve their root segment as well.
   - Example: `015.002.001` means root segment `015` is already in use.

2. Select the highest existing root ID.

3. Allocate the next ID by incrementing:

Example:
- existing: `001`, `002`, `003`
- next: `004`

---

### Child Spec Allocation

To create a child spec under a parent:

1. Identify the parent ID (e.g. `015` or `015.002`).

2. Collect all direct children:
   - children share the same prefix
   - and have exactly one additional segment

Example:
- parent: `015`
- children: `015.001`, `015.002`

3. Select the highest child segment.

4. Allocate the next segment by incrementing:

Example:
- existing: `015.001`, `015.002`
- next: `015.003`

---

### Nested Child Allocation

This rule applies recursively.

Example:

- parent: `015.002`
- existing children: `015.002.001`, `015.002.002`
- next: `015.002.003`

---

### Draft and Active Inclusion

Allocation must consider:

- active specs
- draft specs

Both reserve IDs.

---

### Collision Prevention

Before creating a new spec:

- the agent must verify that no spec (draft or active) already uses the target ID within the feature

If a collision is detected:
- the agent must select the next available ID

---

### Determinism Requirement

Given the same filesystem state, allocation must always produce the same ID.

No randomness or heuristic ordering is allowed.

---

### Invariant

At all times:

- No two specs within a feature may share the same ID
- IDs are never reused once assigned

---

## Metadata rules

Do not duplicate identity metadata inside the spec file.

In particular, specs should not require:
- `id`
- `parent`
- `status`

These are inferred from filename and path.

## Implementation log rules

Project-wide implementation chronology is recorded in:

`docs/features/implementation-log.md`

Agents must append a new entry immediately after completing an active execution spec implementation.

Normal `foundry implement spec <feature>/<id>-<slug>` or `foundry implement spec <feature> <id>` completion appends this entry automatically for active specs.

`foundry spec:validate` enforces that every active spec has an exact matching implementation-log entry.

`foundry spec:log-entry` emits the exact canonical timestamp heading, `- spec:` line, and full entry content expected by implementation-log validation for an active spec.

The implementation log is chronological and append-only.

Each entry must use this required format:

```md
## YYYY-MM-DD HH:MM:SS ±HHMM
- spec: <feature-name>/<id>-<slug>.md
```

An optional note can be included. The format including the note is:

```md
## YYYY-MM-DD HH:MM:SS ±HHMM
- spec: <feature-name>/<id>-<slug>.md
- note: <short implementation note>
```

Draft specs must not be logged as implemented unless they were first promoted to an active spec and then actually implemented.

## Design goals

This convention is intended to provide:

- stable immutable spec addresses
- sortable filenames
- arbitrarily deep hierarchy
- URL-safe and aesthetically clean names
- no renaming when new child specs are added
- no need to edit internal file metadata when reorganizing execution state
- clean separation between feature grouping and implementation chronology
