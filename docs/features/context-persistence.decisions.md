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

