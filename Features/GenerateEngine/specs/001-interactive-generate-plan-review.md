# Execution Spec: 001-interactive-generate-plan-review

## Feature
generate-engine

## Title
Interactive Generate — Plan Review, Approval, and Safe Execution

---

## Purpose

Introduce an interactive layer on top of `generate` that allows developers to:

- inspect planned changes before execution
- understand impact and risk
- approve or modify the plan
- execute safely with confidence

Transforms generate from automatic code modification into controlled, reviewable system evolution.

---

## Core Principle

No code should be modified blindly.

Every generation must be:

visible → understandable → optionally editable → explicitly approved

---

## Scope

### In Scope
- CLI-based interactive mode (`--interactive`, `-i`)
- Plan visualization (summary, detail, diff)
- Approval / rejection flow
- Minimal plan modification
- Risk classification
- Safe execution gating
- JSON + human output

### Out of Scope
- Web UI
- Full plan editing
- TUI panels
- Changes to GenerateEngine core logic

---

## CLI Contract

Command:

```
foundry generate "<intent>" --mode=new --interactive
foundry generate "<intent>" --mode=new -i
```

Default behavior remains unchanged.

---

## Execution Flow

1. Build `GenerationContextPacket`
2. Build `GenerationPlan`
3. Validate plan
4. Render summary
5. Render detail
6. Render diffs (required for file changes)
7. Await user decision
8. Apply (if approved)
9. Run verification
10. Output results

---

## Plan Rendering

### Summary (REQUIRED)

Must include:

- intent
- mode (new|modify|repair)
- targets
- total actions
- affected files
- risk level
- verification steps

### Detail (REQUIRED)

Per action:

- action type
- file path
- description
- dependencies affected
- related graph nodes

### Diff (REQUIRED FOR FILE CHANGES)

- unified diff
- additions/removals clearly marked
- MUST be shown before execution unless explicitly skipped with confirmation

---

## User Decisions

Supported actions:

### Approve
Execute full plan

### Reject
Abort with no changes

### Modify (V1 minimal)

- exclude action(s)
- exclude file(s)
- toggle risky actions

Rules:

- modified plan MUST be revalidated
- invalid plan MUST NOT execute

### Inspect

- expand action
- view related graph
- view explain output

---

## Safety Model

- No file mutations before approval
- Risky operations require additional confirmation
- Explicit warnings for:
  - deletions
  - schema changes
  - contract changes

---

## Risk Classification

Each plan MUST include:

- LOW: additive only
- MEDIUM: modifies existing
- HIGH: deletions / breaking / contracts

Risk must:

- appear in summary
- gate confirmations

---

## Integration

Interactive mode MUST reuse:

- `GenerationPlan`
- `PlanValidator`
- `VerificationRunner`

No duplicated logic.

---

## Output Contract

### Human

- summary
- detail
- diff
- prompts
- execution result
- verification result

### JSON

Must include:

- original plan
- modified plan (if any)
- user decisions
- executed actions
- verification results

---

## Determinism

- Plan generation MUST be deterministic
- Interactive layer MUST NOT introduce randomness
- Same input → same plan

---

## Testing

Add tests for:

- interactive flow
- rendering (summary/detail)
- diff generation
- modification logic
- risk classification
- approval gating
- no execution pre-approval

Coverage target ≥90%

---

## Acceptance Criteria

- `--interactive` flag works
- full plan visibility before execution
- diff available
- approve/reject supported
- minimal modification supported
- risk surfaced and enforced
- no changes without approval
- verification runs post execution
- no regression in non-interactive mode

---

## Done Means

Developers can:

- see what will happen
- understand why
- control execution

Generate becomes safe, transparent, and trustworthy.
