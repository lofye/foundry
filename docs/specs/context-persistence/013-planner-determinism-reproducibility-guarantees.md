# Execution Spec: 013-planner-determinism-reproducibility-guarantees

## Feature
- context-persistence

## Purpose
- Ensure that execution spec planning is fully deterministic and reproducible.
- Guarantee that identical canonical inputs always produce identical planning outputs (or identical blocked results).

## Scope
- Enforce determinism in the ExecutionSpecPlanner and related planning flow.
- Cover both:
    - successful planning (generated spec)
    - blocked planning (no bounded step)

## Constraints
- Canonical feature context remains the only source of truth.
- No LLM usage.
- No randomness or non-deterministic ordering.
- Must reuse existing planner structure.
- Must not change canonical feature documents.

## Requested Changes

### 1. Deterministic Input Handling
- Ensure all planner inputs are processed in a stable, deterministic order.
- Normalize ordering of:
    - parsed spec sections
    - state sections
    - decision entries

### 2. Deterministic Gap Detection
- Given identical inputs, gap detection must always produce the same result.
- No dependence on:
    - file system ordering
    - array iteration side-effects
    - unordered collections

### 3. Deterministic Output Generation
For planned specs:
- Purpose, Scope, Requested Changes, and slug must be identical across runs.
- Slug generation must be stable and derived only from deterministic inputs.

For blocked results:
- status, issues, and required_actions must be identical across runs.

### 4. Stable Slug Generation
- Ensure slug generation:
    - uses deterministic tokenization
    - avoids randomness or ordering variance
    - produces identical slugs for identical inputs

### 5. Tautology + Determinism Interaction
- Ensure tautology detection does not introduce non-deterministic retries.
- If retry logic exists, it must be deterministic and bounded.

### 6. Add Determinism Tests
Add PHPUnit coverage to assert:

- Running planner multiple times with identical inputs produces identical:
    - spec_id
    - spec contents
    - blocked responses

- Reordering irrelevant input sections does NOT change output.

- Known fixture inputs produce fixed expected outputs.

## Non-Goals
- Do not improve planner quality (handled in 012).
- Do not introduce new planning capabilities.
- Do not modify canonical feature documents.
- Do not introduce LLM-based planning.

## Canonical Context
- docs/features/context-persistence.spec.md
- docs/features/context-persistence.md
- docs/features/context-persistence.decisions.md

## Authority Rule
- Planner output must be a pure function of canonical context.
- Same input must always yield the same output.

## Completion Signals
- Planner produces identical output across repeated runs.
- Planner produces identical output regardless of irrelevant input ordering.
- Slugs are stable and repeatable.
- Blocked responses are deterministic.
- All tests pass.

## Post-Execution Expectations
- Developers can trust planner output to be stable and reproducible.
- Planning results are predictable and suitable for automation and CI.
