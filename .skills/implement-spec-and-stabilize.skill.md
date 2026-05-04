---
name: implement-spec-and-stabilize
description: Implement a Foundry execution spec and stabilize the system, allowing clearly reported partial completion when blockers remain.
---

# Purpose

Use this skill when:
- preparing for release
- finalizing a feature
- enforcing a fully clean system state
- iterative implementation where repair may require follow-up

This skill helps produce:
- implemented or partially implemented spec
- validation/test/context results
- boundary verification results when available
- clearly reported blockers
- safe next actions

Do not use this skill for:
- release-final zero-tolerance stabilization
- cases where unresolved issues must block completion; use implement-spec-and-stabilize-strict instead

# Inputs

Expect:
- Features/<FeatureName>/specs/<id>-<slug>.md

If missing:
- stop immediately and request it

# Core Principle

This is a **best-effort stabilization pipeline**.

If a step fails or leaves unresolved issues:
→ do not hide it
→ report the blocker clearly
→ provide the smallest safe next action

---

# Execution Pipeline

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

## Step 7 — Feature Boundary Verification (MANDATORY WHEN AVAILABLE)

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
- feature-specific logic should stay inside the owning feature directory
- shared framework files should contain registration glue only
- warnings and violations must be reported

If boundary violations exist:
→ fix them when safely in scope
→ otherwise report them clearly as remaining issues

If the command is not available because the feature-boundary system has not yet been implemented:
→ report "boundary_verification_available": false

---

## Step 8 — Feature Alignment Pass (MANDATORY)

Run:
- feature-alignment-pass across Features/*
- include legacy docs/features/* during migration when present

Then re-run:

php bin/foundry verify context --json

Requirements:
- report whether alignment is clean
- repair safe alignment issues when in scope

If NOT clean:
→ report remaining issues clearly

---

## Step 9 — Final System Check

Report all of the following:

- whether the spec was implemented
- whether the implementation log is correct
- whether spec validation is clean
- whether tests pass
- whether context verification is clean
- whether feature boundary verification is clean when available
- remaining issues
- required manual actions

If any condition is not met:
→ return partial/blocked status rather than claiming completion

---

# Output

Return:

{
"status": "ok|partial|blocked",
"spec": "<feature>/<id>",
"implemented": true|false,
"validation_clean": true|false,
"tests_passed": true|false,
"context_clean": true|false,
"alignment_clean": true|false,
"boundary_clean": true|false|null,
"remaining_issues": [],
"failure_reason": null|string,
"next_actions": []
}

---

# Completion Criteria

SUCCESS requires:

- implemented behavior matches the spec
- tests and validation pass, or blockers are explicitly reported
- context is clean, or unresolved context issues are explicitly reported
- boundary verification is clean when available, or violations are explicitly reported
- deterministic alignment where safely achievable

---

# Authority Rule

This skill must NEVER:
- silently succeed with issues
- hide unresolved problems
- claim strict completion when checks are incomplete

It must:
- report blockers clearly
- distinguish complete success from partial stabilization
- recommend the smallest safe next action

---

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
