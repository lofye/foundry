# Execution Spec: <id>-<slug>

## Feature
- <feature-name>

## Purpose
- What this implementation step is for
- Why this bounded work order exists now

## Scope
- What this spec will implement now
- What part of the feature this execution step is responsible for

## Constraints (avoid "DO NOT" phrasing, but use positive boundary wording like: “preserve”, “reuse”, “keep”, “fail clearly”, etc.)
- Keep this execution spec secondary to canonical feature truth.
- Reuse existing execution, validation, and readiness pipelines where possible.
- Preserve existing context rules and refusal semantics.
- Fail clearly when conflicts exist.
- Keep this work bounded, deterministic, and traceable.

## Requested Changes
- Concrete changes to implement in this step
- Specific additions, updates, or refactors to perform now

## Non-Goals
- What this execution spec must not change
- What should be deferred to later execution specs

## Canonical Context
- Canonical feature spec: `docs/<feature-name>/<feature-name>.spec.md`
- Canonical feature state: `docs/<feature-name>/<feature-name>.md`
- Canonical decision ledger: `docs/<feature-name>/<feature-name>.decisions.md`

## Authority Rule
- This execution spec is a bounded work order only.
- It does not replace or override the canonical feature spec.
- If this execution spec conflicts with canonical feature intent, the canonical feature spec wins.

## Completion Signals
- What should be true when this execution step is complete
- What outputs, behaviors, or validations should exist after completion

## Post-Execution Expectations
- Update the feature state document to reflect what changed
- Append decision entries for meaningful technical or architectural choices
- Re-run context validation and alignment checks
