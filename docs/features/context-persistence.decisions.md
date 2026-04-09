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