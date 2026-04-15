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
