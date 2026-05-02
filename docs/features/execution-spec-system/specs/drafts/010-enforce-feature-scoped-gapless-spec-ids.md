# Execution Spec: 010-enforce-feature-scoped-gapless-spec-ids

## Feature

- execution-spec-system

## Purpose

Enforce that execution spec IDs are:

- feature-scoped
- sibling-scoped
- hierarchical
- gapless (no skipped numbers)

This ensures deterministic ordering, prevents ambiguity, and eliminates accidental gaps in execution-spec sequencing.

---

## Depends On

- 007-modular-docs-feature-layout.md
- 007.001-agent-facing-doc-path-contracts.md
- 007.002-readmes-stubs-and-public-docs-path-contracts.md
- 007.003-path-fixtures-tests-and-contract-cleanup.md

---

## Scope

Applies to:

- execution specs in:
  - docs/features/<feature>/specs/*.md
- draft execution specs in:
  - docs/features/<feature>/specs/drafts/*.md

Impacts:

- spec:new (ID allocation)
- spec:validate (ID validation)
- execution spec cataloging and resolution

---

## Rules

### 1. Feature-Scoped Validation

Execution spec IDs must be validated only within the same feature.

IDs in different features must not affect each other.

---

### 2. Sibling-Scoped Continuity

Within any sibling group, IDs must be contiguous with no gaps.

#### Top-level example

Valid:

- 001
- 002
- 003

Invalid:

- 001
- 003  
  Missing: 002

---

#### Nested example

Valid:

- 007
- 007.001
- 007.002
- 008

Invalid:

- 007
- 007.001
- 007.003
- 008  
  Missing: 007.002

---

#### Deep nesting example

Valid:

- 007
- 007.001
- 007.001.001
- 007.001.002
- 007.002
- 008

Invalid:

- 007
- 007.001
- 007.001.001
- 007.001.003
- 007.002
- 008  
  Missing: 007.001.002

---

### 3. Active vs Draft Separation

Continuity must be enforced separately for:

- active specs:
  docs/features/<feature>/specs/*.md

- draft specs:
  docs/features/<feature>/specs/drafts/*.md

Draft gaps must not be allowed.

---

### 4. No Global Sequence

Do not enforce a global sequence across all features.

---

### 5. No Renumbering

Do not automatically renumber existing specs.

Gaps must produce validation failures, not corrections.

---

### 6. Hierarchical Insertion Allowed

Hierarchical insertion is valid as long as sibling continuity is preserved.

---

## Required System Behavior

### spec:new

Must:

- determine correct feature
- determine correct sibling group
- assign the next contiguous ID
- prevent creation of a spec that would introduce a gap

---

### spec:validate

Must fail if any gap exists.

Error output must include:

- feature
- location: active or drafts
- parent ID (or "top-level")
- expected missing ID
- offending file path

Output must be deterministic.

---

## Implementation Steps

1. Update execution spec catalog to group specs by:
  - feature
  - parent ID

2. For each group:
  - sort IDs numerically
  - detect gaps in sequence

3. Implement validation logic for:
  - top-level groups
  - nested groups
  - draft groups

4. Update spec:new:
  - compute next valid contiguous ID
  - block invalid creation attempts

5. Ensure no fallback logic exists

6. Ensure deterministic ordering and output

---

## Tests

Add tests proving:

- top-level gaps fail
- sibling gaps fail
- deep sibling gaps fail
- hierarchical insertion passes
- draft and active sequences are validated independently
- different features do not affect each other
- spec:new allocates correct IDs
- spec:new prevents invalid gaps

---

## Acceptance Criteria

- All execution spec IDs are gapless within each feature and sibling group
- spec:validate fails on any gap
- spec:new always allocates valid IDs
- No global sequence enforcement exists
- No renumbering occurs
- Tests cover all scenarios
- Validation output is deterministic

---

## Verification

Run:

```bash
php bin/foundry spec:validate --json
php bin/foundry verify context --json
php bin/foundry verify contracts --json
php bin/foundry verify graph --json
php bin/foundry verify pipeline --json
php vendor/bin/phpunit
php -d xdebug.mode=coverage vendor/bin/phpunit --coverage-text
```

All must pass.

⸻

Implementation Log

After successful implementation, append an entry to:

docs/features/implementation-log.md