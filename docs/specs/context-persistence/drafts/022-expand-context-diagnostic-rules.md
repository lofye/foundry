# Execution Spec: 022-expand-context-diagnostic-rules

## Feature
- context-persistence

## Purpose
- Expand the rule-driven context-diagnostic system beyond `EXECUTION_SPEC_DRIFT`.
- Catch more real context problems through deterministic, feature-scoped rules.
- Build on the internal doctor-rule architecture introduced in 015.002 without changing the existing external `context doctor` and `verify context` contracts.

## Scope
- Add one or more new context-diagnostic rules through the normalized internal rule model.
- Reuse the existing doctor file-bucket output shape and verify-context flattened issue shape.
- Keep this focused on adding rule coverage, not on redesigning the doctor/verify pipelines.

## Constraints
- Keep diagnostics deterministic and feature-scoped.
- Preserve current `context doctor` and `verify context` JSON contracts.
- Reuse the normalized doctor-rule structure introduced in 015.002.
- Prefer a small set of high-value rules over a broad speculative sweep.
- Do not duplicate rules already covered by structural doctor checks.
- Do not add auto-repair in this spec.

## Inputs

Expect inputs such as:
- canonical feature context files under `docs/features/`
- doctor rule infrastructure from 015.002
- current doctor/verify-context output contracts

If any critical input is missing:
- fail clearly and deterministically
- do not invent new context content
- do not silently disable existing rules

## Requested Changes

### 1. Add New Rule-Driven Diagnostics

Add at least two additional high-value context-diagnostic rules using the internal doctor-rule model introduced in 015.002.

The rules must detect real context problems that are not already covered well enough by the existing structural checks.

Acceptable targets include:
- unsupported state claims that are not grounded in the spec or decision ledger
- missing decision references for divergence from canonical intent
- untracked spec requirements not reflected in `Current State`, `Open Questions`, or `Next Steps`
- stale completed-work items lingering in `Next Steps`

Choose the smallest set of rules that delivers clear value and cleanly fits the existing doctor/verify system.

### 2. Preserve Existing External Contracts

`context doctor --json` must continue to expose:
- top-level `status`
- top-level `can_proceed`
- top-level `requires_repair`
- `files.spec`, `files.state`, `files.decisions`
- per-file `issues`
- top-level `required_actions`

`verify context --json` must continue to expose:
- top-level `status`
- top-level `can_proceed`
- top-level `requires_repair`
- flat `issues`
- top-level `required_actions`

Do not introduce a new public diagnostics schema in this spec.

### 3. Use Stable Issue Codes

Each new rule must emit a stable, explicit issue code.

Issue codes must:
- be machine-friendly
- remain deterministic
- be suitable for agent workflows and automated checks

### 4. Use Actionable Required Actions

Each new rule must contribute deterministic, actionable repair guidance through the existing `required_actions` flow.

Recommended actions must be:
- concrete
- small enough to act on
- consistent with the actual rule violation

### 5. Keep Rule Ordering Deterministic

If multiple rules fire:
- issue ordering must remain deterministic within doctor file buckets
- verify-context flattened issue ordering must remain deterministic
- repeated runs against the same filesystem state must produce the same ordered outputs

### 6. Tests

Add focused coverage proving:

- each new rule fires in the intended condition
- each new rule does not over-fire on healthy context
- doctor output shape remains unchanged
- verify-context output shape remains unchanged
- issue ordering remains deterministic
- required actions remain stable and actionable
- all existing related context doctor and verify tests still pass

## Non-Goals
- Do not redesign the doctor-rule engine.
- Do not add auto-repair behavior.
- Do not normalize additional artifacts in this spec.
- Do not broaden this into repository-wide content linting.
- Do not change execution-spec naming, allocation, or validation rules.

## Canonical Context
- Canonical feature spec: `docs/features/context-persistence.spec.md`
- Canonical feature state: `docs/features/context-persistence.md`
- Canonical decision ledger: `docs/features/context-persistence.decisions.md`

## Authority Rule
- Context diagnostics must remain deterministic, feature-scoped, and grounded in canonical feature context.
- New rules must integrate through the existing internal rule structure rather than ad hoc service conditionals.
- External doctor and verify-context contracts must remain stable.

## Completion Signals
- At least two new high-value context-diagnostic rules exist through the internal rule model.
- New issue codes are stable and deterministic.
- `context doctor` and `verify context` keep their current external output shapes.
- Required actions remain actionable and deterministic.
- Existing behavior remains intact.
- All tests pass.

## Post-Execution Expectations
- Foundry catches more real context problems before implementation proceeds.
- The doctor-rule system becomes materially more valuable without becoming noisy.
- Future context diagnostics become cheaper to add.
