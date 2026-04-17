### Decision: establish execution-spec-system as a standalone feature
Timestamp: 2026-04-14T10:03:15-04:00

**Context**
- Foundry already supported planning and executing bounded work from `docs/specs/`, but the naming contract for execution specs was inconsistent across code, templates, and existing documents.
- Hierarchical padded ids and filename-only headings needed a clear canonical home.

**Decision**
- Create a standalone feature named `execution-spec-system`.
- Treat execution-spec naming, identity, heading, and placement rules as their own feature instead of folding them into an unrelated cleanup bucket.

**Reasoning**
- The execution-spec contract is cross-cutting and affects planners, resolvers, docs, and human workflows.
- A dedicated feature keeps the naming policy explicit and easier to evolve without obscuring why the rules exist.

**Alternatives Considered**
- Keep the work implicit inside `context-persistence`.
- Fold the changes into a broad documentation cleanup.

**Impact**
- Execution-spec naming now has a feature-owned canonical spec, state document, and decision history.
- Future changes to execution-spec hierarchy or allocation can build on a clear source of truth.

**Spec Reference**
- Purpose
- Goals
- Constraints

### Decision: keep filename identity canonical while leaving CLI lookup feature-qualified
Timestamp: 2026-04-14T10:04:00-04:00

**Context**
- The new naming contract makes the filename the canonical execution-spec identity, but CLI workflows still need an unambiguous way to locate a spec within a feature directory.
- Existing `plan feature` and `implement spec` flows already exchange feature-qualified refs such as `event-bus/001-contract-test-coverage`.

**Decision**
- Make the filename and its hierarchical id the canonical identity and heading source for execution specs.
- Keep fully qualified CLI lookup refs in the form `<feature>/<id>-<slug>` while still allowing unique filename shorthand resolution.

**Reasoning**
- This preserves unambiguous lookup without reintroducing duplicated identity inside the spec file itself.
- It keeps existing CLI workflows stable while moving canonical identity to the filename where the hierarchy actually lives.

**Alternatives Considered**
- Change all CLI references to filename-only ids immediately.
- Keep feature-prefixed headings and treat the path as the canonical identity.

**Impact**
- Resolver and planner behavior now align with the new filename contract without breaking the existing feature-qualified lookup flow.
- Future tooling can expose both canonical filename identity and convenient feature-qualified refs without conflating them.

**Spec Reference**
- Goals
- Constraints
- Expected Behavior

### Decision: block framework-repository execution specs before app-feature scaffolding
Timestamp: 2026-04-15T09:16:09-04:00

**Context**
- `implement spec execution-spec-system/004-spec-auto-log-on-implementation` in the framework repository routed through `ContextExecutionService::execute()` and the generic `FeatureGenerator`, which created accidental files under `app/features/execution-spec-system/`.
- Those files are application scaffold output, not valid framework-feature implementation artifacts.
- The framework repository needs an explicit safe failure mode until a dedicated framework-internal implementation path exists.

**Decision**
- When `implement spec` runs inside the framework repository, block execution specs before the generic `app/features/*` scaffold path.
- Return an explicit deterministic blocked result instead of creating or modifying application feature scaffolds for framework-internal work.

**Reasoning**
- The generic app-feature generator writes source-of-truth files that the framework compiler and runtime immediately treat as real application features.
- Blocking early prevents misplaced output from becoming live accidental input.
- An explicit block is safer than pretending framework execution succeeded through the wrong destination.

**Alternatives Considered**
- Keep routing framework execution specs through the generic app scaffold flow.
- Add a full dedicated framework-internal implementation path in the same change.
- Allow scaffold generation and clean it up later.

**Impact**
- Framework-repository execution specs no longer create `app/features/<feature>/` scaffolds.
- `implement spec` now fails honestly for framework-internal work until a dedicated framework execution path exists.
- Existing application-project behavior remains unchanged because the block applies only inside the framework repository.

**Spec Reference**
- Goals
- Constraints
- Expected Behavior

### Decision: narrow canonical conflict detection to forbidden-action evidence
Timestamp: 2026-04-15T00:53:58-04:00

**Context**
- `implement spec` was raising `EXECUTION_SPEC_CONFLICTS_WITH_CANONICAL_SPEC` for execution specs that were aligned with the canonical feature spec but happened to share several topic words with canonical negative constraints.
- The known false positive was the auto-log execution spec, where an instruction to append implementation-log entries overlapped lexically with the canonical logging constraint without contradicting it.
- Canonical feature authority still needed to be preserved for real conflicts such as renaming immutable ids or instructing forbidden draft execution.

**Decision**
- Narrow canonical conflict detection so that lexical topic overlap alone is no longer sufficient evidence of contradiction.
- Compare only positive execution-spec instruction items against forbidden clauses extracted from canonical non-goals and negative constraints.
- Keep the existing blocked result contract unchanged for true conflicts.

**Reasoning**
- Shared nouns show subject matter, not contradiction.
- Restricting comparison to positive execution instructions avoids treating aligned negative guardrails in execution specs as if they were conflicting with canonical guardrails.
- A deterministic forbidden-clause comparison keeps canonical protection in place without introducing probabilistic semantic inference.

**Alternatives Considered**
- Keep the raw token-overlap heuristic and reword execution specs to avoid overlapping nouns.
- Remove canonical conflict detection entirely.
- Replace the heuristic with an LLM-backed contradiction detector.

**Impact**
- Aligned execution specs that reinforce canonical behavior are no longer blocked falsely during `implement spec`.
- True contradictions against canonical non-goals or negative constraints still raise `EXECUTION_SPEC_CONFLICTS_WITH_CANONICAL_SPEC`.
- Conflict detection remains deterministic, testable, and conservative without relying on brittle topic overlap.

**Spec Reference**
- Goals
- Constraints
- Expected Behavior

### Decision: auto-log successful active execution-spec completion
Timestamp: 2026-04-14T23:35:32-04:00

**Context**
- The implementation-log format is already part of the execution-spec naming policy, but recording entries still depended on a manual follow-up after successful `implement spec` runs.
- Missing manual log updates weaken chronology and make the policy easy to skip even when active execution-spec implementation succeeded.

**Decision**
- Automatically append implementation-log entries when an active execution spec completes successfully through `implement spec`.
- Skip draft paths, prevent duplicate entries for the same active spec, and surface log-write failures as explicit implementation issues.

**Reasoning**
- Logging belongs in the execution-spec lifecycle contract, not as a separate human reminder.
- Idempotent append behavior prevents accidental duplicate chronology while keeping successful completion scriptable.
- Surfacing write failures inside the spec-execution result avoids silently claiming a clean success when the required log entry is missing.

**Alternatives Considered**
- Keep implementation-log updates manual.
- Append log entries from the CLI wrapper instead of the execution service.
- Ignore log-write failures and leave the implementation result as completed.

**Impact**
- Active execution-spec completion now keeps project chronology current automatically.
- Draft specs remain non-executable planning artifacts and are not recorded as implemented.
- Implement-spec callers now get a deterministic error path when the required implementation log cannot be written.

**Spec Reference**
- Goals
- Constraints
- Expected Behavior

### Decision: add a dedicated `spec:validate` CLI command for execution-spec rule enforcement
Timestamp: 2026-04-14T15:19:39-04:00

**Context**
- Execution-spec naming and placement rules are now documented and draft creation is deterministic, but there was still no single command to check repository-wide spec state before planning or implementation.
- Allocation already blocks on invalid spec state, which means developers and agents need a direct way to inspect all violations without mutating files.

**Decision**
- Add a dedicated `spec:validate` command that scans active and draft execution specs under `docs/specs/`.
- Report canonical filename, placement, heading, duplicate-id, and forbidden-metadata violations without modifying any files.

**Reasoning**
- A read-only validator gives agents and developers one deterministic entry point for enforcing the spec naming policy.
- Reporting every violation in one run reduces repair loops and keeps allocation failures easier to diagnose.
- Reusing the same canonical rules across docs, planning, creation, and validation reduces drift.

**Alternatives Considered**
- Keep validation implicit inside allocation and resolver failures only.
- Repair violations automatically during validation.
- Validate only one feature at a time instead of scanning the whole spec tree.

**Impact**
- Execution-spec rule enforcement is now explicit and scriptable.
- Invalid repository state can be diagnosed before `plan feature`, `spec:new`, or manual implementation work.

**Spec Reference**
- Goals
- Constraints
- Expected Behavior

### Decision: reuse one catalog for planner allocation and draft creation
Timestamp: 2026-04-14T14:05:00-04:00

**Context**
- `plan feature` already needed deterministic root-ID allocation, and `spec:new` now needs the same allocation rules plus clearer failure behavior when feature spec state is invalid.
- Duplicating directory scans and ID selection would create drift between planner-created specs and manually created drafts.

**Decision**
- Introduce one shared execution-spec catalog that scans active and draft specs, detects invalid or duplicate ID state, and allocates the next root ID.
- Reuse that catalog from both `ContextPlanningService` and `spec:new`.

**Reasoning**
- A shared catalog keeps allocation rules deterministic in one place.
- Validation of duplicate IDs and invalid spec filenames should be consistent regardless of which command is allocating the next spec.
- This avoids maintaining separate allocation heuristics for planner output and CLI draft creation.

**Alternatives Considered**
- Keep planner allocation private and duplicate similar logic inside `spec:new`.
- Let `spec:new` ignore invalid spec state and attempt best-effort allocation.

**Impact**
- Planner and draft creation now share one allocation source of truth.
- Invalid execution-spec state blocks allocation consistently instead of producing divergent behavior.

**Spec Reference**
- Goals
- Constraints
- Expected Behavior

### Decision: add a dedicated `spec:new` CLI command for deterministic draft creation
Timestamp: 2026-04-14T13:34:01-04:00

**Context**
- Execution-spec naming and allocation rules are now explicit, but draft creation still requires manual path construction and manual root-ID selection.
- The next execution spec for this feature defines a stable CLI contract for creating drafts safely.

**Decision**
- Add a dedicated `spec:new` command that creates draft execution specs under `docs/specs/<feature>/drafts/`.
- Reuse canonical allocation rules, deterministic slug normalization, and filename-only headings for generated drafts.

**Reasoning**
- Draft creation should use the same canonical naming contract that planning and resolution already enforce.
- A dedicated CLI surface reduces ID collisions and keeps agent and human workflows aligned.
- Stable plain-text output makes the command safer for both terminal use and automation.

**Alternatives Considered**
- Leave draft creation manual.
- Hide draft creation inside `plan feature`.
- Create drafts with interactive prompts instead of a deterministic command contract.

**Impact**
- Execution-spec creation becomes a first-class deterministic workflow instead of an undocumented manual step.
- Later validation and lifecycle tooling can build on the same shared draft-creation rules.

**Spec Reference**
- Goals
- Constraints
- Expected Behavior

### Decision: clarify auto-log failure semantics as partial success, not clean success
Timestamp: 2026-04-15T14:05:00-04:00

**Context**
- Automatic implementation-log append is now part of active execution-spec completion.
- In the implemented behavior, log-write failures do not erase the already-completed implementation work, but they also must not be reported as a clean success.
- The canonical feature spec and the execution spec needed an explicit decision clarifying how this outcome should be represented.

**Decision**
- When active execution-spec implementation succeeds but the required implementation-log append fails, the result must surface clearly and deterministically as a partial-success outcome such as `completed_with_issues`.
- This outcome must not be reported as a clean successful completion.
- Draft specs still remain ineligible for implementation logging.

**Reasoning**
- A hard clean-failure result would overstate what went wrong by implying the implementation itself did not complete.
- A clean success would understate what went wrong by hiding the missing required log entry.
- A partial-success result preserves both truths: implementation completed, but required post-completion logging did not.

**Alternatives Considered**
- Treat log-write failure as a hard failure of the entire implementation.
- Ignore log-write failure and report a clean success.
- Keep log updates manual.

**Impact**
- The canonical execution-spec-system contract now explicitly allows `completed_with_issues`-style results for implementation-log append failures.
- `implement spec` remains truthful about execution outcomes without silently skipping required chronology updates.
- Execution specs and canonical feature docs can align on one clear partial-success model.

**Spec Reference**
- Goals
- Constraints
- Expected Behavior

### Decision: context-driven execution for execution-spec-system

Timestamp: <ISO-8601>

**Context**

- Foundry executed feature work for `execution-spec-system` from canonical context artifacts.

**Decision**

- Use the canonical spec, state, and decision ledger as the deterministic execution input.
- Update feature context after execution and revalidate before finishing.

**Reasoning**

- This keeps feature execution traceable to the canonical context contract.
- This preserves fail-closed behavior when repair is still required.

**Alternatives Considered**

- Execute from ad hoc prompts only.
- Skip post-execution context updates.
- Repair context only after implementation.

**Impact**

- Feature execution now leaves an explicit context trail.
- Later runs can resume from updated state instead of relying on chat history.

**Spec Reference**

- Expected Behavior
- Acceptance Criteria

### Decision: add exact feature-plus-id shorthand for active execution-spec resolution
Timestamp: 2026-04-16T09:46:00-04:00

**Context**

- The filename remains the canonical execution-spec identity, but `implement spec` still required either the full `<feature>/<id>-<slug>` ref or a globally unique filename shorthand.
- In practice, users often know the feature and canonical id before they need the slug text.
- Any shorthand still needed to preserve active-only resolution and deterministic failure semantics.

**Decision**

- Accept `implement spec <feature> <id>` as a deterministic lookup form for active execution specs.
- Match the canonical hierarchical id exactly within the provided feature and fail clearly for malformed ids, unknown features, unknown active ids, draft-only matches, or ambiguous duplicates.

**Reasoning**

- Feature-plus-id shorthand improves ergonomics without changing filename identity.
- Scoping the lookup to one feature preserves determinism and avoids guessing across unrelated execution specs.
- Explicit failure modes keep the active-versus-draft lifecycle contract intact.

**Alternatives Considered**

- Keep only the full feature-qualified ref.
- Rely only on unique global filename shorthand.
- Add fuzzy slug or partial-id matching.

**Impact**

- `implement spec` can now be invoked more quickly when the feature and id are already known.
- Canonical filename identity, active-only execution, and deterministic blocked results remain unchanged.
- The CLI help and reference docs now surface the shorthand explicitly.

**Spec Reference**

- Goals
- Constraints
- Expected Behavior
- Acceptance Criteria

### Decision: enforce implementation-log coverage during deterministic spec validation
Timestamp: 2026-04-17T12:15:00-04:00

**Context**

- Automatic implementation-log appends already covered newly completed active execution specs, but older promoted specs could still exist without a corresponding entry in `docs/specs/implementation-log.md`.
- `spec:validate` already served as the deterministic repository-wide execution-spec verification surface, yet it did not enforce this part of the lifecycle contract.
- Draft specs still needed to remain exempt because they are planning artifacts rather than completed active work.

**Decision**

- Extend `spec:validate` to require an exact matching implementation-log entry for every active canonical execution spec.
- Continue exempting specs under `docs/specs/<feature>/drafts/`.
- Treat missing coverage as a deterministic validation violation rather than a best-effort warning.

**Reasoning**

- The implementation log is part of the execution-spec lifecycle contract, so validation should enforce it just like filename, heading, and placement rules.
- Exact matching keeps the rule deterministic and machine-readable without adding fuzzy historical inference.
- Reusing `spec:validate` is narrower and clearer than inventing a separate audit command for the same repository state.

**Alternatives Considered**

- Keep implementation-log coverage as a documentation convention only.
- Enforce the rule only during `implement spec` and never re-check repository state later.
- Add fuzzy matching or infer implementation history from other repository signals.

**Impact**

- Missing implementation-log coverage for active specs is now detectable through the standard execution-spec verification path.
- Draft specs remain out of scope for implementation chronology.
- Repository validation can now surface incomplete execution-spec history deterministically instead of relying on memory.

**Spec Reference**

- Constraints
- Expected Behavior
- Acceptance Criteria
