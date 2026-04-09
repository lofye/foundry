### Decision: separate intent, state, and reasoning into three files
Timestamp: 2026-04-07T12:00:00-04:00

**Context**
- Chat history is ephemeral and does not reliably preserve feature intent across sessions.

**Decision**
- Use three canonical feature files: spec, state, and decision ledger.

**Reasoning**
- This keeps intent, current reality, and historical reasoning distinct and easier to validate.

**Alternatives Considered**
- Keep everything in one file.
- Use only execution specs.
- Rely on chat history and code only.

**Impact**
- The system is more structured and easier to resume, but requires disciplined updates.

**Spec Reference**
- Purpose
- Goals
- Constraints

### Decision: introduce CLI surface for context initialization and validation
Timestamp: 2026-04-07T12:30:00-04:00

**Context**
- Context artifacts exist but must currently be created and validated manually.
- This limits usability and prevents consistent enforcement.

**Decision**
- Introduce CLI commands to initialize and validate feature context:
    - context init
    - context doctor

**Reasoning**
- A CLI surface makes the system usable for both humans and LLMs.
- Deterministic outputs allow future automation and enforcement layers.

**Alternatives Considered**
- Keep context creation manual.
- Delay CLI until later phases.
- Use non-deterministic or conversational tooling.

**Impact**
- Enables consistent creation and validation of feature context.
- Forms the foundation for later enforcement and execution phases.

**Spec Reference**
- Goals
- Expected Behavior
- Acceptance Criteria

### Decision: introduce deterministic spec-state alignment checking
Timestamp: 2026-04-07T13:00:00-04:00

**Context**
- Structural validation exists but cannot detect divergence between spec and actual feature state.
- Without alignment checking, the system cannot enforce consistency or detect drift.

**Decision**
- Introduce a deterministic alignment engine to compare feature spec, state, and decisions.
- Provide a CLI command:
  - context check-alignment

**Reasoning**
- Alignment is required before execution enforcement can be trusted.
- Deterministic heuristics allow explainable and testable mismatch detection.
- Decision-backed divergence must be treated differently from unexplained divergence.

**Alternatives Considered**
- Rely on manual review only.
- Introduce LLM-based semantic alignment immediately.
- Delay alignment until later phases.

**Impact**
- Enables early detection of drift between intent and implementation.
- Provides the first semantic enforcement layer in the system.
- Establishes the foundation for future inspect/verify and execution phases.

**Spec Reference**
- Goals
- Expected Behavior
- Constraints
- Acceptance Criteria

### Decision: add deterministic spec-state alignment checking
Timestamp: 2026-04-07T13:30:00-04:00

**Context**
- Structural validation alone cannot detect drift between intended behavior and recorded feature state.
- The context system needs a deterministic semantic layer before inspect, verify, and enforcement can be trusted.

**Decision**
- Add a conservative alignment engine and CLI command:
  - context check-alignment

**Reasoning**
- Alignment checking is necessary to detect untracked requirements, unsupported state claims, and unexplained divergence.
- Deterministic heuristics are easier to test, explain, and trust than aggressive semantic inference.
- Decision-backed divergence must be handled differently from unexplained divergence.

**Alternatives Considered**
- Rely on manual review only.
- Delay alignment until later phases.
- Use LLM-based semantic matching immediately.

**Impact**
- Foundry can now detect meaningful mismatches between spec and state.
- The context system now has a semantic validation layer in addition to structural validation.
- This enables later inspect, verify, and refusal-to-proceed phases.

**Spec Reference**
- Goals
- Constraints
- Expected Behavior
- Acceptance Criteria

### Decision: compose doctor and alignment into inspect and verify workflows
Timestamp: 2026-04-07T14:00:00-04:00

**Context**
- Structural validation and semantic alignment existed separately, but there was no unified inspection or verification surface.
- Later enforcement phases need a deterministic proceed/fail signal rather than ad hoc interpretation of multiple commands.

**Decision**
- Add:
  - inspect context
  - verify context
- Reuse doctor and alignment services rather than reimplementing either path.

**Reasoning**
- A single inspection surface improves visibility.
- A deterministic verification surface provides a clean machine-readable gate for future enforcement.
- Reuse preserves consistency and reduces duplicate logic.

**Alternatives Considered**
- Keep doctor and alignment as separate manual checks only.
- Reimplement validation and alignment inside inspect and verify.
- Delay verify semantics until later phases.

**Impact**
- Foundry now has a unified context inspection workflow.
- Foundry now has deterministic pass/fail semantics for feature context.
- This creates the clean proceed/fail boundary needed for 35D5.

**Spec Reference**
- Goals
- Expected Behavior
- Acceptance Criteria

### Decision: promote context workflow guidance into framework and scaffold onboarding
Timestamp: 2026-04-07T14:30:00-04:00

**Context**
- Context commands were implemented, but framework and scaffold guidance still described the workflow as not yet available.
- This created drift between the documented onboarding path and the actual CLI behavior.

**Decision**
- Update framework and scaffold onboarding guidance to describe the implemented context workflow.
- Use verify context as the primary machine-readable proceed / fail gate.

**Reasoning**
- Onboarding docs must reflect real command behavior once the contract exists.
- A single documented gate reduces ambiguity for both humans and automation.

**Alternatives Considered**
- Leave bootstrap-only wording in place.
- Document different proceed / fail gates across framework and app scaffolds.
- Delay onboarding updates until later phases.

**Impact**
- Framework and scaffold guidance now match the implemented context system.
- New apps inherit the same deterministic context workflow expectations as the framework repo.

**Spec Reference**
- Goals
- Expected Behavior
- Acceptance Criteria

### Decision: expose explicit readiness signals for context enforcement
Timestamp: 2026-04-07T15:00:00-04:00

**Context**
- Doctor, alignment, inspect, and verify each reported context health, but later execution phases need a single explicit readiness interpretation.
- The workflow needed a deterministic answer to whether meaningful implementation may proceed.

**Decision**
- Expose can_proceed and requires_repair consistently across context doctor, context check-alignment, inspect context, and verify context.
- Keep refusal-to-proceed semantics aligned across CLI output and onboarding guidance.

**Reasoning**
- A shared readiness model keeps inspection, verification, and later enforcement layers consistent.
- Explicit readiness signals reduce hidden interpretation by users and tooling.

**Alternatives Considered**
- Infer readiness separately in each command.
- Keep pass / fail semantics only in verify context.
- Delay readiness hardening until feature execution exists.

**Impact**
- Later execution commands can reuse the existing context readiness contract without inventing a second policy path.
- Users and automation now receive the same deterministic readiness signals from every context surface.

**Spec Reference**
- Constraints
- Expected Behavior
- Acceptance Criteria

### Decision: execute feature work from canonical context with bounded repair
Timestamp: 2026-04-07T15:30:00-04:00

**Context**
- Foundry could inspect and verify feature context, but it still lacked a public execution path that consumed canonical context artifacts directly.
- Later execution needed to remain fail-closed, deterministic, and repair-first.

**Decision**
- Add implement feature as a strict extension of the context system.
- Reuse context validation and readiness signals as the execution gate.
- Allow only bounded, deterministic repair operations before execution when repair is explicitly requested.

**Reasoning**
- Canonical context must remain authoritative once feature execution begins.
- Reusing doctor, alignment, inspect, and verify preserves consistency and avoids a second execution policy path.
- Bounded repair keeps execution deterministic while still unblocking simple context issues.

**Alternatives Considered**
- Execute from ad hoc prompts only.
- Bypass context enforcement during implementation.
- Allow speculative context rewriting during auto-repair.

**Impact**
- Foundry can now execute feature work from canonical context artifacts.
- Feature execution updates state and decisions, then revalidates context before finishing.
- CI and scripted workflows can consume deterministic blocked / repaired / completed results from the same context contract.

**Spec Reference**
- Constraints
- Expected Behavior
- Acceptance Criteria

### Decision: execute feature work from canonical context for context-persistence
Timestamp: 2026-04-07T15:30:00-04:00

**Context**
- Foundry could validate, align, inspect, and verify feature context, but it still lacked a public execution path that consumed canonical context artifacts directly.
- Feature execution needed to remain fail-closed, deterministic, and repair-first.

**Decision**
- Add `implement feature` as a strict extension of the context system.
- Use the canonical spec, state, and decision ledger as the deterministic execution input.
- Update feature context after execution and revalidate before finishing.

**Reasoning**
- This keeps feature execution traceable to the canonical context contract.
- This preserves fail-closed behavior when repair is still required.
- This avoids introducing a second execution policy path separate from doctor, alignment, inspect, and verify.

**Alternatives Considered**
- Execute from ad hoc prompts only.
- Skip post-execution context updates.
- Repair context only after implementation.

**Impact**
- Foundry can now execute feature work from canonical context artifacts.
- Feature execution now leaves an explicit context trail.
- Later runs can resume from updated state instead of relying on chat history.

**Spec Reference**
- Expected Behavior
- Acceptance Criteria
