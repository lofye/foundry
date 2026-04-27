# Execution Spec: 010-generate-metrics-and-insights

## Purpose

Provide deterministic, opt-in visibility into generate behavior over time, enabling developers to understand usage patterns, identify inefficiencies, and detect potential risks without affecting normal execution.

---

## Feature

`generate-engine`

---

## Goals

1. Track generation behavior over time.
2. Surface actionable insights without introducing non-determinism.
3. Keep metrics strictly opt-in and repository-local.
4. Integrate with existing generate, workflow, and record systems.
5. Enable CLI-based inspection and export of metrics.

---

## Non-Goals

- Do not introduce remote telemetry or external reporting.
- Do not collect data without explicit opt-in.
- Do not impact generate execution performance meaningfully.
- Do not introduce probabilistic or heuristic analysis in V1.
- Do not require a database or external storage.

---

## Core Concepts

### Metrics Collection

A repository-local, opt-in system that records structured metrics about generate operations.

---

### Metrics Record

A deterministic, append-only record of a single generate or workflow execution.

---

### Aggregated Insights

Derived summaries computed deterministically from metrics records.

---

## Opt-In Model

Metrics collection MUST be disabled by default.

Enable via:

```json
{
  "metrics": {
    "enabled": true
  }
}
```

Location:

```text
.foundry/config/metrics.json
```

Rules:

- If not enabled → no metrics collected
- No partial collection
- No implicit enablement

---

## Metrics Record Shape

Metrics MUST be stored as deterministic JSON entries.

Canonical shape:

```json
{
  "schema": "foundry.generate.metrics_record.v1",
  "record_id": "string",
  "type": "single|workflow",
  "template_id": "string|null",
  "workflow_id": "string|null",
  "steps": 0,
  "status": "completed|failed",
  "policy_violations": 0,
  "approval_required": false,
  "approval_status": "pending|approved|rejected|null",
  "timestamp": "string|null"
}
```

---

## Determinism Rules

- Records MUST be append-only
- Field order MUST be stable
- Aggregation MUST be deterministic
- No randomness or sampling
- Timestamp MUST follow existing conventions or be `null` in V1

---

## Collection Rules

When enabled, metrics MUST be recorded for:

- single-step generate runs
- workflow runs
- template-based runs
- approval-gated runs

Metrics MUST include:

- execution type
- status
- step count (for workflows)
- template usage (if any)
- approval usage (if any)
- policy violation count

---

## Aggregation Model

Aggregations MUST be computed deterministically from stored records.

Supported aggregations (V1):

- total runs
- total failures
- failure rate
- average steps per workflow
- template usage counts
- approval usage counts
- policy violation frequency

---

## CLI Behavior

### Show Metrics

```bash
foundry generate:metrics
```

### Export Metrics

```bash
foundry generate:metrics --json
```

### Behavior

- Reads metrics records
- Computes aggregates
- Outputs deterministic summary

---

## Output Requirements

### Text Output

Must include:

```text
total runs: X
failures: X
failure rate: X%
average workflow steps: X
template usage:
  <template_id>: X
approval usage:
  required: X
policy violations: X
```

### JSON Output

Must include:

```json
{
  "total_runs": 0,
  "failures": 0,
  "failure_rate": 0,
  "average_steps": 0,
  "templates": {},
  "approvals": {},
  "policy_violations": 0
}
```

---

## Inspect Surface Requirements

Inspect MUST expose:

- raw metrics records (if requested)
- aggregated metrics
- deterministic ordering

---

## Verify Surface Requirements

Verify MUST fail when:

- metrics schema is invalid
- required fields are missing
- record ordering is corrupted
- aggregation produces inconsistent results

---

## Performance Requirements

- Metrics collection MUST be lightweight
- Must not significantly impact generate execution time
- Aggregation MUST be computed on demand

---

## Compatibility Requirements

- Existing generate behavior MUST be unchanged when metrics are disabled
- Metrics system MUST be additive
- No existing contracts may be broken

---

## Tests Required

1. Metrics disabled → no records created
2. Metrics enabled → records created
3. Deterministic record structure
4. Aggregation correctness
5. CLI output correctness
6. JSON output correctness
7. Verify detects invalid metrics records
8. Backward compatibility

---

## Acceptance Criteria

- Metrics collection is opt-in only
- Metrics are recorded deterministically
- Aggregated insights are available via CLI
- No impact when disabled
- Inspect and verify surfaces support metrics
- All tests pass
- Strict coverage gate exits 0

---

## Done Means

Developers can safely observe and understand generation behavior over time using deterministic, repository-local metrics without affecting system execution.
