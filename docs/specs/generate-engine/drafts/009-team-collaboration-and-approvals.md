# Execution Spec: 009-team-collaboration-and-approvals

## Purpose

Introduce deterministic, repository-local team collaboration and approval controls for generate plans, enabling multi-user review, explicit approval gates, and auditable decision history before execution.

---

## Feature

`generate-engine`

---

## Goals

1. Support approval-gated generation workflows.
2. Attribute actions (review, approve, reject) to users.
3. Persist a complete, append-only audit trail.
4. Integrate cleanly with existing generate, workflow, and policy systems.
5. Preserve determinism and non-interactive reproducibility.

---

## Non-Goals

- Do not implement authentication or identity providers.
- Do not introduce networked or remote approval systems.
- Do not add real-time collaboration or locking.
- Do not allow implicit approvals.
- Do not bypass policy or validation systems.

---

## Core Concepts

### Plan Approval State

Each GenerationPlan or WorkflowPlan MAY require approval before execution.

States:

- `pending`
- `approved`
- `rejected`

---

### Reviewer Action

A recorded action performed by a user.

Types:

- `approve`
- `reject`
- `comment` (optional, non-blocking)

---

### Approval Record

An append-only record capturing all reviewer actions for a plan.

---

## Approval Requirement Model

Plans MAY declare approval requirements:

```json
{
  "approval": {
    "required": true,
    "min_approvals": 1
  }
}
```

Rules:

- If `required=false` or absent → plan executes normally
- If `required=true` → execution MUST be blocked until approved
- `min_approvals` MUST be >= 1

---

## Approval Record Shape

Approval records MUST be persisted as deterministic JSON.

```json
{
  "schema": "foundry.generate.approval_record.v1",
  "plan_id": "string",
  "status": "pending|approved|rejected",
  "required": true,
  "min_approvals": 1,
  "approvals": [
    {
      "user": "string",
      "action": "approve|reject",
      "timestamp": "string|null",
      "comment": "string|null"
    }
  ]
}
```

---

## Determinism Rules

- Approval entries MUST be stored in append order.
- No mutation or deletion allowed.
- Timestamps MUST follow existing project conventions or be `null` in V1.
- User identifiers MUST be explicit strings (no environment lookup).

---

## Execution Rules

### Before Execution

If approval is required:

- Plan MUST NOT execute if status is `pending`
- Plan MUST NOT execute if status is `rejected`
- Plan MAY execute only if:
  - `status=approved`
  - approvals >= `min_approvals`

### During Execution

- Approval state MUST NOT change mid-execution

---

## CLI Behavior

### Request Approval

```bash
foundry generate --approve --user=<user>
```

### Reject Plan

```bash
foundry generate --reject --user=<user>
```

### Behavior

- Loads plan record
- Appends approval action
- Recomputes approval status

---

## Approval Status Resolution

Rules:

- If any `reject` exists → status = `rejected`
- Else if approvals >= min_approvals → status = `approved`
- Else → status = `pending`

---

## Inspect Surface Requirements

Inspect MUST expose:

- plan_id
- approval status
- required flag
- min approvals
- list of reviewer actions (ordered)

Output MUST be deterministic.

---

## Verify Surface Requirements

Verify MUST fail when:

- approval schema is invalid
- required approval missing for execution attempt
- approval count < required minimum
- approval record mutated (non-append-only)
- unknown approval state

---

## Compatibility Requirements

- Existing generate behavior MUST remain unchanged when approval is not required
- Approval system MUST be additive
- Existing plans MUST remain valid

---

## Tests Required

1. Plan with no approval executes normally
2. Plan requiring approval blocks execution
3. Approval action transitions to approved
4. Reject action blocks execution
5. Multiple approvals satisfy threshold
6. Inspect output includes approval state
7. Verify detects invalid approval records
8. Append-only audit trail enforced

---

## Acceptance Criteria

- Plans can require approval before execution
- Approval decisions are attributed to users
- Approval history is persisted and immutable
- Execution is blocked until requirements are met
- Inspect and verify surfaces support approvals
- All tests pass
- Strict coverage gate exits 0

---

## Done Means

Teams can safely collaborate on generation workflows with explicit approval gates, full auditability, and deterministic execution guarantees.
