# Execution Spec: 002-generate-skill-integration

## Feature
generate-engine

## Title
Generate ↔ Skill System Integration (Auto Mode Selection)

---

## Purpose

Allow LLM agents (e.g., Codex) to automatically choose between:

- non-interactive generate (fast path)
- interactive generate (safe path)

based on intent, risk, and context.

---

## Core Principle

LLMs must default to the safest correct behavior while preserving velocity.

---

## Scope

### In Scope
- decision logic for interactive vs non-interactive
- skill integration contract
- risk-aware routing
- CLI compatibility

### Out of Scope
- modifying core generate logic
- UI changes

---

## Decision Model

The system MUST select mode based on:

### Use Non-Interactive When:

- additive changes only
- low risk
- deterministic scaffolding
- CI/CD context

### Use Interactive When:

- modifying existing code
- medium/high risk
- schema changes
- deletions
- contract impact
- unclear intent

---

## Skill Contract

Skill name:

`generate-with-safety-routing`

Behavior:

1. Analyze intent
2. Build plan (dry-run)
3. Inspect risk level
4. Route:

```
if risk == LOW:
    run generate (non-interactive)
else:
    run generate --interactive
```

---

## Codex Prompt Contract

Codex MUST:

- prefer safety over speed
- choose interactive for uncertainty
- never silently perform high-risk changes

---

## CLI Compatibility

No breaking changes.

Explicit flags override routing:

- `--interactive` → force interactive
- `--no-interactive` (future) → force non-interactive

---

## Determinism

Routing decision MUST be deterministic based on:

- plan
- risk
- intent

---

## Testing

Add tests for:

- routing decisions
- risk thresholds
- override flags
- CI-safe behavior

---

## Acceptance Criteria

- skill can route correctly
- interactive used for risky changes
- non-interactive used for safe changes
- no regression in existing workflows

---

## Done Means

LLMs automatically choose the correct execution mode.

Developers get:

- speed when safe
- safety when needed
