# Execution Spec: 015-state-normalization-pass-and-canonical-ordering

## Feature
- context-persistence

## Purpose
- Normalize the feature state document so it stays concise, non-duplicative, and planner-friendly as the context system grows.
- Establish a canonical ordering rule for state sections and bullet content that preserves current truth while reducing noise and drift.

## Scope
- Clean and normalize `docs/features/context-persistence.md`.
- Remove duplicated or redundant bullets from `Current State` and `Next Steps`.
- Establish canonical ordering guidance for feature state documents.
- Keep the meaning of the current state intact while improving readability and downstream planner input quality.

## Constraints
- Keep canonical feature context authoritative.
- Preserve meaningful current-state facts.
- Preserve append-only behavior for the decision ledger; this spec does not rewrite history.
- Do not change intended feature behavior.
- Do not weaken alignment, execution, or planning guarantees.
- Keep the resulting state document deterministic and easy to maintain.

## Requested Changes
- Normalize `docs/features/context-persistence.md` so:
  - `Current State` contains only statements that are true now
  - `Next Steps` contains only real future-oriented items
  - duplicated bullets are removed
  - repetitive acceptance-criteria restatements are removed unless they add state value
- Introduce a canonical ordering approach for state bullets:
  - foundational facts first
  - implemented capabilities next
  - current guarantees next
  - open future-facing items last
- Ensure state language is concise, concrete, and non-tautological.
- Ensure planner-relevant facts remain explicit.
- Add or update tests only if the implementation introduces automated normalization or ordering logic.

## Non-Goals
- Do not rewrite `docs/features/context-persistence.spec.md` unless intent truly changed.
- Do not compact or rewrite the decision ledger.
- Do not introduce LLM-based state synthesis.
- Do not invent new work just to populate `Next Steps`.

## Canonical Context
- Canonical feature spec: `docs/features/context-persistence.spec.md`
- Canonical feature state: `docs/features/context-persistence.md`
- Canonical decision ledger: `docs/features/context-persistence.decisions.md`

## Authority Rule
- The feature spec remains the source of intended behavior.
- The state document remains the source of current reality.
- State normalization may improve structure and wording, but must not change feature intent.

## Completion Signals
- `docs/features/context-persistence.md` is deduplicated and canonically ordered.
- `Current State` and `Next Steps` are clearly separated.
- Planner-relevant facts remain explicit.
- Alignment stays green.
- All tests pass.

## Post-Execution Expectations
- The state document is easier to read, maintain, and plan from.
- Future spec implementations can update state consistently after each change.
- The context system remains self-hosting and easier to reconcile.
