# Execution Spec: 002-repair-cli-experience-context-alignment

## Feature
- cli-experience

## Purpose
- Repair `cli-experience` canonical context so repo-wide `verify context --json` passes again.
- Align the feature spec, state document, and decision ledger with the current real implementation state of CLI autocomplete work.
- Keep the repair narrow, truthful, and consistent with Foundry’s context-anchoring rules.

## Scope
- Repair context alignment for:
  - `docs/cli-experience/cli-experience.spec.md`
  - `docs/cli-experience/cli-experience.md`
  - `docs/cli-experience/cli-experience.decisions.md`
- Resolve the current `alignment_status = mismatch` condition for `cli-experience`.
- Keep this focused on canonical-context repair only.

## Constraints
- Keep the repair minimal.
- Do not invent implementation that does not exist.
- Do not broaden this into additional CLI feature work.
- Do not change CLI behavior in this spec unless a tiny documentation-alignment code change is absolutely necessary.
- Preserve meaning and keep the current intended boundaries of the `cli-experience` feature.
- Follow Foundry context rules:
  - spec = intended behavior
  - state = current reality
  - decisions = divergence/history

## Inputs

Expect inputs such as:
- the current `verify context --json` failure for `cli-experience`
- `docs/cli-experience/cli-experience.spec.md`
- `docs/cli-experience/cli-experience.md`
- `docs/cli-experience/cli-experience.decisions.md`

If any critical input is missing:
- fail clearly and deterministically
- do not invent replacement context
- do not silently drop required context sections

## Requested Changes

### 1. Repair Expected Behavior / Acceptance Criteria Tracking

Inspect `cli-experience.spec.md` and make sure every currently relevant requirement from:

- `## Expected Behavior`
- `## Acceptance Criteria`

is reflected in one of:

- `## Current State`
- `## Open Questions`
- `## Next Steps`

within `cli-experience.md`

If a requirement is implemented now:
- reflect it in `Current State`

If a requirement is intentionally pending:
- reflect it in `Next Steps`

If a requirement is unresolved or conditional:
- reflect it in `Open Questions`

### 2. Remove or Rewrite Unsupported State Claims

Inspect `cli-experience.md` for any `Current State` claims that are not grounded in:

- `cli-experience.spec.md`
- or `cli-experience.decisions.md`

For each unsupported claim, do the smallest correct thing:

- remove it
- rewrite it to match current truth
- or add a supporting decision entry if the divergence is intentional

Do not leave unsupported `Current State` claims in place.

### 3. Use the Decision Ledger Only When Needed

Use `cli-experience.decisions.md` only where a real divergence or intentional boundary needs to be recorded.

Do not add decision entries just to restate obvious alignment.
If a divergence exists and is intended, log it clearly in the decision ledger using the existing house style.

### 4. Keep the Repair Narrow

This spec is for context repair, not feature expansion.

Do not:
- add new CLI behavior
- add new shell support
- add new completion surfaces
- revise unrelated specs
- broaden the feature definition beyond what is necessary to make canonical context truthful and aligned

### 5. Verify the Repair

After editing, run:

```bash
php bin/foundry verify context --feature=cli-experience --json
```

The repair is not complete unless this returns:

- `status = pass`
- `can_proceed = true`
- `requires_repair = false`

### 6. Tests

No new PHPUnit coverage is required unless a real code or parser behavior change becomes necessary.
If any code change is made, add the smallest relevant test coverage and explain why it was required.

## Non-Goals
- Do not redesign the `cli-experience` feature.
- Do not add autocomplete support for additional shells in this spec.
- Do not change execution-spec planning behavior.
- Do not repair unrelated features in this spec.
- Do not use this spec as a placeholder for future CLI enhancements.

## Canonical Context
- Canonical feature spec: `docs/cli-experience/cli-experience.spec.md`
- Canonical feature state: `docs/cli-experience/cli-experience.md`
- Canonical decision ledger: `docs/cli-experience/cli-experience.decisions.md`

## Authority Rule
- Canonical feature context must truthfully reflect current CLI autocomplete intent and implementation state.
- State claims must be grounded in the spec or decision ledger.
- Alignment repair must prefer the smallest truthful change.

## Completion Signals
- `php bin/foundry verify context --feature=cli-experience --json` passes.
- `cli-experience.md` reflects current reality without unsupported claims.
- Relevant spec requirements are tracked in `Current State`, `Open Questions`, or `Next Steps`.
- Any intentional divergence is documented in the decision ledger.
- No unnecessary feature expansion was introduced.

## Post-Execution Expectations
- Repo-wide `verify context --json` can pass once `cli-experience` is no longer the failing feature.
- The `cli-experience` feature context becomes a trustworthy foundation for future CLI usability work.
- Future work under `cli-experience` can proceed without carrying forward context drift.
