Implement Foundry Master Spec 35D5 — AGENTS, APP-AGENTS, Scaffold, and Onboarding Integration

Objective

Ship the context anchoring system into:
- framework contributor workflow
- generated application workflow
- onboarding documentation

This spec connects:
- context validation (35D2)
- alignment checking (35D3 / 35D3A)
- inspection + verification (35D4)

to real-world usage via AGENTS and scaffolded apps.

The guidance introduced here must reflect the actual implemented system and must use `verify context` as the primary machine-readable proceed / fail gate.

---

Scope

Update:
- AGENTS.md (framework)
- APP-AGENTS.md (scaffold source)
- APP-README.md and README.md (only where necessary)
- app scaffolding and promotion behavior

Do NOT introduce new commands or change existing JSON contracts.

---

Core Requirement

Documentation must reflect the actual implemented system.

Do NOT describe aspirational behavior.

All workflow guidance must align with:
- context doctor
- context check-alignment
- inspect context
- verify context

---

Enforcement Model

AGENTS guidance must explicitly enforce the following rules.

## Rule — Mandatory Context Usage

Before performing meaningful work:
- read:
  - feature spec
  - feature state document
  - decision ledger
- use Foundry context tooling when available:
  - context doctor
  - context check-alignment
  - inspect context
  - verify context

Do NOT rely on chat history as authoritative context.

---

## Rule — Primary Execution Gate

`verify context` is the primary machine-readable gate for whether meaningful work may proceed.

Guidance must reflect:
- if `verify context` passes, meaningful work may proceed
- if `verify context` fails, meaningful work must not proceed

When `verify context` is not immediately used in the workflow, equivalent conclusions must still align with:
- doctor status
- alignment status

without contradicting the implemented command behavior.

---

## Rule — Refuse to Proceed

Meaningful work MUST NOT proceed if:
- verify context fails
- doctor status is repairable or non_compliant
- alignment status is mismatch

In this case, the agent MUST:
1. STOP
2. explain the non-compliance
3. list required corrective actions
4. perform or propose repair as the next step

---

## Rule — Allowed Recovery Actions

When context is invalid, the agent MAY:
- run context init
- repair missing or malformed context files
- update the feature state document
- append decision ledger entries
- update the feature spec with corresponding decision logging

Repair is the only valid next step before implementation.

---

Required Guidance Content

Framework AGENTS.md

Must include:
- purpose of the context anchoring system
- source-of-truth boundaries:
  - spec → intent
  - state → current implementation state
  - decisions → reasoning history
  - code/tests → implementation and runtime behavior
- canonical file structure
- feature naming rules
- spec/state/decision roles
- read-before-acting rule
- state sync rule
- decision logging rule
- spec-vs-state mismatch rule
- refusal-to-proceed rules
- repair-first workflow guidance
- verify context as the primary proceed / fail gate

Must be:
- deterministic
- model-agnostic
- consistent with implemented CLI behavior

---

APP-AGENTS.md

Must:
- preserve the same operational rules as framework AGENTS
- be slightly simpler and less framework-internal
- remain equally strict about:
  - canonical file model
  - refusal-to-proceed semantics
  - repair-first behavior
  - verify context as the proceed / fail gate

Do NOT weaken enforcement semantics.

---

README / APP-README Updates

Update only where necessary to prevent stale guidance.

Do NOT duplicate AGENTS content.

Prefer:
- short references to the context system
- references to AGENTS behavior
- alignment with actual CLI commands
- concise workflow pointers rather than repeating all rules

---

Scaffold Requirements

Ensure generated apps receive:
- AGENTS.md from APP-AGENTS.md
- README.md from APP-README.md (if applicable)

Requirements:
- no drift between source templates and promoted files
- deterministic output
- consistent wording

If scaffold promotion logic exists:
- update canonical sources, not generated outputs
- keep promotion logic, templates, and tests aligned

---

Files to Update

Likely targets include:
- AGENTS.md
- APP-AGENTS.md
- APP-README.md
- README.md
- src/CLI/Commands/InitAppCommand.php
- scaffold-related tests

Adjust exact paths to match the repository.

---

Responsibilities

- keep framework and app guidance aligned
- ensure scaffolded apps receive correct guidance
- ensure docs match actual CLI behavior
- ensure no contradictions across onboarding surfaces
- ensure verify context is represented as the primary machine-readable proceed / fail signal

---

Tests (PHPUnit)

Integration tests
- generated app contains updated AGENTS.md
- generated app contains updated README.md if applicable
- scaffold promotion produces correct files
- no drift between APP-AGENTS.md and generated AGENTS.md

Regression tests
- changes to onboarding docs do not create inconsistencies
- init-app output remains deterministic
- documented workflow does not contradict implemented context command behavior

---

Acceptance Criteria

The work is complete only when:
- framework AGENTS.md contains finalized context anchoring guidance
- APP-AGENTS.md contains aligned application-facing guidance
- scaffolded apps receive correct guidance automatically
- scaffold tests verify promoted output
- onboarding docs are not stale
- documentation reflects verify context as the primary proceed / fail gate
- all tests pass

---

Final Instruction

Ship the context system as part of the default Foundry experience.

Guidance must:
- match real system behavior
- enforce correct usage
- prevent invalid workflows

Do not weaken or dilute enforcement rules.
Do not describe behavior that the CLI does not actually implement.