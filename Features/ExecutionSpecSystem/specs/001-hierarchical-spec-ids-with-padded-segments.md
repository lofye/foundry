# Execution Spec: 001-hierarchical-spec-ids-with-padded-segments

## Purpose

- Establish a canonical, immutable, hierarchical spec ID system for Foundry.
- Ensure specs can be appended indefinitely without renumbering.
- Guarantee correct human-visible ordering in filesystems, editors, CLIs, and repository UIs.
- Make the filename the single source of truth for spec identity and hierarchy.

## Scope

- Define the canonical spec ID format.
- Define the canonical filename format.
- Define how hierarchy is inferred from IDs.
- Define validation and parsing rules.
- Remove the need for duplicated metadata inside spec files.

## Constraints

- Spec IDs must be immutable once assigned.
- Specs must be append-only.
- No renaming of existing specs is allowed.
- Filenames must sort correctly using standard lexical sorting.
- Hierarchy must be derivable from the filename alone.
- No reliance on filesystem-specific “natural sort”.
- No duplicated identity metadata inside spec contents.

## Requested Changes

### 1. Canonical Hierarchical ID Format

Spec IDs must be dot-separated numeric segments.

Each segment must be exactly 3 digits.

Valid:
- `001`
- `015`
- `015.001`
- `015.002.001`

Invalid:
- `15`
- `015.1`
- `015.01`
- `015.0001`
- `015.a01`

---

### 2. Canonical Filename Format

All specs must use this filename format:

`<id>-<slug>.md`

Examples:

- `015-state-normalization-pass-and-canonical-ordering.md`
- `015.001-hierarchical-spec-ids-with-padded-segments.md`
- `015.002-another-child.md`
- `015.002.001-grandchild.md`

Rules:

- `<id>` is the canonical spec identifier
- `<slug>` is lowercase kebab-case
- the file extension must be `.md`
- hyphen (`-`) separates id from slug
- no underscores, spaces, or uppercase letters

---

### 3. Filename as Source of Truth

The filename is the canonical identity of the spec.

Spec files must NOT define:
- `id`
- `parent`
- `status`

These must be derived from:
- filename
- file path

---

### 4. Spec Heading Format

The first line inside each spec file must mirror the filename only, without the feature path.

Format:

`# Execution Spec: <id>-<slug>`

Valid example:

`# Execution Spec: 001-hierarchical-spec-ids-with-padded-segments`

Invalid example:

`# Execution Spec: execution-spec-system/001-hierarchical-spec-ids-with-padded-segments`

---

### 5. Hierarchy Derivation

Hierarchy is inferred from the ID.

Rule:
- Parent = ID with final segment removed

Examples:
- parent(`015.001`) → `015`
- parent(`015.002.001`) → `015.002`

Root specs:
- have no parent
- consist of a single segment (e.g. `015`)

---

### 6. Ordering Guarantees

The padded segment format must guarantee correct ordering using standard lexical sorting.

Examples:
- `015.009` < `015.010`
- `015.010` < `015.010.001`
- all `015.*` < `016`

This must hold in:
- Finder
- terminal `ls`
- editor sidebars
- GitHub file views

---

### 7. Parsing and Resolution

All spec-processing logic must support hierarchical IDs:

- file discovery
- ordering
- parent-child relationships
- ambiguity detection

IDs must be interpreted as numeric segments, not strings.

---

### 8. Validation Rules

Only canonical padded IDs are allowed.

- non-padded IDs must be rejected or normalized at input boundaries
- stored filenames must always be canonical
- ambiguous formats must fail explicitly

---

### 9. Spec Status via Directory

Spec status is determined by location, not metadata.

Paths:

- `docs/features/<feature>/specs/drafts/<id>-<slug>.md` → draft (not executed)
- `docs/features/<feature>/specs/<id>-<slug>.md` → active (eligible to be executed)

No status field is allowed inside spec files.

---

### 10. ID Allocation (Minimal Rule)

When creating child specs:

- first child: `.001`
- next sibling increments last segment

Examples:

- child of `015` → `015.001`
- next → `015.002`
- child of `015.002` → `015.002.001`

This spec defines structure only; advanced planning logic may be added later.

---

## Non-Goals

- No renaming or rebalancing of existing spec trees
- No directory-based hierarchy encoding
- No mixed padded/unpadded formats
- No metadata duplication inside spec files
- No reliance on custom sorting logic

---

## Authority Rule

- The filename is the canonical identity of the spec
- Hierarchy is derived exclusively from the ID
- This convention is mandatory for all specs

---

## Completion Signals

- All specs follow `<id>-<slug>.md`
- IDs are padded and hierarchical
- Files sort correctly in all common environments
- No spec defines `id`, `parent`, or `status` internally
- Draft vs active behavior is path-based only
- Parsing and ordering are deterministic
- All tests pass

---

## Completion Signals

- All specs follow `<id>-<slug>.md`
- IDs are padded and hierarchical
- Spec headings follow `# Execution Spec: <id>-<slug>`
- Files sort correctly in all common environments
- No spec defines `id`, `parent`, or `status` internally
- Draft vs active behavior is path-based only
- Parsing and ordering are deterministic
- All tests pass

---

## Post-Execution Expectations

- Specs can be added indefinitely without renumbering
- Hierarchical relationships are obvious from filenames
- File listings always appear in correct logical order
- No duplication exists between filenames and file contents
- Spec system remains stable and predictable for humans and LLMs
