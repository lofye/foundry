# Codex Reasoning Policy

## Purpose

Define a consistent reasoning-level policy for Codex work in the Foundry repository so that model effort matches task difficulty, cost, speed, and risk.

This policy is OpenAI/Codex-specific. It is not intended to be LLM-agnostic, because reasoning-effort controls are model-family specific.

---

## Core Principle

Do not use maximum reasoning by default.

Use the **lowest reasoning level that is likely to succeed reliably** for the task at hand.

This keeps work:
- faster
- cheaper
- more reproducible
- easier to iterate on

Higher reasoning is reserved for:
- architecture
- deep debugging
- root-cause analysis
- high-risk changes
- ambiguous or repeated failure cases

---

## Reasoning Levels

### Medium
Use for routine, well-specified implementation work.

Typical cases:
- implementing a spec that is already tight and Codex-ready
- updating tests for a localized change
- deterministic refactors
- CLI wiring with clear contracts
- documentation alignment passes
- ordinary validation / stabilization loops
- strict dry-run previews

Default assumption:
- if the task is clearly specified and bounded, start here

---

### High
Use for harder implementation and stabilization work where multiple subsystems interact.

Typical cases:
- implementing a spec that touches several files or subsystems
- running `implement-spec-and-stabilize`
- running `implement-spec-and-stabilize-strict`
- integrating new CLI surfaces with validators, docs, and tests
- fixing non-trivial regressions
- debugging a failure with several plausible causes
- modifying core workflow logic or enforcement paths

Default assumption:
- if the work is important and multi-file, but still well-scoped, use high

---

### Extra High
Use only when deep reasoning is genuinely required.

Typical cases:
- writing or revising major architectural specs
- root-cause analysis after repeated failed attempts
- tricky invariant design
- debugging non-obvious determinism bugs
- contract / migration / destructive-operation safety review
- designing new framework-level subsystems
- reconciling conflicting evidence across many files

Do not use extra high for:
- routine spec implementation
- repetitive stabilization workflows
- ordinary test fixing
- basic CLI work
- docs cleanup

Default assumption:
- this is research / architecture / hard-debug mode, not normal execution mode

---

## Workflow Mapping

### Implement an ordinary execution spec
Recommended reasoning:
- **High** if the spec is multi-file or core
- **Medium** if the spec is tight, local, and deterministic

### `implement-spec-and-stabilize`
Recommended reasoning:
- **High**

### `implement-spec-and-stabilize-strict`
Recommended reasoning:
- **High**

### `implement-spec-and-stabilize-strict` in dry-run mode
Recommended reasoning:
- **Medium**

### Feature alignment pass
Recommended reasoning:
- **Medium**

### Context repair
Recommended reasoning:
- **Medium** for ordinary safe repair
- **High** if the repair path is new or recently changed

### Spec writing / tightening / major revision
Recommended reasoning:
- **Extra High**

### Root-cause debugging
Recommended reasoning:
- **High** first
- escalate to **Extra High** if:
  - the first attempt fails
  - the cause is non-obvious
  - invariants or architecture may need revision

### Small surgical fix
Recommended reasoning:
- **Medium**

---

## Escalation Rules

Start lower unless there is a clear reason not to.

### Escalate from Medium to High when:
- multiple subsystems are involved
- tests fail in non-obvious ways
- the spec is under-specified
- the change affects contracts or validators
- the first implementation attempt was incomplete or incorrect

### Escalate from High to Extra High when:
- repeated attempts failed
- the task is architectural, not just implementational
- the failure involves hidden invariants or nondeterminism
- the change is risky, destructive, or contract-sensitive
- you need to reason across many files and competing interpretations

### De-escalate when:
- the problem becomes local and well understood
- the spec is now tight and deterministic
- the remaining work is mostly mechanical

---

## Operational Rules

### 1. One run, one reasoning level
Assume a single Codex run uses one reasoning level.

Do not assume reasoning can be raised or lowered dynamically inside one continuous run.

If a different level is needed:
- stop
- start a new run at the new level

### 2. Prefer iteration over permanent maximum effort
A medium/high first pass followed by escalation is preferred over always using extra high.

### 3. Keep reasoning proportional to authority
If a task is tightly constrained by:
- an execution spec
- AGENTS.md
- validation commands
- deterministic skills

then reasoning can usually be lower than for unconstrained design work.

### 4. Safety beats speed when risk is real
If the task includes:
- deletions
- migrations
- contract changes
- irreversible effects
- ambiguous intent

prefer the higher safe reasoning level.

---

## Default Policy

Use these defaults unless there is a clear reason to override them:

- **Medium**:
  - feature-alignment-pass
  - strict dry-run
  - small local fixes
  - deterministic cleanup work

- **High**:
  - normal spec implementation
  - implement-spec-and-stabilize
  - implement-spec-and-stabilize-strict
  - multi-file workflow changes
  - non-trivial debugging

- **Extra High**:
  - architecture
  - hard root-cause analysis
  - major spec design
  - invariant discovery
  - repeated-failure investigations

---

## Prompting Guidance

When launching Codex work, explicitly set expectation by phrasing.

Examples:

### Medium
- “This is a bounded, deterministic implementation. Keep reasoning proportional and avoid overthinking.”
- “This is a local fix. Prefer a medium-effort pass.”

### High
- “This is multi-file implementation work touching core workflows. Use a careful high-effort pass.”
- “This should be implemented carefully and verified fully.”

### Extra High
- “Treat this as deep architecture / root-cause analysis.”
- “Use extra-high reasoning; the goal is to identify the true invariant before changing code.”

---

## Review Rule

If a task that should have succeeded at medium/high required extra high, ask why.

Possible reasons:
- the spec was underspecified
- the architecture is too implicit
- the workflow needs stronger tooling
- the invariant should be formalized in a future spec

The long-term goal is not to rely on extra high more often.
The long-term goal is to make Foundry structured enough that routine work succeeds at medium or high.

---

## Summary

- Default to **Medium** for bounded, deterministic work
- Use **High** for most real implementation and stabilization work
- Reserve **Extra High** for architecture, root-cause analysis, and hard debugging
- Treat reasoning level as fixed per run
- Escalate only when the task actually demands it
