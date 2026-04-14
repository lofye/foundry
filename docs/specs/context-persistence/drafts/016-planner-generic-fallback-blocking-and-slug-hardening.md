# Execution Spec: context-persistence/016-planner-generic-fallback-blocking-and-slug-hardening

## Feature
- context-persistence

## Purpose
- Prevent the planner from generating low-value fallback execution specs with generic titles or slugs when no meaningful bounded step exists.
- Tighten planner output so vague fallback behavior becomes a blocked result instead of a misleading execution spec.

## Scope
- Harden `plan feature` against generic fallback spec generation.
- Tighten slug generation rules.
- Tighten planner acceptance rules for generated purpose, scope, requested changes, and completion signals.
- Keep this focused on planner output quality and planner refusal behavior, not parser structure.

## Constraints
- Keep canonical feature context authoritative.
- Keep planning deterministic and reproducible.
- Preserve the existing blocked/planned contract.
- Do not introduce LLM-based planning.
- Do not weaken bounded-step requirements.
- Do not broaden this into general execution-spec parser hardening.

## Requested Changes

### 1. Block Generic Fallback Specs
Planner must block instead of generating an execution spec when the best candidate collapses into a generic fallback such as:
- `initial`
- `update`
- `improve`
- `support`
- `ensure`
- other similarly low-information fallback slugs or titles

If no meaningful bounded step exists, return a blocked result with actionable required actions.

### 2. Harden Slug Generation
Generated slugs must:
- reflect the actual bounded work
- be derived from concrete planner output
- avoid default/generic placeholders
- be deterministic across repeated runs

Slug generation must fail closed:
- if a concrete, meaningful slug cannot be derived, planning must block

### 3. Harden Purpose / Scope / Requested Changes Acceptance
Planner must reject candidate output when:
- purpose is generic or tautological
- scope is too broad or too vague
- requested changes merely restate a high-level phrase without concrete work
- completion signals are too broad to verify the bounded step

Rejected candidates must not be written as execution specs.

### 4. Harden Completion Signals
Completion signals must reflect the bounded step itself, not merely broad project health.

Bad example:
- `verify context --feature=context-persistence returns pass after execution`

Good examples:
- a specific behavior now exists
- a specific output mapping now occurs
- a specific blocked condition now fires

If completion signals are too generic, the planner must refine or block.

### 5. Preserve True Blocking Behavior
When canonical context contains no meaningful next step, planner must return:
- `status = blocked`
- a specific issue code
- actionable `required_actions`

It must not synthesize a weak fallback spec just to satisfy the plan command.

### 6. Tests
Add coverage to prove:
- generic fallback slugs are blocked
- low-information fallback specs are not emitted
- concrete bounded specs still generate successfully
- repeated identical inputs still produce identical blocked or planned outputs
- completion-signal quality gates work deterministically

## Non-Goals
- Do not implement broad execution-spec parser hardening here.
- Do not change canonical feature authority.
- Do not introduce rename/insert execution-spec commands.
- Do not broaden this spec into generic content cleanup outside planner output.

## Canonical Context
- Canonical feature spec: `docs/features/context-persistence.spec.md`
- Canonical feature state: `docs/features/context-persistence.md`
- Canonical decision ledger: `docs/features/context-persistence.decisions.md`

## Authority Rule
- Canonical feature context remains the source of truth.
- Planner may derive bounded work only from concrete gaps in canonical context.
- If only a vague fallback remains, the planner must block.

## Completion Signals
- Planner no longer emits generic fallback specs like `initial`.
- Weak slug candidates are blocked instead of written.
- Low-information purpose, scope, requested changes, or completion signals are rejected.
- Legitimate bounded specs still generate successfully.
- Deterministic planning behavior is preserved.
- All tests pass.

## Post-Execution Expectations
- `plan feature` emits either a meaningful bounded execution spec or a clean blocked result.
- Developers no longer see misleading fallback files generated from thin planner output.
- Planner output becomes safer to trust as the source of the next execution step.
