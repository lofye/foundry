# Execution Spec: 005-fix-canonical-conflict-detection

## Feature
- execution-spec-system

## Purpose
- Fix false-positive canonical conflict detection during `implement spec`.
- Prevent aligned execution-spec instructions from being blocked merely because they share topic words with canonical negative constraints or non-goals.
- Make execution-spec conflict detection conservative, deterministic, and closer to actual contradiction detection.

## Scope
- Update the canonical conflict detection logic in the execution-spec implementation path.
- Replace or narrow the current token-overlap heuristic used for `EXECUTION_SPEC_CONFLICTS_WITH_CANONICAL_SPEC`.
- Preserve the ability to block real conflicts between execution specs and canonical feature specs.
- Add regression coverage using the current 004 auto-log case as a known false positive.

## Constraints
- Keep canonical feature specs authoritative.
- Keep execution-spec implementation conservative.
- Do not remove conflict detection entirely.
- Do not broaden this into a general NLP or LLM-based semantic contradiction system.
- Keep the detection deterministic and testable.
- Reuse existing execution and validation plumbing where practical.
- Do not require decision-ledger interpretation for this fix.

## Requested Changes

### 1. Fix the Conflict Predicate

Update the conflict detector used by `implement spec` so that raw lexical overlap alone is no longer sufficient to declare a conflict.

The current behavior incorrectly treats shared domain nouns as contradiction evidence.

At minimum:
- shared topic words must not by themselves trigger `EXECUTION_SPEC_CONFLICTS_WITH_CANONICAL_SPEC`
- the detector must require stronger evidence of actual contradiction than `count(overlap) >= 3`

### 2. Preserve Real Conflict Detection

The implementation must still block execution specs that genuinely conflict with canonical feature constraints or non-goals.

Examples of real conflicts that should still be caught:
- canonical spec says a workflow must not modify files, while the execution spec explicitly instructs file modification in that forbidden area
- canonical spec says drafts must not be executed, while the execution spec explicitly instructs draft execution
- canonical spec forbids renaming IDs, while the execution spec instructs renaming existing IDs

### 3. Treat Aligned Reinforcement as Non-Conflict

The detector must not treat aligned implementation instructions as conflicting merely because they repeat the same nouns or object names.

The following kind of pair must be allowed:

- canonical negative constraint:
  - `Automatic implementation logging must not log draft specs, must prevent duplicate entries, and must surface log-write failures clearly and deterministically.`
- execution-spec instruction:
  - `Append entries to docs/features/implementation-log.md.`

This is not a contradiction and must not trigger a conflict.

### 4. Narrow the Heuristic

Implement a narrower deterministic rule.

Acceptable approaches include:
- requiring contradictory polarity rather than noun overlap alone
- requiring the execution-spec instruction to positively instruct an action forbidden by canonical text
- requiring a stronger structured relation than simple token overlap

Do not use:
- LLM inference
- probabilistic semantic scoring
- hidden non-deterministic heuristics

### 5. Keep the Existing External Error Contract

When a real conflict exists, `implement spec` must still return the existing blocked result with:

- `status = blocked`
- `can_proceed = false`
- `requires_repair = true`
- issue code `EXECUTION_SPEC_CONFLICTS_WITH_CANONICAL_SPEC`

This spec changes only detection accuracy, not the external blocked-result contract.

### 6. Regression Coverage

Add regression tests proving:

- the current 004 auto-log case is no longer treated as conflicting
- aligned execution specs that share topic words with canonical negative constraints are allowed
- genuinely conflicting execution specs are still blocked
- repeated runs remain deterministic
- all existing relevant execution-path tests still pass

### 7. Keep the Fix Minimal

Prefer the smallest deterministic fix that eliminates the known false positive and preserves meaningful protection.

Do not redesign the entire implementation workflow in this spec.

## Non-Goals
- Do not redesign `implement spec`.
- Do not change spec naming, allocation, or validation rules.
- Do not add decision-ledger-aware semantic reasoning.
- Do not introduce AI-based contradiction detection.
- Do not weaken canonical feature authority.

## Canonical Context
- Canonical feature spec: `docs/execution-spec-system/execution-spec-system.spec.md`
- Canonical feature state: `docs/execution-spec-system/execution-spec-system.md`
- Canonical decision ledger: `docs/execution-spec-system/execution-spec-system.decisions.md`

## Authority Rule
- Canonical feature specs remain authoritative.
- Execution specs must still be blocked when they truly contradict canonical constraints or non-goals.
- Topic overlap alone is not sufficient evidence of contradiction.

## Completion Signals
- The 004 auto-log execution spec no longer falsely conflicts with the canonical feature spec.
- Real canonical conflicts are still blocked.
- The external blocked-result contract remains unchanged for true conflicts.
- Conflict detection is more precise and still deterministic.
- All tests pass.

## Post-Execution Expectations
- `implement spec` becomes a more trustworthy orchestration surface.
- Execution specs that reinforce canonical behavior are no longer blocked falsely.
- Foundry preserves canonical authority without relying on a brittle overlap heuristic.
