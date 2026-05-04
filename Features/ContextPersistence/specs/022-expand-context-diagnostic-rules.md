# Execution Spec: 022-expand-context-diagnostic-rules

## Feature
- context-persistence

## Purpose
- Expand the rule-driven context-diagnostic system beyond `EXECUTION_SPEC_DRIFT`.
- Detect additional **high-signal inconsistencies between spec, state, and decisions**.
- Strengthen context reliability without increasing diagnostic noise.
- Build on the internal doctor-rule architecture introduced in 015.002 without changing external contracts.

## Scope
- Add **exactly two (2)** new context-diagnostic rules.
- Rules must operate through the normalized doctor-rule model (015.002).
- Reuse existing doctor and verify-context output structures.
- Do not modify the doctor/verify pipelines.

## Constraints
- Diagnostics must be:
  - deterministic
  - feature-scoped
  - reproducible across runs
- Preserve:
  - `context doctor --json` contract
  - `verify context --json` contract
- Use existing rule infrastructure only (no ad hoc conditionals)
- Prefer:
  - high-signal rules
  - low false-positive rate
- Do not:
  - duplicate structural doctor checks
  - introduce auto-repair
  - introduce new public schemas

## Inputs
- `docs/features/<feature>/<feature>.spec.md`
- `docs/features/<feature>/<feature>.md`
- `docs/features/<feature>/<feature>.decisions.md`
- doctor rule infrastructure from 015.002

If inputs are missing:
- fail deterministically
- do not silently skip rules

## Requested Changes

### 1. Implement Exactly Two High-Value Rules

Implement **exactly two rules**, selected from the following approved set:

#### Rule A: STATE_CLAIM_WITHOUT_SPEC_SUPPORT
Detect when:
- the feature state (`.md`) claims something is implemented or supported
- but no corresponding intent exists in the feature spec (`.spec.md`)

#### Rule B: SPEC_REQUIREMENT_NOT_TRACKED_IN_STATE
Detect when:
- the feature spec defines a requirement or behavior
- but the state document does not reflect it in any of:
  - Current State
  - Next Steps
  - Open Questions

#### Rule C: DECISION_MISSING_FOR_STATE_DIVERGENCE
Detect when:
- state behavior diverges from spec intent
- and no corresponding entry exists in the decision ledger

#### Rule D: STALE_COMPLETED_ITEMS_IN_NEXT_STEPS
Detect when:
- items in `Next Steps` describe work already implemented
- and remain listed as pending

### Rule Selection Constraint
- Implement **exactly two (2)** rules
- Choose the **highest-signal pair**

### 2. Stable Issue Codes
Each rule must emit a stable code:
- STATE_CLAIM_WITHOUT_SPEC_SUPPORT
- SPEC_REQUIREMENT_NOT_TRACKED_IN_STATE
- DECISION_MISSING_FOR_STATE_DIVERGENCE
- STALE_COMPLETED_ITEMS_IN_NEXT_STEPS

### 3. Required Actions
Each rule must emit:
- precise
- minimal
- deterministic repair actions

### 4. Deterministic Ordering
- rule execution order must be stable
- issue ordering must be stable

### 5. Tests
Add tests proving:
- rules fire correctly
- no false positives
- output shape unchanged
- ordering deterministic

## Non-Goals
- no engine redesign
- no auto-repair
- no schema changes

## Completion Signals
- Exactly two new rules implemented
- Rules are high-signal and low-noise
- All tests pass
