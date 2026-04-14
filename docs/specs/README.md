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

- `docs/specs/<feature-name>/drafts/<id>-<slug>.md` = draft, not executable
- `docs/specs/<feature-name>/<id>-<slug>.md` = active, executable

Examples:

- `docs/specs/execution-spec-system/001-hierarchical-spec-ids-with-padded-segments.md`
- `docs/specs/execution-spec-system/drafts/002-next-id-allocation.md`
- `docs/specs/execution-spec-system/drafts/002.001-third-id-allocation.md`

The feature path provides context and execution state.
The filename provides identity.

## Heading rules

The first line inside a spec file must mirror the filename only.

Format:

`# Execution Spec: <id>-<slug>`

Example:

`# Execution Spec: 001-hierarchical-spec-ids-with-padded-segments`

Do not include the feature path in the heading.

Invalid example:

`# Execution Spec: execution-spec-system/001-hierarchical-spec-ids-with-padded-segments`

## ID rules

- IDs are immutable once assigned.
- IDs use 3-digit segments.
- Segments are separated by `.`.
- Root specs have one segment: `015`
- Child specs append a segment: `015.001`
- Deeper descendants continue similarly: `015.002.001`

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

## Status rules

Specs do not store status in file metadata.

Status is inferred from path:

- `docs/specs/<feature-name>/drafts/<id>-<slug>.md` = draft, not executable
- `docs/specs/<feature-name>/<id>-<slug>.md` = active, executable

Moving a spec from `drafts/` to the feature root promotes it from draft to executable without changing its contents.

## Metadata rules

Do not duplicate identity metadata inside the spec file.

In particular, specs should not require:
- `id`
- `parent`
- `status`

These are inferred from filename and path.

## Implementation log rules

Project-wide implementation chronology is recorded in:

`docs/specs/implementation-log.md`

Agents must append a new entry immediately after completing an active execution spec implementation.

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
•	stable immutable spec addresses
•	sortable filenames
•	arbitrarily deep hierarchy
•	URL-safe and aesthetically clean names
•	no renaming when new child specs are added
•	no need to edit internal file metadata when reorganizing execution state
•	clean separation between feature grouping and implementation chronology

