---
name: implement-spec-and-stabilize-strict
description: Strictly implement a Foundry execution spec and enforce a fully clean, verified, aligned system state. Supports dry-run mode. Refuses completion if any issues remain.
---

# Purpose

Use this skill when:
- preparing for release
- finalizing a feature
- enforcing a fully clean system state
- zero-tolerance for drift or unresolved issues

This skill helps produce:
- fully implemented spec
- zero validation errors
- zero failing tests
- zero context verification issues
- fully aligned feature context
- production-ready system state

Do not use this skill for:
- iterative development
- partially complete specs
- exploratory work

# Inputs

Expect:
- Features/<FeatureName>/specs/<id>-<slug>.md

If missing:
- stop immediately and request it

---

# Modes

## Normal Mode (default)
- Executes the full pipeline
- Modifies files
- Applies repairs and alignment
- Must end in a fully clean state or fail

## Dry-Run Mode

If invoked with "dry-run":

- DO NOT modify any files
- DO NOT append to implementation-log
- DO NOT execute repair writes

Instead:

1. Analyze the spec implementation impact
2. Identify:
   - files that would change
   - tests that would be affected
   - validation issues
   - context issues
   - repairable issues
3. Simulate:
   - repair results
   - alignment changes

Return:

{
  "status": "ok|blocked",
  "dry_run": true,
  "would_modify_files": [],
  "would_add_log_entry": true|false,
  "would_fail_validation": true|false,
  "would_fail_tests": true|false,
  "would_fail_boundary_verification": true|false|null,
  "context_issues": [],
  "repairable_issues": [],
  "unresolved_issues": [],
  "can_proceed": true|false
}

Rules:
- No side effects
- Deterministic output
- Same input → same output

---

# Core Principle

This is a **zero-tolerance pipeline**.

If ANY step fails or leaves unresolved issues:
→ DO NOT COMPLETE  
→ REPORT FAILURE

---

# Execution Pipeline (Normal Mode Only)

## Step 1 — Implement Spec
- Implement exactly as specified
- No invention
- Add/update tests as required

---

## Step 2 — Append Implementation Log
- Append to:
  docs/features/implementation-log.md
- Must follow exact format

---

## Step 3 — Spec Validation (MUST PASS CLEAN)

Run:

php bin/foundry spec:validate --json

Requirements:
- zero violations
- no warnings
- no unrelated breakage

If ANY violation exists:
→ FIX or FAIL

---

## Step 4 — Tests (MUST PASS CLEAN)

Run:

php vendor/bin/phpunit

Requirements:
- all tests pass
- no skipped critical tests

If ANY failure:
→ FIX or FAIL

---

## Step 5 — Context Verification (MUST BE CLEAN)

Run:

php bin/foundry verify context --json

Requirements:
- no issues
- no required_actions
- all features consumable

If ANY issue exists:
→ proceed to repair

---

## Step 6 — Context Repair (REQUIRED IF ISSUES EXIST)

Run:

php bin/foundry context repair --feature=<feature> --json

Then:

Re-run:

php bin/foundry verify context --json

If still not clean:
→ FAIL (do not proceed)

---

## Step 7 — Feature Boundary Verification (MANDATORY)

Run when available:

```bash
php bin/foundry verify features --json
```

Prefer feature-scoped verification when available:

```bash
php bin/foundry verify features --feature=<feature> --json
php bin/foundry feature:map --feature=<feature> --json
```

Requirements:
- zero boundary violations
- no feature-specific runtime logic outside the owning feature directory
- no feature-specific tests outside the owning feature directory unless explicitly grandfathered
- shared framework files contain registration glue only
- opt-out, if configured, is explicit and reported

If ANY boundary violation exists:
→ FIX or FAIL

If the command is not available because the feature-boundary system has not yet been implemented:
→ report "boundary_verification_available": false
→ do not claim boundary enforcement was run

---

## Step 8 — Feature Alignment Pass (MANDATORY)

Run:
- feature-alignment-pass across Features/*

Then re-run:

php bin/foundry verify context --json

Requirements:
- still fully clean

If NOT:
→ FAIL

---

## Step 9 — Final System Check

All must be true:

- spec implemented
- implementation log correct
- spec validation clean
- tests pass
- context verification clean
- feature boundary verification clean when available
- no remaining issues
- no required manual actions

If ANY condition is not met:
→ FAIL

---

# Output (Normal Mode)

Return:

{
  "status": "ok|failed",
  "spec": "<feature>/<id>",
  "implemented": true|false,
  "validation_clean": true|false,
  "tests_passed": true|false,
  "context_clean": true|false,
  "alignment_clean": true|false,
  "boundary_clean": true|false|null,
  "remaining_issues": [],
  "failure_reason": null|string
}

---

# Completion Criteria

SUCCESS requires:

- zero validation issues
- zero failing tests
- zero context issues
- zero feature boundary violations when boundary verification is available
- zero required_actions
- deterministic alignment
- no ambiguity

---

# Authority Rule

This skill must NEVER:
- silently succeed with issues
- downgrade failures to warnings
- ignore unresolved problems

It must:
- enforce a fully clean, production-ready state
- fail loudly if that state is not achieved
