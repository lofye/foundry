---
name: implement-spec-and-stabilize
description: Implement a Foundry execution spec, append implementation log, run validation and tests, optionally run context repair, and perform a feature alignment pass. Use when given a docs/specs/<feature>/<id>-<slug>.md file to fully implement and stabilize the system.
---

# Purpose

Use this skill when:
- implementing a Foundry execution spec
- completing a feature change end-to-end
- stabilizing the system after implementation
- preparing for a clean, verified state

This skill helps produce:
- fully implemented spec
- updated implementation log
- passing tests
- verified context
- repaired context (if safe and needed)
- aligned feature documentation

Do not use this skill for:
- partial implementations
- speculative or exploratory changes
- editing specs without implementing them

# Inputs

Expect inputs such as:
- docs/specs/<feature>/<id>-<slug>.md

If input is missing:
- stop and request the spec path

# Reasoning Principles

## 1. Spec Discipline
- The execution spec is authoritative
- Implement exactly what is specified
- Do not invent beyond the spec

## 2. Deterministic Implementation
- No randomness
- No hidden behavior
- Stable outputs

## 3. Minimal Safe Changes
- Only change what is required
- Avoid unrelated modifications

## 4. Verification First
- The system must pass validation before being considered complete

## 5. No Silent Failures
- If something fails, report clearly

# Execution Pipeline

## Step 1 — Implement Spec

- Implement:
  docs/specs/<feature>/<id>-<slug>.md

- Ensure:
    - behavior matches spec exactly
    - tests are added/updated as required

---

## Step 2 — Append Implementation Log

Append a correct entry to:

docs/specs/implementation-log.md

Rules:
- must follow existing format exactly
- must reference feature and spec ID
- must be appended (not modified in-place)

---

## Step 3 — Run Validation

Run:

php bin/foundry spec:validate --json

- If it fails:
    - fix ONLY relevant issues
    - do not modify unrelated specs

---

## Step 4 — Run Tests

Run:

php vendor/bin/phpunit

- All tests must pass
- Fix failures deterministically

---

## Step 5 — Verify Context

Run:

php bin/foundry verify context --json

- If clean → continue
- If issues exist → proceed to Step 6

---

## Step 6 — Context Auto-Repair (if available)

If safe repairs are possible:

Run:

php bin/foundry context repair --feature=<feature> --json

Rules:
- only apply safe, deterministic repairs
- do not invent semantic content

---

## Step 7 — Feature Alignment Pass

Run a feature alignment pass across:

docs/features/*

Rules:
- align spec/state/decisions
- normalize formatting
- remove duplication
- do not invent meaning

---

## Step 8 — Final Verification

Re-run:

php bin/foundry verify context --json

Ensure:
- system is clean
- feature is consumable

---

# Output

Return a final JSON summary:

{
"status": "ok|partial|failed",
"spec": "<feature>/<id>",
"implemented": true,
"tests_passed": true,
"validation_passed": true,
"context_verified": true,
"repair_applied": true|false,
"alignment_performed": true,
"remaining_issues": [],
"requires_manual_review": true|false
}

# Completion Criteria

- spec implemented correctly
- implementation log updated
- validation passes
- tests pass
- context verifies cleanly
- repair applied if needed and safe
- alignment completed
- system is stable and deterministic