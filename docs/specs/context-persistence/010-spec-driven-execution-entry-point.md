Implement Foundry Master Spec 35D7B — Spec-Driven Execution Entry Point

Objective

Implement deterministic, spec-driven execution as a secondary entry point into Foundry’s existing context-driven feature execution system.

This spec introduces:
- `foundry implement spec <spec-id>`
- deterministic resolution of execution specs under `docs/specs/<feature>/`
- strict enforcement of canonical feature authority
- reuse of the existing 35D7 execution pipeline
- PHPUnit coverage

Execution specs may guide bounded work.
They MUST NOT replace or override canonical feature truth.

Do NOT bypass or duplicate:
- context validation
- alignment checking
- verification semantics
- refusal-to-proceed rules
- repair / auto-repair behavior
- the existing `implement feature <feature>` execution pipeline

---

Preface

By this point, Foundry already supports context-driven feature execution using:
- canonical feature spec
- feature state
- decision ledger

This spec adds a workflow-oriented command for executing a discrete implementation spec or work order while preserving the rule that each feature has exactly one authoritative feature spec.

Execution specs remain secondary artifacts. They may drive a particular implementation pass, but they do not replace the canonical feature spec.

This spec MUST mirror the 35D7 execution model as closely as possible and MUST reuse its pipeline rather than reimplement it.

---

Goals

- add `foundry implement spec <spec-id>`
- support execution specs as structured work orders
- map execution specs deterministically to canonical feature context
- ensure execution specs cannot override feature authority
- reuse the same enforcement, repair, and execution pipeline as `implement feature <feature>`
- standardize execution spec storage under feature-scoped directories

---

Non-Goals

This spec does not:
- allow multiple canonical specs per feature
- make execution specs authoritative after implementation
- bypass doctor, alignment, or refusal rules
- introduce new feature context files
- replace `implement feature <feature>`
- duplicate execution logic already introduced by 35D7
- introduce planning behavior
- introduce new repair semantics

---

Execution Spec Model

Execution specs MUST live under:

`docs/specs/<feature>/<NNN-name>.md`

Examples:

- `docs/specs/blog/001-initial.md`
- `docs/specs/blog/002-add-comments.md`
- `docs/specs/blog/003-add-rss.md`

Flat forms such as:

- `docs/specs/blog-1.md`
- `docs/specs/blog-2.md`

MUST NOT be treated as canonical.

Execution specs are:
- implementation instructions
- bounded work orders
- optional secondary inputs to the execution system

Execution specs are NOT:
- authoritative feature truth
- replacements for `docs/features/<feature>.spec.md`

---

Command

Support:

`foundry implement spec <spec-id>`
`foundry implement spec <spec-id> --json`

Optional flags:

- `--repair`
- `--auto-repair`

`<spec-id>` MUST resolve deterministically to a single execution spec.

Examples of acceptable input:
- `blog/001-initial`
- `blog/002-add-comments`

If shorthand is supported, it MUST remain deterministic and documented.

Repair flag semantics MUST match 35D7 exactly.

---

Suggested Execution Spec Structure

If structure is validated, support:

# Execution Spec: <id>

## Feature
- context-persistence

## Purpose
- What this implementation step is for

## Scope
- What this spec will implement now

## Constraints
- Boundaries for this work order

## Requested Changes
- Concrete intended changes

The parser may initially be conservative and minimal.

---

Required Behavior

### 1. Resolve Execution Spec

- locate the execution spec by deterministic rules
- fail clearly if the spec cannot be found
- fail clearly if resolution is ambiguous
- reject non-canonical flat execution-spec paths
- parse enough structure to identify:
    - target feature
    - requested scope of work
    - explicit constraints

If the file path and `## Feature` section disagree:
- fail clearly
- return blocked result
- explain the conflict in `issues` / `required_actions`

### 2. Resolve Canonical Feature Context

For the target feature, load:
- `docs/features/<feature>.spec.md`
- `docs/features/<feature>.md`
- `docs/features/<feature>.decisions.md`

The canonical feature spec remains authoritative.

### 3. Enforce Canonical Authority

If execution-spec instructions conflict with the canonical feature spec:
- canonical feature spec wins
- the command MUST return a blocked result unless the conflict is repaired or resolved
- `required_actions` MUST explain the conflict deterministically

Execution specs may narrow or focus work.
They MUST NOT redefine intended feature behavior.

### 4. Reuse Existing Execution Pipeline

After resolution, reuse the existing 35D7 pipeline:
- context resolution
- doctor
- alignment
- enforcement gate
- repair / auto-repair behavior
- execution input assembly
- implementation
- state update
- decision logging
- revalidation

Do NOT create a parallel execution pipeline.

### 5. Record Execution-Spec Influence

If implementation proceeds using an execution spec:
- `actions_taken` and/or appended decision entries MUST reflect that the work was driven by that execution spec
- this MUST remain deterministic
- this MUST NOT change canonical feature authority

---

Execution Semantics

Execution MUST:
- treat the canonical feature spec as authoritative
- treat the execution spec as a bounded work-order overlay
- fail closed when context is invalid unless explicit repair succeeds
- reuse 35D7 repair semantics exactly

If `can_proceed = false`:

Default:
- STOP
- return blocked result
- include issues and `required_actions`

If `--repair`:
- use the same guided repair path as 35D7

If `--auto-repair`:
- use the same bounded deterministic repair path as 35D7

If repair fails:
- STOP
- return blocked result

---

JSON Output Contract

Use a shape aligned with `implement feature`:

{
"spec_id": "blog/002-add-comments",
"feature": "blog",
"status": "completed|blocked|repaired|completed_with_issues",
"can_proceed": true,
"requires_repair": false,
"repair_attempted": false,
"repair_successful": false,
"actions_taken": [],
"issues": [],
"required_actions": []
}

Requirements:
- deterministic ordering
- stable keys
- no timestamps
- consistent with 35D7 result semantics

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

Implementation Requirements

Create or update:

- `src/CLI/Commands/ImplementSpecCommand.php`
- `src/Context/ExecutionSpecResolver.php`
- `src/Context/ExecutionSpec.php`

You may add helpers if needed, but keep implementation minimal and composable.

If practical, prefer delegating into `ContextExecutionService` rather than creating a second orchestration layer.

---

Responsibilities

ImplementSpecCommand:
- resolve execution spec
- resolve target feature
- delegate into the existing context-driven execution pipeline
- pass through repair flags consistently
- return deterministic output

ExecutionSpecResolver:
- locate and load execution specs deterministically
- identify target feature
- reject ambiguity or invalid shape clearly
- enforce canonical path expectations

ExecutionSpec:
- represent parsed execution-spec data in a structured way

---

Constraints

- keep execution specs secondary to canonical feature truth
- reuse the existing `implement feature` pipeline rather than duplicating execution logic
- preserve the existing context rules
- fail clearly instead of silently resolving conflicts between execution specs and canonical feature truth
- preserve repair and refusal semantics established in 35D7 / 35D6

If conflict exists, fail clearly and explain required corrective actions.

---

Testing Requirements

Unit tests:
- execution spec resolves correctly
- target feature extraction works deterministically
- file-path / `## Feature` disagreement fails clearly
- conflict with canonical spec is detected
- invalid or ambiguous execution spec fails clearly
- non-canonical flat execution-spec path is rejected
- repair flags are passed through consistently to the reused execution pipeline

Integration tests:
- `implement spec <id>` succeeds when execution spec and feature context align
- conflicting execution spec is blocked
- execution reuses feature execution pipeline correctly
- `--repair` works consistently when supported by the reused pipeline
- `--auto-repair` works consistently when supported by the reused pipeline
- outputs are deterministic and stable

Regression tests:
- JSON output remains aligned with 35D7 semantics
- exit codes remain correct
- execution spec input never overrides canonical feature authority

---

Acceptance Criteria

This spec is complete only when:
1. Foundry can execute a discrete implementation spec
2. execution specs map deterministically to target features
3. canonical feature spec remains authoritative
4. conflicts are blocked clearly
5. implementation reuses existing context-driven execution infrastructure
6. canonical execution spec storage is feature-scoped and deterministic
7. repair / auto-repair behavior remains aligned with 35D7
8. outputs are deterministic and stable
9. all tests pass

---

Final Instruction

Implement spec-driven execution as a secondary entry point into the context-driven feature execution system.

Execution specs may guide bounded work.

They MUST NEVER replace canonical feature truth.

They MUST reuse the 35D7 execution pipeline rather than duplicating it.

Do not introduce a second execution policy path.
