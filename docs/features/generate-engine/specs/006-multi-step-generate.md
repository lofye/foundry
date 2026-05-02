# Execution Spec: 006-multi-step-generate

## Purpose
Enable generate to execute multiple coordinated steps as a single workflow, allowing complex system changes to be expressed as deterministic, staged plans.

## Core Principle
A workflow is a sequence of independently valid generation steps that share context and execute in a predictable, ordered manner.

---

## Goals
1. Support multi-step generation plans
2. Enable dependency-aware sequencing between steps
3. Preserve determinism across the entire workflow
4. Maintain full visibility into each step’s intent and outcome
5. Ensure safe failure handling with actionable recovery guidance

---

## Non-Goals
- Do not introduce implicit or hidden step execution
- Do not allow non-deterministic branching
- Do not bypass existing validation systems
- Do not create a complex DSL for workflows

---

## Core Concepts

### WorkflowPlan
A top-level structure representing a multi-step generation workflow.

Properties:
- id (deterministic)
- steps (ordered list)
- sharedContext
- metadata

---

### Step
A single unit of execution within a workflow.

Properties:
- id
- description
- generationPlan (standard Generate plan)
- dependencies (optional, explicit)
- status (pending, complete, failed)

---

### Shared Context
A deterministic, immutable context object passed between steps.

Constraints:
- Must be serializable
- Must not mutate unpredictably
- Must be explicitly extended between steps

---

## Execution Model

1. Workflow is constructed deterministically
2. Steps are ordered explicitly
3. Each step:
   - resolves dependencies
   - validates its GenerationPlan
   - executes
4. Output of each step is merged into Shared Context
5. Next step proceeds using updated context

---

## Requirements

### Execution
- Steps MUST execute sequentially
- No parallel execution in V1
- Execution order MUST be deterministic

### Validation
- Each step MUST pass full validation independently
- Workflow MUST fail if any step fails validation

### Failure Handling
- Fail-fast on first failure
- Provide:
  - failed step ID
  - reason
  - rollback guidance
- No partial silent success

### Determinism
- Same workflow input MUST produce identical results
- Step ordering MUST be stable
- Context merging MUST be deterministic

### Observability
- Each step MUST be visible in CLI output
- Each step MUST record:
  - input
  - output
  - status

---

## CLI Behavior

### New Capability
generate MUST support workflows:

Examples:
- foundry generate --workflow=path/to/workflow.json
- foundry generate --multi-step

V1 contract:
- workflow definitions are repository-local JSON files
- each step declares `id`, `intent`, `mode`, optional `target`, optional `packs`, and optional explicit `dependencies`
- shared context is resolved through deterministic `{{shared.*}}` and `{{steps.<id>.*}}` placeholders

### Output
- Show step-by-step execution
- Show failures clearly
- Provide final workflow summary

---

## Acceptance Criteria

- Multi-step workflows can be defined and executed
- Each step is independently validated
- Execution is strictly deterministic
- Failures stop execution immediately
- CLI clearly shows step progression and results

---

## Done Means

- Generate supports staged, multi-step system evolution
- Developers can express complex changes as workflows
- Determinism, validation, and visibility are preserved
