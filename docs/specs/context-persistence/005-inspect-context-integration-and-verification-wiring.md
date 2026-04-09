Implement Foundry Master Spec 35D4 — Inspect Context Integration and Verification Wiring

Objective

Integrate context anchoring into Foundry’s inspection and verification workflows.

Expose:
- inspect context
- verify context

These commands must compose existing context systems, not reimplement them.

Use:
- context doctor (35D2)
- context check-alignment (35D3/35D3A)

Do NOT duplicate validation or alignment logic.

---

Scope

Add command: inspect context

Support:

php bin/foundry inspect context <feature>
php bin/foundry inspect context <feature> --json

Behavior:
- validate feature name
- resolve canonical file paths
- call:
    - ContextDoctorService
    - AlignmentChecker
- aggregate:
    - spec file status
    - state file status
    - decision ledger status
    - doctor result
    - alignment result
- return a concise, unified view of context health for the feature
- remain deterministic

---

Add command: verify context

Support:

php bin/foundry verify context
php bin/foundry verify context --json
php bin/foundry verify context --feature=<feature>
php bin/foundry verify context --feature=<feature> --json

Behavior:
- reuse doctor + alignment results
- return pass/fail semantics
- support CI usage
- remain deterministic

---

Verification Semantics

Pass condition

A feature passes verification when:
- doctor status is ok or warning
  AND
- alignment status is ok or warning

Fail condition

A feature fails verification when:
- doctor status is repairable or non_compliant
  OR
- alignment status is mismatch

Do NOT introduce new status categories.

---

JSON Output — inspect context

Suggested shape:

{
"feature": "event-bus",
"doctor": { ... },
"alignment": { ... },
"summary": {
"doctor_status": "ok|warning|repairable|non_compliant",
"alignment_status": "ok|warning|mismatch"
}
}

Requirements:
- reuse existing doctor and alignment JSON structures
- stable keys
- deterministic ordering
- no timestamps

---

JSON Output — verify context

Single feature:

{
"feature": "event-bus",
"status": "pass|fail",
"doctor_status": "...",
"alignment_status": "...",
"issues": []
}

For multi-feature mode (no --feature flag):
- evaluate all features deterministically
- return a stable aggregate result consistent with Foundry conventions

---

Consistency Rules

The following must remain consistent across:
- context doctor
- context check-alignment
- inspect context
- verify context

Consistency includes:
- feature naming behavior
- canonical path resolution
- issue codes where shared
- status semantics
- JSON key naming and ordering

Do NOT introduce conflicting interpretations.

---

Files to Create or Update

Create or update:
- src/CLI/Commands/InspectContextCommand.php
- src/CLI/Commands/VerifyContextCommand.php
- src/Context/ContextInspectionService.php (optional but recommended)

You may integrate into existing inspect/verify infrastructure if appropriate.

---

Responsibilities

InspectContextCommand
- validate feature name
- aggregate doctor + alignment results
- return unified view
- deterministic output

VerifyContextCommand
- map doctor + alignment results to pass/fail
- support single feature and all-feature modes
- deterministic output

ContextInspectionService (optional)
- centralize aggregation logic
- avoid duplication between commands

---

Constraints

Do NOT implement:
- new validation rules
- new alignment logic
- refusal-to-proceed logic
- AGENTS.md updates
- APP-AGENTS.md updates
- scaffold changes

This spec is integration only.

---

Tests (PHPUnit)

Unit tests
- aggregation combines doctor + alignment results correctly
- verification mapping produces correct pass/fail outcomes

Integration tests
- inspect context <feature> --json returns combined context status
- verify context --feature=<feature> --json returns deterministic output
- compliant feature passes
- repairable/non_compliant/mismatch feature fails

---

Acceptance Criteria

The work is complete only when:
- inspect context <feature> returns aggregated context status
- verify context returns correct pass/fail semantics
- existing context services are reused (no duplication)
- outputs are deterministic and stable
- all tests pass

---

Final Instruction

Unify the context system into Foundry’s inspection and verification language.

Reuse existing services.

Do not introduce new concepts or duplicate logic.