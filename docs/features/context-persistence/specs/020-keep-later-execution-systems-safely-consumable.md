# Execution Spec: 020-keep-later-execution-systems-safely-consumable

## Feature
- context-persistence

## Purpose
- Ensure canonical feature context is always safe and deterministic for downstream execution systems to consume without ambiguity, drift, or partial state.

## Scope
- Define and enforce the minimum guarantees required for execution systems (compiler, CLI, execution-spec runner, etc.) to safely consume canonical context files.
- Introduce validation rules that prevent unsafe or incomplete context from being used downstream.

## Constraints
- Canonical feature context remains the single source of truth.
- Execution systems must never infer or guess missing context.
- All safety checks must be deterministic and machine-verifiable.
- Do not duplicate logic already covered by context:doctor unless extending it explicitly.
- Respect prior decisions in docs/context-persistence/context-persistence.decisions.md.

## Requested Changes

### 1. Introduce “consumability” validation

Extend context verification to enforce that canonical context is **safe for downstream consumption**.

A feature is considered *not safely consumable* if any of the following are true:
- Required sections are missing or structurally invalid
- Alignment status is not `ok`
- Doctor status is not `ok`
- Any required_actions are present

### 2. Add a derived flag to verify output

Extend:

`php bin/foundry verify context --json`

to include per-feature:

```json
"consumable": true|false
```

Rules:
- `consumable = true` ONLY if:
  - doctor_status = ok
  - alignment_status = ok
  - required_actions = []

Otherwise:
- `consumable = false`

### 3. Enforce global consumability

At the root level:

```json
"can_proceed": true|false
```

must now also require:

- all features have `"consumable": true`

### 4. Prevent unsafe downstream execution

Any system that depends on canonical context MUST refuse to proceed if:

`consumable = false`

This includes (but is not limited to):
- execution-spec runner
- compile graph (if it relies on context)
- CLI commands that depend on feature context

### 5. Standardize refusal reason

When refusing due to non-consumable context, output:

```json
{
  "status": "fail",
  "reason": "context_not_consumable",
  "required_action": "Run `php bin/foundry verify context --json` and resolve all issues before proceeding."
}
```

### 6. Do NOT modify existing doctor or alignment rules

This spec:
- does NOT change how doctor works
- does NOT change alignment rules

It only:
- **derives a strict gate from their outputs**

## Acceptance Criteria

- `verify context --json` includes `"consumable"` per feature
- `can_proceed` becomes false if any feature is not consumable
- downstream systems refuse to run when consumable = false
- refusal output is deterministic and matches spec
- no existing passing behavior is broken

## Completion Signals

- Running `verify context --json` shows `"consumable": true` for all passing features
- Introducing a context misalignment flips `"consumable": false`
- downstream commands fail fast with the defined refusal message
- PHPUnit includes coverage for:
  - consumable true case
  - consumable false case
  - refusal behavior

## Post-Execution Expectations

- Execution systems no longer rely on implicit assumptions about context
- Context safety becomes an enforced invariant, not a convention
- Future specs (021+) can assume context is safe when execution begins
