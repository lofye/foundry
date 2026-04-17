# Execution Spec: 020-fails-when-doctor-repairable

## Feature
- context-persistence

## Purpose
- Current State does not yet reflect fails when doctor is repairable or non_compliant, so this is the next bounded step now.

## Scope
- Verify context status mapping and output.

## Constraints
- Keep canonical feature context authoritative.
- Keep generated execution specs secondary to canonical feature truth.
- Keep this work deterministic and bounded to one coherent step.
- Respect prior decisions recorded in docs/features/context-persistence.decisions.md.

## Requested Changes
- Verify context fails when doctor is repairable or non_compliant.

## Non-Goals
- Do not broaden this step beyond Verify context status mapping and output.
- Do not change canonical feature context authority.

## Completion Signals
- Verify context fails when doctor is repairable or non_compliant.
- docs/features/context-persistence.md reflects the completed bounded step.
- verify context --feature=context-persistence returns pass after execution.

## Post-Execution Expectations
- Current State reflects the completed bounded work.
- Meaningful execution decisions are appended to docs/features/context-persistence.decisions.md when needed.
- Canonical feature context remains authoritative for later work.
