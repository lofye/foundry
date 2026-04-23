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

### Decision: add conservative persisted-plan undo with explicit rollback inputs and destructive-confirmation gating

Timestamp: 2026-04-23T12:29:29-04:00

**Context**

- The `003.002-plan-undo` execution spec required a first undo layer for persisted generate plans, but it explicitly rejected pretending that Foundry could perform perfect rollback for every action type.
- Existing persisted plan records already carried executable plan data and executed-action summaries, but they did not preserve the pre-change file contents needed to restore updated or deleted files safely.
- Create-file rollback is destructive because the reversal deletes files, and update/delete rollback is only trustworthy when the prior contents were captured explicitly.

**Decision**

- Add `plan:undo <plan_id>` as an explicit persisted-plan rollback surface with `--dry-run` preview support and `--yes` confirmation gating for destructive generated-file deletion.
- Extend successful live plan records to persist the minimal pre-change file snapshots needed for conservative V1 undo under the repository-local `.foundry/plans/` artifact contract.
- Limit V1 undo to deterministic file-action rollback only: delete newly created generated files when the stored pre-change snapshot proves the path was absent, restore updated files only when prior contents were persisted, and restore deleted files only when prior contents were persisted.
- Report irreversible actions, unsafe current-state skips, and partial outcomes explicitly instead of silently guessing rollback behavior.

**Reasoning**

- Persisting only the minimal rollback inputs needed for supported file actions keeps the plan artifact contract local and deterministic without introducing full repository snapshots or hidden history stores.
- Requiring explicit confirmation before deleting generated files makes undo trustworthy for humans and agents, especially in non-interactive or JSON-driven flows.
- Treating missing rollback data and current-state drift as explicit irreversible or skipped cases preserves user trust better than attempting best guesses that might overwrite manual edits.

**Alternatives Considered**

- Add git-based rollback as the primary undo mechanism in this step.
- Reconstruct previous file contents heuristically from plan metadata or current repository state.
- Allow destructive create-file reversal by default without requiring an explicit confirmation step.

**Impact**

- Foundry now has a first-class persisted-plan undo surface that is honest about what V1 can and cannot reverse.
- Successful generate runs carry enough local rollback data for supported update and delete reversals without changing the broader history subsystem.
- Future undo work can extend this contract deliberately while preserving the current conservative guarantee.

**Spec Reference**

- Goals
- Constraints
- Requested Changes
- Acceptance Criteria

### Decision: implement explicit persisted-plan replay on top of the existing generate execution path

Timestamp: 2026-04-23T10:11:10-04:00

**Context**

- `003.001-plan-replay` required persisted generate plans to become executable inputs rather than inspection-only artifacts.
- The repository already persisted canonical plan records under `.foundry/plans/`, but replay still needed a safe command surface, drift handling, and deterministic execution based on stored plan data.
- Replay needed to reuse existing generate validation, git safety, execution ordering, and verification behavior without silently creating a fresh plan behind the user’s back.

**Decision**

- Add `plan:replay <plan_id>` as an explicit replay command over persisted generate plan artifacts.
- Reconstruct replay execution from the stored approved final plan when present and otherwise the stored original plan, together with persisted intent metadata needed for deterministic validation and execution.
- Support adaptive replay by default, strict replay failure with `--strict`, and validation-only dry runs with `--dry-run`.
- Surface drift explicitly in both success and strict-failure paths instead of silently adapting the stored artifact itself.

**Reasoning**

- Reusing the stored plan artifact keeps replay truthful to the persisted generate result and avoids quietly re-planning from changed system state.
- Keeping replay on the existing generate execution path preserves deterministic file ordering, validator behavior, git safety checks, and verification semantics.
- Explicit drift reporting lets adaptive replay remain useful while still giving strict replay a clear contract for reproducibility-sensitive use cases.

**Alternatives Considered**

- Regenerate a fresh plan from the stored intent and call that replay.
- Block replay entirely unless current source hashes and filesystem state match exactly.
- Create a second replay-only executor disconnected from the existing generate engine helpers.

**Impact**

- Persisted plans are now reusable operational inputs rather than one-time history artifacts.
- Developers and agents can choose between adaptive replay, strict replay, and dry-run replay with deterministic JSON output and explicit drift visibility.
- The replay foundation now exists for future undo and richer policy layers without weakening the stored-plan contract.

**Spec Reference**

- Goals
- Constraints
- Expected Behavior
- Acceptance Criteria

### Decision: make interactive generate smoke coverage use a valid mode-bearing invocation

Timestamp: 2026-04-22T00:15:00-04:00

**Context**

- Interactive generate behavior was already implemented and covered by integration tests, but the older `001-interactive-generate-plan-review` execution spec still showed a mode-less `foundry generate "<intent>" --interactive` command shape.
- The actual CLI contract requires `--mode=new|modify|repair`, so a mode-less smoke example was misleading even though the working tests already used valid invocations.
- `001.001-valid-interactive-generate-smoke-invocation` required one explicit harmless smoke-style path that proves review logic is reached rather than failing in argument validation.

**Decision**

- Keep interactive generate smoke coverage on the existing CLI integration path.
- Add one explicit non-destructive smoke-style integration test that uses `foundry generate ... --mode=new --interactive --json`, reaches review logic, records rejection, and performs no writes.
- Update the older execution-spec example so repository documentation no longer suggests a mode-less interactive invocation.

**Reasoning**

- The implementation gap was primarily contract drift and the absence of one named smoke-style assertion, not missing core interactive behavior.
- Reusing the existing integration harness keeps the change narrow and deterministic.
- A rejection path is the safest way to prove interactive entry and decision handling without depending on risky filesystem mutation.

**Alternatives Considered**

- Add a second bespoke smoke harness outside the existing generate integration tests.
- Leave the older execution-spec example unchanged because execution specs are non-authoritative after implementation.
- Use an approval path that writes files as the smoke check.

**Impact**

- The repository now has truthful, explicit smoke coverage for the valid interactive generate entry path.
- Future regressions that accidentally reintroduce early `GENERATE_MODE_REQUIRED` failures into the smoke path are easier to catch.
- Generate-engine docs and implementation coverage now align on the required `--mode` contract for interactive usage.

**Spec Reference**

- Constraints
- Expected Behavior
- Acceptance Criteria

### Decision: expose deterministic generate safety-routing guidance as payload-level skill contract data

Timestamp: 2026-04-22T10:20:00-04:00

**Context**

- `002-generate-skill-integration` required agents to choose deterministically between the fast non-interactive generate path and interactive review based on intent, risk, and context.
- The repository does not have an in-process skill runtime that can execute a named Codex skill inside the framework itself.
- The generate engine already computes plan confidence, plan structure, and interactive risk signals that can drive the routing decision without changing core generation behavior.

**Decision**

- Implement `generate-with-safety-routing` as a deterministic payload contract emitted by `foundry generate` rather than as a framework-internal skill runner.
- Add a dedicated safety router that inspects explicit interactive intent, CI context, plan confidence, and generate-plan risk to recommend either `interactive` or `non_interactive`.
- Persist the routing recommendation in generate JSON and history payloads, and surface the recommended mode in default human output.

**Reasoning**

- Emitting the routing contract in payload data keeps the framework focused on deterministic planning and execution while still giving agents a stable integration point.
- Reusing existing plan confidence and risk analysis avoids duplicating generate logic or creating a second planning pass purely for routing.
- Including the same recommendation in both human and machine-readable output keeps developers and agents aligned on why a route was chosen.

**Alternatives Considered**

- Build a framework-owned skill runtime that executes named agent skills directly.
- Route only on risk level and ignore plan confidence or CI context.
- Keep routing knowledge external to the framework and require every agent integration to reimplement it independently.

**Impact**

- Agents can consume `safety_routing` deterministically to decide when to stay on the fast path and when to escalate to interactive review.
- Generate output remains backward compatible while becoming more useful for automation-aware tooling.
- Future CLI work can add `--no-interactive` as an explicit override without changing the routing contract shape.

**Spec Reference**

- Goals
- Constraints
- Expected Behavior
- Acceptance Criteria

### Decision: persist terminal generate runs as repository-local plan artifacts while retaining the broader history surface

Timestamp: 2026-04-23T00:40:00-04:00

**Context**

- The `003-plan-persistence-and-history` execution spec required every terminal generate run to become a first-class persisted artifact with enough canonical data for later inspection, replay, and undo work.
- The repository already had `history` support backed by `app/.foundry/build/history`, but that surface was shared across build, quality, observability, and generate records and stored a narrower summary-oriented generate payload.
- The new contract needed stable plan ids, append-only repository-local storage, and dedicated inspection commands without regressing the existing generate workflow or broader history features.

**Decision**

- Add a dedicated persisted plan store under `.foundry/plans/` for terminal generate runs.
- Keep plan records append-only and machine-readable with explicit storage versioning, UUID plan ids, filesystem-safe timestamps, canonical context and plan data, and terminal status semantics (`success`, `failed`, `aborted`).
- Add `plan:list` and `plan:show <plan_id>` as the dedicated inspection surface for persisted generate plan history.
- Retain the older `history --kind=generate` surface for compatibility and broader build/observability-style history, rather than replacing it in this step.

**Reasoning**

- A dedicated plan store keeps the replay/undo foundation explicit instead of overloading the broader shared history surface with plan-specific semantics.
- Persisting canonical plan data at terminal states makes rejected interactive sessions and failed generate attempts inspectable without pretending they succeeded.
- Keeping the existing `history` surface avoids unnecessary churn in unrelated observability and compatibility workflows while the new plan contract stabilizes.

**Alternatives Considered**

- Extend `app/.foundry/build/history` in place and skip a dedicated `.foundry/plans/` contract.
- Persist only successful generate runs and treat failures or interactive rejections as logs-only events.
- Add replay or undo execution in the same step instead of separating persistence from later action-taking commands.

**Impact**

- Generate-engine now has a repository-local, append-only plan history contract suitable for later replay, undo, and audit work.
- Developers and agents can inspect terminal generate runs deterministically by plan id through `plan:list` and `plan:show`.
- Existing history-oriented workflows remain intact while generate gains a more purpose-built persisted artifact surface.

**Spec Reference**

- Goals
- Constraints
- Expected Behavior
- Acceptance Criteria
