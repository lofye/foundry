# Execution Spec: 023-normalize-additional-context-artifacts

## Feature
- context-persistence

## Purpose
- Extend canonical normalization beyond feature state documents.
- Reduce formatting, ordering, and structural drift in additional context artifacts.
- Build on the state normalization path from 015.003 while preserving meaning and keeping normalization deterministic.

## Scope
- Extend reusable normalization to one or more additional canonical context artifacts beyond `docs/features/<feature>.md`.
- Keep this focused on context artifacts only.
- Apply the smallest valuable expansion of normalization behavior.

## Constraints
- Keep normalization deterministic and idempotent.
- Preserve semantic meaning.
- Do not rewrite execution specs in this spec.
- Do not normalize unrelated repository markdown files.
- Prefer a narrow artifact expansion over a broad formatting system.
- Reuse normalization infrastructure from 015.003 where practical.

## Inputs

Expect inputs such as:
- canonical feature context documents under `docs/features/`
- the reusable normalization path introduced in 015.003
- current doctor/verify-context workflows

If any critical input is missing:
- fail clearly and deterministically
- do not invent content
- do not silently skip normalization for targeted artifacts

## Requested Changes

### 1. Select Additional Target Artifacts

Extend normalization to one or more of these canonical artifact classes:

- `docs/features/<feature>.spec.md`
- `docs/features/<feature>.decisions.md`

Choose the smallest expansion that yields clear practical value and can be implemented conservatively.

### 2. Define Canonical Rules per Artifact Type

For each targeted artifact type, define explicit canonical normalization rules.

Examples:
- stable section ordering
- bullet deduplication within appropriate sections
- whitespace normalization
- deterministic heading spacing
- conservative cleanup of obvious stale formatting noise

Do not invent new semantic content.

### 3. Preserve Artifact-Specific Meaning

Normalization must respect artifact roles:

- feature spec = current intended behavior
- decisions ledger = append-only reasoning history

The normalization must not:
- rewrite intent
- summarize decisions
- collapse distinct historical decision entries
- reorder append-only decision entries in a way that alters chronology

### 4. Integrate Through Reusable Normalization Infrastructure

Reuse or extend the normalization system introduced in 015.003.

Avoid creating disconnected one-off normalization paths for each artifact class if a shared deterministic infrastructure can handle them cleanly.

### 5. Keep Idempotency

Given the same input:
- the same normalized output must be produced

Given already normalized targeted artifacts:
- re-running normalization must produce stable output with no further changes

### 6. Tests

Add focused coverage proving:

- targeted additional artifact types normalize deterministically
- normalization is conservative and meaning-preserving
- append-only decision chronology is not broken
- repeated normalization is idempotent
- existing state-document normalization remains intact
- all relevant context tests still pass

## Non-Goals
- Do not add auto-repair behavior.
- Do not redesign context doctor or verify-context in this spec.
- Do not normalize execution specs in this spec.
- Do not introduce user-configurable formatting policies.
- Do not broaden this into a general repository markdown formatter.

## Canonical Context
- Canonical feature spec: `docs/features/context-persistence.spec.md`
- Canonical feature state: `docs/features/context-persistence.md`
- Canonical decision ledger: `docs/features/context-persistence.decisions.md`

## Authority Rule
- Canonical context artifacts should converge on deterministic, reusable normalization behavior where that can be done without changing meaning.
- Artifact-specific roles must remain intact.
- Normalization must stay conservative, deterministic, and idempotent.

## Completion Signals
- At least one additional canonical context artifact type beyond state documents is normalized through reusable infrastructure.
- Targeted artifact normalization is deterministic and idempotent.
- Meaning is preserved.
- Existing state normalization remains intact.
- All tests pass.

## Post-Execution Expectations
- More canonical context artifacts become stable and easier to diff.
- Formatting and ordering drift decreases beyond state documents.
- Later diagnostics and tooling can rely on cleaner context inputs.
