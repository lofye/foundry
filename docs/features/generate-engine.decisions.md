### Decision: create canonical generate-engine context around the existing generate workflow and track interactive review as the next bounded step

Timestamp: 2026-04-21T10:30:00-04:00

**Context**

- The repository already ships a substantial `generate` implementation with deterministic planning, validation, execution, verification, confidence reporting, git safety checks, and explain snapshot support.
- Execution specs existed under `docs/specs/generate-engine/`, but the canonical feature spec, state document, and decision ledger had never been created.
- Work on `001-interactive-generate-plan-review` requires canonical context before runtime behavior can change.

**Decision**

- Create `generate-engine` as a standalone canonical feature.
- Ground the feature spec in the existing non-interactive generate workflow.
- Track interactive plan review, approval, minimal modification, and risk gating as the next bounded implementation step for this feature.

**Reasoning**

- The generate engine is already a meaningful subsystem with its own CLI contract, safety model, and verification path.
- Canonical context must describe current reality first so alignment checks can distinguish implemented behavior from pending work.
- Framing interactive review as the next bounded step lets the repository proceed compliantly without inventing a second planning surface or hiding missing behavior.

**Alternatives Considered**

- Keep relying on execution specs without canonical feature context.
- Fold generate-engine concerns into an unrelated feature such as `cli-experience`.
- Write the canonical spec as if interactive review were already implemented.

**Impact**

- `generate-engine` now has canonical spec, state, and decision files that reflect the existing implementation and pending interactive work.
- Context verification can gate future generate-engine changes against an authoritative source of truth.
- `001-interactive-generate-plan-review` can proceed as an implementation step once the repaired context passes validation.

**Spec Reference**

- Purpose
- Goals
- Constraints
- Expected Behavior
- Acceptance Criteria

### Decision: keep interactive generate review as an explicit spec-state divergence until execution spec 001 is implemented

Timestamp: 2026-04-21T10:35:00-04:00

**Context**

- The canonical `generate-engine` spec now records the intended interactive review, approval, modification, and risk-gating behavior.
- The current implementation still only exposes the non-interactive generate workflow.
- The state document needs to say plainly that interactive generate review, pre-apply plan editing, and interactive decision capture are not implemented yet.

**Decision**

- Allow the canonical spec to describe the intended interactive end-state now.
- Record the current absence of `--interactive` review behavior as a temporary, explicit spec-state divergence until `001-interactive-generate-plan-review` lands.

**Reasoning**

- The feature spec should preserve the intended contract for the generate engine instead of collapsing to only today’s implementation details.
- The state document must remain truthful about the current CLI and payload behavior.
- Logging the gap in the decision ledger lets context validation distinguish intentional pending work from accidental drift.

**Alternatives Considered**

- Rewrite the feature spec to describe only the currently implemented non-interactive workflow.
- Leave the state document vague about the missing interactive behavior.
- Treat the execution spec as the source of truth instead of the canonical feature context.

**Impact**

- The feature context can remain honest about both the intended interactive behavior and the still-missing implementation.
- Context verification has an explicit rationale for the current absence of interactive generate review, minimal plan modification, and interactive decision capture.
- Implementation work can proceed without hiding the gap or weakening the canonical spec.

**Spec Reference**

- Expected Behavior
- Acceptance Criteria

### Decision: implement interactive generate review as a thin layer over the existing planning and verification pipeline

Timestamp: 2026-04-21T15:05:00-04:00

**Context**

- `001-interactive-generate-plan-review` required `generate` to expose plan review, diffs, approval, minimal modification, risk gating, and richer result payloads.
- The repository already had a working non-interactive generate engine with deterministic planning, validation, execution, and verification.
- Interactive plan modification needed to affect actual execution rather than only the displayed preview.

**Decision**

- Implement interactive generate review as a dedicated review layer that sits on top of the existing generate planner and validator.
- Reuse the current plan, validation, and verification pipeline for both previewed and approved plans.
- Make the reviewed action list authoritative at execution time so excluded files or actions are not still written by the underlying strategy.

**Reasoning**

- A thin review layer preserves the existing deterministic generate architecture and avoids creating a second planning system.
- Reusing the current validator and verification runner keeps safety checks aligned between interactive and non-interactive modes.
- Executing only the approved action subset is required for interactive modification to be trustworthy instead of cosmetic.

**Alternatives Considered**

- Fork the generate engine into separate interactive and non-interactive execution paths.
- Limit interactive mode to approve or reject only and skip minimal plan modification.
- Keep strategy-level execution unchanged and treat interactive exclusions as preview-only.

**Impact**

- `foundry generate --interactive` now shows summary, detail, and unified diffs before mutation, supports approve or reject, supports minimal plan filtering, records user decisions, and enforces high-risk confirmation.
- Interactive execution now honors filtered plans at apply time while preserving the existing non-interactive workflow.
- Canonical feature state can now track `002-generate-skill-integration` and preview-strategy expansion as follow-on work instead of pending core interactive implementation.

**Spec Reference**

- Goals
- Constraints
- Expected Behavior
- Acceptance Criteria
