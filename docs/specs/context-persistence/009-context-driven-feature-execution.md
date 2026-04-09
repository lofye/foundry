Implement Foundry Master Spec 35D7 — Context-Driven Feature Execution

Objective

Implement deterministic, context-driven feature execution in Foundry.

This spec introduces:
- `foundry implement feature <feature>`
- strict enforcement using the context system established in 35D1–35D6
- deterministic execution input derived from canonical context artifacts
- optional guided repair and auto-repair flows
- post-execution context updates
- post-execution revalidation
- CI/CD-compatible behavior
- PHPUnit coverage

Execution MUST consume and respect the existing context system.

Do NOT bypass or duplicate:
- context validation
- alignment checking
- verification semantics
- refusal-to-proceed rules

---

Command

Support:

foundry implement feature <feature>
foundry implement feature <feature> --json

Optional flags:

--repair
--auto-repair

Semantics:

Default:
- fail closed if context is invalid

`--repair`:
- attempt guided repair using `required_actions`
- proceed only if repair succeeds and revalidation passes

`--auto-repair`:
- attempt deterministic automatic repair before execution
- repairs MUST remain safe, bounded, and non-speculative
- proceed only if repair succeeds and revalidation passes

If neither repair mode succeeds, execution MUST stop.

---

Canonical Context Inputs

For feature `<feature>`, execution MUST consume:

- `docs/features/<feature>.spec.md` → authoritative intent
- `docs/features/<feature>.md` → current implementation state
- `docs/features/<feature>.decisions.md` → reasoning history and constraints

Optional:
- `docs/specs/<feature>/` may be read if helpful

Constraints:

- the canonical feature spec is authoritative
- the feature state reflects current known reality
- the decision ledger constrains execution
- execution specs MUST NOT override the canonical feature spec

---

Execution Pipeline

Implement this exact sequence.

### 1. Resolve Context
- validate feature name
- resolve canonical paths
- load spec, state, and decisions

### 2. Validate Context
Reuse existing services to derive:
- doctor status
- alignment status
- can_proceed
- requires_repair
- required_actions

Do NOT reimplement validation logic.

### 3. Enforcement Gate

If `can_proceed = false`:

Default:
- STOP
- return blocked result
- include issues and `required_actions`

If `--repair` is provided:
- perform guided repair using `required_actions`
- update context artifacts
- re-run validation

If `--auto-repair` is provided:
- perform deterministic automatic repair
- MUST NOT introduce speculative changes
- MUST only apply safe, bounded operations
- update context artifacts
- re-run validation

If `can_proceed` remains false after repair attempts:
- STOP
- return blocked result

Execution MUST fail closed unless repaired successfully.

### 4. Build Execution Input

Construct deterministic execution input from:
- spec → primary intent
- state → current progress and gaps
- decisions → constraints and prior reasoning

Input MUST be:
- explicit
- structured
- reproducible

Do NOT rely on hidden prompt assembly or undocumented inference.

### 5. Execute Implementation

Implementation MUST:
- align with the canonical feature spec
- respect the decision ledger
- modify only necessary system parts
- remain deterministic

Reuse existing Foundry execution or generation systems where practical.

This spec does NOT define `implement spec <id>` and does NOT require execution-spec-driven entry points.

### 6. Post-Execution Context Update

After implementation:

Update the feature state document to reflect:
- what was implemented
- what remains
- what is in progress
- next steps

Append decision entries when:
- architectural choices are made
- technical tradeoffs are made
- deviations or clarifications occur

Decision entries MUST:
- preserve reasoning
- remain append-only
- reference spec sections when applicable

### 7. Revalidation

Re-run:
- `context doctor`
- `context check-alignment`

Execution MUST NOT leave the feature in a worse context state.

If revalidation fails:
- return `completed_with_issues`
- include issues and `required_actions`
- do NOT silently report success

---

Repair Execution Model

Repair actions MUST be derived ONLY from `required_actions`.

Allowed repair operations:

- create missing files
- add missing required sections
- update the feature state document
- append decision ledger entries
- update the feature spec WITH corresponding decision logging

Repair MUST:
- be deterministic
- be traceable
- avoid modifying unrelated files
- preserve user-authored intent

Auto-repair MUST NOT:
- invent new requirements
- override existing decisions
- silently alter behavior
- introduce speculative changes

---

JSON Output Contract

Stable top-level shape:

{
"feature": "blog",
"status": "completed|blocked|repaired|completed_with_issues",
"can_proceed": true,
"requires_repair": false,
"repair_attempted": true,
"repair_successful": true,
"actions_taken": [],
"issues": [],
"required_actions": []
}

Requirements:

- deterministic ordering
- stable keys
- no timestamps
- consistent with existing context command conventions

---

Exit Codes (CI/CD Compatibility)

Exit code 0:
- `status = completed`
- `status = repaired`

Exit code 1:
- `status = blocked`
- `status = completed_with_issues`

The command MUST be usable in CI pipelines and scripted workflows.

---

Responsibilities

ImplementFeatureCommand:
- orchestrate the execution pipeline
- enforce preconditions
- handle repair flows
- produce deterministic text and JSON output

ContextExecutionService:
- assemble execution input
- reuse validation services
- coordinate implementation, repair, updates, and revalidation
- avoid duplicating logic

ExecutionResult:
Represents:
- status
- can_proceed
- requires_repair
- repair_attempted
- repair_successful
- actions_taken
- issues
- required_actions

---

Constraints

Do NOT implement:
- `implement spec <id>`
- new context artifact types
- hidden execution paths
- prompt-only execution without context
- duplicate validation logic
- model-specific integrations

Execution MUST fail closed unless explicitly repaired.

---

Tests (PHPUnit)

Unit tests:
- execution is blocked when `can_proceed = false`
- execution proceeds when context is valid
- guided repair resolves simple issues deterministically
- auto-repair performs safe deterministic fixes
- execution input is deterministic
- state updates correctly
- decision ledger updates correctly
- result shape is stable

Integration tests:
- `implement feature <feature>` succeeds with valid context
- blocked feature returns correct blocked result
- `--repair` enables recovery when repairable
- `--auto-repair` enables safe recovery when repairable
- state is updated after execution
- decisions are appended when needed
- revalidation passes after successful execution
- failed revalidation returns `completed_with_issues`

Regression tests:
- JSON output remains stable
- exit codes remain correct
- repair behavior remains deterministic

---

Acceptance Criteria

The work is complete only when:
- Foundry executes features using canonical context artifacts
- execution is blocked when context is invalid
- repair flows work deterministically
- the canonical feature spec is treated as authoritative input
- state and decisions are updated after execution
- revalidation preserves system integrity
- output is deterministic and CI-friendly
- all tests pass

---

Final Instruction

Implement context-driven feature execution as a strict extension of the context system.

Execution MUST:
- respect enforcement
- consume canonical context artifacts
- repair context only when explicitly allowed
- update context artifacts
- revalidate after execution

Do not weaken existing guardrails.
Do not introduce hidden behavior.
Do not introduce a second execution path.