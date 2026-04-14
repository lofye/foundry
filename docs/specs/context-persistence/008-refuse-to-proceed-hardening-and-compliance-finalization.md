# Execution Spec: 008-refuse-to-proceed-hardening-and-compliance-finalization

Implement Foundry Master Spec 35D6 — Refuse-to-Proceed Hardening and Compliance Finalization

Objective

Harden the context anchoring system so it becomes a deterministic execution guardrail for LLM-assisted workflows.

This spec:
- finalizes refusal-to-proceed semantics
- standardizes execution readiness signaling
- hardens CLI outputs for machine interpretation
- ensures repair guidance is explicit and deterministic
- guarantees consistency between CLI behavior and AGENTS guidance

---

Scope

Update existing systems only:
- context doctor
- context check-alignment
- inspect context
- verify context
- AGENTS.md
- APP-AGENTS.md

Do NOT:
- introduce new commands
- introduce new document types
- duplicate logic across services

---

Core Principle

Execution readiness must be explicit, deterministic, and machine-readable.

There must be no ambiguity about whether meaningful implementation can proceed.

All enforcement must be visible through:
- command outputs (JSON + text)
- deterministic status fields
- AGENTS guidance

---

Execution Readiness Model

All relevant commands MUST expose:

- can_proceed (boolean)
- requires_repair (boolean)

These fields MUST be:
- deterministic
- derived only from existing status values
- consistent across all commands

---

Semantics

can_proceed = true when:
- doctor.status ∈ {ok, warning}
  AND
- alignment.status ∈ {ok, warning}

can_proceed = false when:
- doctor.status ∈ {repairable, non_compliant}
  OR
- alignment.status = mismatch

requires_repair = true when:
- can_proceed = false

No additional logic paths may be introduced.

---

Status Model Alignment

The system MUST treat statuses as follows:

doctor.status:
- ok
- warning
- repairable
- non_compliant

alignment.status:
- ok
- warning
- mismatch

verify.status:
- pass
- fail

Required mapping:

- verify.status = pass → can_proceed = true
- verify.status = fail → can_proceed = false

These mappings MUST NOT diverge across commands.

---

Refuse-to-Proceed Rules

A feature is BLOCKED (can_proceed = false) if ANY of the following are true:

Structural failures:
- missing spec file
- missing state file
- missing decision ledger
- missing required sections in any file
- invalid feature name

Compliance failures:
- doctor.status ∈ {repairable, non_compliant}

Alignment failures:
- alignment.status = mismatch
  AND no supporting:
    - state tracking
    - decision ledger entry

---

Allowed Actions While Blocked

When can_proceed = false, ONLY the following actions are allowed:

- create missing context files
- repair malformed files
- update feature state document
- append decision ledger entries
- update spec (with corresponding decision entry)

Meaningful implementation MUST NOT proceed until can_proceed = true.

---

Output Hardening

All context-related commands MUST:

- include can_proceed and requires_repair in JSON output
- produce deterministic output ordering
- use stable issue codes
- avoid timestamps or non-deterministic fields
- align text output with JSON output

Text output MUST NOT contradict JSON output.

---

Required Actions

All commands reporting issues MUST include:

- required_actions (array of strings)

Each action MUST be:
- specific
- concise
- deterministic
- directly derived from detected issues

Examples:

- "Create missing spec file"
- "Add missing 'Expected Behavior' section to spec"
- "Log divergence in decision ledger"
- "Update state to reflect current implementation"
- "Update spec to reflect intended behavior"

No vague or generic instructions.

---

Command Responsibilities

Ensure:

- context doctor exposes structural + compliance readiness
- context check-alignment exposes alignment readiness
- inspect context aggregates both views without altering logic
- verify context remains the canonical pass/fail gate

All commands MUST expose consistent execution readiness signals.

---

Documentation Alignment

AGENTS.md and APP-AGENTS.md MUST:

- reference can_proceed semantics
- treat verify context as the primary proceed/fail gate
- enforce refusal-to-proceed rules
- describe repair-first workflow

Documentation MUST NOT:
- contradict CLI behavior
- describe non-existent enforcement
- introduce alternative decision logic

---

Files to Update

Likely targets include:
- src/Context/ContextDoctorService.php
- src/Context/AlignmentChecker.php
- src/Context/ContextInspectionService.php
- src/CLI/Commands/ContextDoctorCommand.php
- src/CLI/Commands/ContextCheckAlignmentCommand.php
- src/CLI/Commands/InspectContextCommand.php
- src/CLI/Commands/VerifyContextCommand.php
- AGENTS.md
- APP-AGENTS.md

Adjust to match repository structure.

---

Tests (PHPUnit)

Unit tests:
- can_proceed derived correctly from doctor + alignment
- requires_repair derived correctly
- verify.status aligns with can_proceed
- missing files produce can_proceed = false
- mismatch without decision produces can_proceed = false

Integration tests:
- missing spec → can_proceed = false
- missing state → can_proceed = false
- missing decisions → can_proceed = false
- malformed files → can_proceed = false
- mismatch without decision → can_proceed = false
- repaired feature → can_proceed = true

Regression tests:
- JSON and text outputs remain aligned
- issue codes remain stable
- required_actions remain deterministic

---

Acceptance Criteria

The work is complete only when:

- all context commands expose can_proceed and requires_repair
- refusal conditions are explicit and test-covered
- verify context fully aligns with can_proceed semantics
- required_actions are deterministic and actionable
- documentation matches actual CLI behavior
- no ambiguity exists about execution readiness
- all tests pass

---

Final Instruction

Complete the context anchoring system as a deterministic enforcement layer.

The system must work because:

- artifacts are explicit
- outputs are deterministic
- execution readiness is machine-readable
- enforcement is visible
- behavior is consistent
