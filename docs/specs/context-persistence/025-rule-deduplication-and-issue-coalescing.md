# Execution Spec: 025-rule-deduplication-and-issue-coalescing

## Feature
- context-persistence

## Purpose
- Reduce diagnostic noise as the context-doctor rule set grows.
- Prevent overlapping rules from producing duplicate or near-duplicate issues and repeated repair guidance.
- Preserve the value of richer diagnostics without degrading trust through noisy outputs.

## Scope
- Add deterministic post-processing for doctor-rule results before they are exposed through:
  - `context doctor --json`
  - `verify context --json`
- Deduplicate overlapping issues where multiple rules identify the same underlying problem.
- Coalesce duplicate or near-duplicate `required_actions` into a smaller, stable set.
- Keep this focused on output quality and noise reduction, not on changing rule semantics or weakening diagnostics.

## Constraints
- Keep diagnostics deterministic and machine-readable.
- Preserve the existing external output contracts for `context doctor` and `verify context`.
- Do not suppress distinct issues that represent genuinely different underlying problems.
- Do not redesign the doctor-rule engine itself in this spec.
- Do not add auto-repair behavior in this spec.
- Prefer explicit, conservative coalescing rules over fuzzy heuristic merging.
- Reuse the normalized doctor-rule infrastructure introduced earlier where practical.

## Inputs

Expect inputs such as:
- normalized doctor-rule results
- doctor file-bucket issue lists
- flattened verify-context issue lists
- top-level `required_actions`

If any critical input is missing:
- fail clearly and deterministically
- do not silently drop diagnostics
- do not invent substitute issues or actions

## Requested Changes

### 1. Add Deterministic Issue Deduplication

Introduce a deterministic deduplication/coalescing step for diagnostic issues.

This step must run after rule evaluation and before final output is returned.

At minimum, it must be able to identify cases where:
- multiple rules emit the same issue code for the same file/target and same underlying problem
- multiple rules emit different issue codes but the exact same canonical repair target and message
- the same issue would otherwise appear multiple times in the flattened `verify context` output

The deduplication rules must be explicit and stable.

### 2. Preserve Distinct Problems

Do not merge issues merely because they mention the same file or same section.

Two issues must remain separate if they differ meaningfully in any of:
- root cause
- remediation path
- canonical issue code
- affected target semantics

This spec reduces noise; it does not hide real problems.

### 3. Add Required-Action Coalescing

Coalesce duplicate or equivalent `required_actions` so users and agents do not receive repeated instructions.

Requirements:
- identical required actions must collapse to one entry
- ordering must remain deterministic
- wording must remain canonical rather than dynamically rewritten
- coalescing must happen consistently for both feature-local and repo-wide verification outputs

### 4. Keep Doctor and Verify Contracts Stable

Do not change the outer JSON shape of:
- `context doctor --json`
- `verify context --json`

This spec may reduce the count of issues/actions returned, but must not change the contract fields themselves.

### 5. Define Explicit Coalescing Rules

Use conservative, deterministic coalescing criteria based on explicit fields such as:
- code
- file_path
- section
- message
- required action text

Do not use broad semantic similarity or LLM-style interpretation in this spec.

### 6. Ensure Stable Ordering

After deduplication/coalescing:
- issue ordering must still be deterministic
- required action ordering must still be deterministic
- repeated runs over the same filesystem state must produce the same ordered output

No filesystem-order leakage or unstable set-order behavior is allowed.

### 7. Keep Rule Outputs Useful for Automation

The reduced output must remain useful for agents and tooling.

That means:
- stable codes are preserved
- important file paths are preserved
- no diagnostic information needed for remediation is lost
- flattened `verify context` output remains sufficiently specific

### 8. Tests

Add focused coverage proving:

- exact duplicate issues are deduplicated
- duplicate required actions are coalesced
- distinct issues are not incorrectly merged
- doctor output shape remains unchanged
- verify output shape remains unchanged
- ordering remains deterministic
- repeated runs produce identical outputs
- all relevant existing context doctor and verify tests still pass

## Non-Goals
- Do not redesign the doctor-rule engine.
- Do not change the meaning of existing rules.
- Do not introduce fuzzy semantic clustering.
- Do not add auto-repair behavior.
- Do not broaden this into general log or message summarization.

## Canonical Context
- Canonical feature spec: `docs/features/context-persistence.spec.md`
- Canonical feature state: `docs/features/context-persistence.md`
- Canonical decision ledger: `docs/features/context-persistence.decisions.md`

## Authority Rule
- Diagnostic expansion must not degrade output quality through avoidable duplication.
- Noise reduction must remain conservative, deterministic, and automation-safe.
- Distinct underlying problems must remain separately visible.

## Completion Signals
- Duplicate issues are reduced deterministically.
- Duplicate required actions are coalesced deterministically.
- Distinct issues remain separate.
- External doctor and verify contracts remain unchanged.
- All tests pass.

## Post-Execution Expectations
- As more context-doctor rules are added, outputs remain readable and trustworthy.
- Humans and agents receive less repetitive remediation guidance.
- Foundry’s diagnostic system scales without becoming noisy.
