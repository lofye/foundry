# Execution Spec: 023-normalize-additional-context-artifacts

## Feature
- context-persistence

## Purpose
- Extend canonical normalization beyond feature state documents.
- Reduce formatting, ordering, and structural drift in additional canonical context artifacts.
- Build on the reusable state-document normalization path introduced in 015.003 while preserving meaning and keeping normalization deterministic.

## Scope
- Extend reusable normalization to **exactly one** additional canonical artifact class beyond `docs/features/<feature>.md`.
- In this spec, target only:

```text
docs/features/<feature>.spec.md
```

- Keep this focused on feature spec documents only.
- Do not normalize decision ledgers in this spec.

## Constraints
- Keep normalization deterministic and idempotent.
- Preserve semantic meaning.
- Do not rewrite execution specs in this spec.
- Do not normalize unrelated repository markdown files.
- Do not normalize `docs/features/<feature>.decisions.md` in this spec.
- Prefer a narrow, high-confidence artifact expansion over a broad formatting system.
- Reuse normalization infrastructure from 015.003 where practical.

## Inputs

Expect inputs such as:
- canonical feature spec documents under `docs/features/`
- the reusable normalization path introduced in 015.003
- current doctor/verify-context workflows

If any critical input is missing:
- fail clearly and deterministically
- do not invent content
- do not silently skip normalization for targeted artifacts

## Requested Changes

### 1. Target Feature Spec Documents Only

Extend normalization to:

```text
docs/features/<feature>.spec.md
```

Do not normalize decision ledgers in this spec.

Rationale:
- feature spec documents are current-intent artifacts and are safer to normalize conservatively
- decision ledgers are append-only historical records and deserve a separate, more careful spec if normalized later

### 2. Define Canonical Normalization Rules for Feature Specs

Introduce explicit normalization rules for feature spec documents.

Allowed normalization includes:
- stable section ordering for known canonical sections
- deterministic heading spacing
- deterministic blank-line handling
- deterministic bullet formatting
- exact duplicate bullet removal within the same section when safe
- conservative cleanup of obvious formatting noise

Do not:
- invent new semantic content
- merge semantically different bullets
- rewrite meaning for style alone
- reorder bullets when order is clearly intentional

### 3. Preserve Feature-Spec Semantics

Feature spec normalization must preserve the role of the artifact:

- feature spec = current intended behavior

Normalization must not:
- rewrite intent
- silently broaden or narrow requirements
- change the meaning of constraints, goals, expected behavior, or acceptance criteria
- remove distinct items that only look similar but are semantically different

### 4. Integrate Through Reusable Normalization Infrastructure

Reuse or extend the normalization system introduced in 015.003.

Avoid creating a disconnected one-off normalization path just for feature spec documents if the shared deterministic infrastructure can support this safely.

### 5. Keep Idempotency

Given the same input:
- the same normalized output must be produced

Given an already normalized feature spec:
- re-running normalization must produce stable output with no further changes

### 6. Choose the Smallest Useful Integration Point

Apply feature-spec normalization through the smallest framework-owned path that makes it real and reusable.

Acceptable integration points include:
- the same context-owned write path used for state normalization
- a closely related canonical-context update path
- another deterministic context write boundary already trusted by the system

Do not add broad repository-wide rewrite behavior in this spec.

### 7. Tests

Add focused coverage proving:

- feature spec documents normalize deterministically
- normalization is conservative and meaning-preserving
- repeated normalization is idempotent
- duplicate bullets within the same section are handled safely when truly exact
- stable section ordering is enforced for targeted feature spec sections
- existing state-document normalization remains intact
- all relevant context tests still pass

## Non-Goals
- Do not add auto-repair behavior.
- Do not redesign context doctor or verify-context in this spec.
- Do not normalize execution specs in this spec.
- Do not normalize `docs/features/<feature>.decisions.md` in this spec.
- Do not introduce user-configurable formatting policies.
- Do not broaden this into a general repository markdown formatter.

## Canonical Context
- Canonical feature spec: `docs/features/context-persistence.spec.md`
- Canonical feature state: `docs/features/context-persistence.md`
- Canonical decision ledger: `docs/features/context-persistence.decisions.md`

## Authority Rule
- Canonical feature spec documents should converge on deterministic, reusable normalization behavior where that can be done without changing meaning.
- Artifact-specific roles must remain intact.
- Normalization must stay conservative, deterministic, and idempotent.

## Completion Signals
- Feature spec documents are normalized through reusable infrastructure.
- Targeted normalization is deterministic and idempotent.
- Meaning is preserved.
- Existing state normalization remains intact.
- All tests pass.

## Post-Execution Expectations
- Feature spec documents become more stable and easier to diff.
- Formatting and ordering drift decreases beyond state documents.
- Later diagnostics and tooling can rely on cleaner canonical context inputs without risking decision-ledger history.
