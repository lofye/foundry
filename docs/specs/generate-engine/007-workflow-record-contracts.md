# 007-workflow-record-contracts

## Purpose

Define the canonical persisted record contract for multi-step generate workflows and specify how workflow records relate to step records across history, inspect, and verify surfaces.

This tightens the V1 multi-step generate implementation by making workflow persistence and visibility explicit, deterministic, and testable.

---

## Feature

`generate-engine`

---

## Scope

This spec applies to:

- grouped workflow plan records produced by `foundry generate --workflow=<file>`
- per-step generate plan records produced during workflow execution
- persisted history artifacts
- inspect surfaces that expose generate history or workflow execution details
- verify surfaces that validate persisted generate records

---

## Goals

1. Define a canonical workflow record shape.
2. Define the relationship between one workflow record and its step records.
3. Ensure workflow and step records can be inspected deterministically.
4. Ensure verification can detect malformed, orphaned, incomplete, or inconsistent workflow records.
5. Preserve compatibility with existing per-step generate plan records.

---

## Non-Goals

- Do not change workflow execution semantics.
- Do not add branching, parallel execution, retries, or resumable workflows.
- Do not redefine `GenerationPlan`.
- Do not remove existing per-step generate records.
- Do not introduce database-backed persistence.
- Do not implement workflow `--explain` or workflow `--git-commit` semantics.

---

## Core Principle

A workflow record is the parent execution artifact.

Step records remain normal generate records, but when produced inside a workflow they MUST explicitly reference their parent workflow and their position within that workflow.

---

## Canonical Workflow Record Shape

A workflow record MUST be persisted as deterministic JSON.

The canonical shape MUST include:

```json
{
  "schema": "foundry.generate.workflow_record.v1",
  "workflow_id": "string",
  "source": {
    "type": "repository_file",
    "path": "string"
  },
  "status": "completed|failed",
  "started_at": "string|null",
  "completed_at": "string|null",
  "steps": [
    {
      "step_id": "string",
      "index": 0,
      "status": "completed|failed|skipped",
      "record_id": "string|null",
      "dependencies": ["string"],
      "failure": null
    }
  ],
  "shared_context": {},
  "result": {
    "completed_steps": 0,
    "failed_step": "string|null",
    "skipped_steps": 0
  },
  "rollback_guidance": ["string"]
}
```

### Determinism Rules

- Object keys MUST be emitted in stable order.
- Step entries MUST be ordered by execution index.
- Dependency lists MUST preserve normalized deterministic order.
- `workflow_id` MUST be deterministic for the workflow run input.
- Paths MUST be repository-relative where possible.
- No random IDs are allowed.
- Timestamps, if present, MUST follow the existing project convention for persisted history artifacts.
- If stable timestamps are not already part of generate history contracts, `started_at` and `completed_at` MUST be `null` in V1.

---

## Workflow Record Fields

### `schema`

MUST equal `foundry.generate.workflow_record.v1`.

### `workflow_id`

A deterministic identifier for the workflow record.

It MUST be stable for identical workflow inputs and execution configuration.

### `source`

Identifies the workflow definition source.

Required fields:

- `type`
- `path`

V1 only requires `repository_file`.

### `status`

Allowed values:

- `completed`
- `failed`

A workflow MUST be `failed` if any executed step fails.

### `steps`

An ordered summary of every declared workflow step.

Each step summary MUST include:

- `step_id`
- `index`
- `status`
- `record_id`
- `dependencies`
- `failure`

Rules:

- Completed steps MUST reference their generated per-step record ID.
- Failed steps MUST reference their per-step record ID when one exists.
- Skipped steps MUST use `record_id: null`.
- Steps not reached because of fail-fast behavior MUST be marked `skipped`.

### `shared_context`

The final deterministic shared workflow context after execution stops.

Rules:

- Must be serializable.
- Must not include hidden runtime objects.
- Must reflect context only up to the point execution stopped.

### `result`

A compact machine-readable summary.

Required fields:

- `completed_steps`
- `failed_step`
- `skipped_steps`

### `rollback_guidance`

A deterministic array of human-readable guidance strings.

Rules:

- Must be present for failed workflows.
- May be empty for completed workflows.
- Must not contain stack traces.

---

## Step Record Relationship

Existing per-step generate records MUST remain valid standalone generate records.

When a generate record is produced as part of a workflow, it MUST include workflow linkage metadata.

The metadata MUST include:

```json
{
  "workflow": {
    "workflow_id": "string",
    "step_id": "string",
    "step_index": 0,
    "is_workflow_step": true
  }
}
```

Rules:

- `workflow.workflow_id` MUST match the parent workflow record.
- `workflow.step_id` MUST match the workflow step definition.
- `workflow.step_index` MUST match the step’s index in the workflow record.
- Non-workflow generate records MUST omit this block or set no workflow linkage.
- A step record MUST NOT point to more than one workflow.

---

## Inspect Surface Requirements

Inspect surfaces that expose generate history MUST represent workflow records explicitly.

Required behavior:

- distinguish standalone generate records, workflow records, and workflow step records
- expose workflow ID, source path, status, ordered steps, per-step statuses, per-step record IDs, failed step, and rollback guidance
- preserve parent-child structure in JSON output
- show workflow hierarchy clearly in text output
- avoid environment-specific absolute paths unless already required by existing contracts

Example text output:

```text
workflow <workflow_id> failed
  step 0 <step_id> completed <record_id>
  step 1 <step_id> failed <record_id>
  step 2 <step_id> skipped
```

---

## Verify Surface Requirements

Verify surfaces MUST validate workflow record integrity.

Verification MUST fail when:

- a workflow record has an unknown schema
- a workflow record is missing required fields
- step indexes are missing, duplicated, or out of order
- a completed step has no `record_id`
- a skipped step has a `record_id`
- a step record references a missing workflow record
- a step record references a workflow ID but no matching step exists
- a step record’s `step_id` or `step_index` disagrees with the parent workflow record
- workflow status is `completed` while any step is `failed` or `skipped`
- workflow status is `failed` but no failed step is recorded
- rollback guidance is missing for a failed workflow
- shared context is not serializable

Verification JSON MUST include deterministic issue objects with:

```json
{
  "code": "string",
  "severity": "error|warning",
  "record_id": "string|null",
  "workflow_id": "string|null",
  "step_id": "string|null",
  "message": "string"
}
```

Exit behavior:

- pass when all workflow records are valid
- exit non-zero when workflow record integrity errors are found
- allow warnings only if existing verify conventions allow warnings

---

## Compatibility Requirements

- Existing non-workflow generate records MUST remain valid.
- Existing generate history inspection MUST not break.
- Existing plan-record persistence tests MUST continue to pass.
- Workflow linkage MUST be additive for step records.
- No existing JSON field may be repurposed incompatibly.

---

## Tests Required

Add or update tests covering:

1. Canonical workflow record persistence.
2. Step record workflow linkage metadata.
3. Completed workflow with multiple completed steps.
4. Failed workflow with completed, failed, and skipped steps.
5. Inspect JSON output for workflow hierarchy.
6. Inspect text output for workflow hierarchy.
7. Verify pass for valid workflow records.
8. Verify failure for orphaned step records.
9. Verify failure for mismatched step IDs or indexes.
10. Verify failure for invalid workflow status/step status combinations.
11. Backward compatibility for standalone generate records.

---

## Acceptance Criteria

- Workflow records have one canonical documented V1 shape.
- Workflow step records explicitly link to their parent workflow.
- Inspect surfaces expose workflow hierarchy clearly and deterministically.
- Verify surfaces detect malformed workflow/step relationships.
- Existing standalone generate records remain valid.
- Focused and full test suites pass.
- Strict coverage gate exits 0 and remains at or above 90% line coverage.

---

## Done Means

Generate workflow persistence is no longer an implementation detail.

Workflow records, step records, inspect output, and verify validation all share one deterministic contract that future workflow features can safely build on.
