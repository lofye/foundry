# Execution Spec: 004-plan-snapshot-and-patch-based-undo

## Feature

-   generate-engine

## Purpose

-   Strengthen undo reliability by introducing snapshot/patch-based
    rollback inputs.
-   Enable deterministic, high-fidelity reversal of generate operations.
-   Upgrade undo from best-effort (003.002) to structured, data-backed
    rollback.

## Scope

-   Extend plan persistence to capture reversible state (snapshots or
    patches).
-   Support deterministic rollback for file changes.
-   Integrate with existing undo and replay flows.
-   Keep implementation minimal but structurally correct.

## Constraints

-   Must remain deterministic and local to the repo.
-   Must not rely on Git.
-   Must not introduce full version-control semantics.
-   Must not silently infer missing rollback data.
-   Prefer minimal snapshot/patch data over full duplication when
    possible.

## Inputs

Expect: - persisted plan artifacts from 003 - executed file actions -
file contents before and after changes

If missing: - fail clearly - do not guess previous state

## Requested Changes

### 1. Persist Rollback Data

Extend persisted plan records to include:

-   `file_snapshots_before`
-   `file_snapshots_after` (optional)
-   or `patches` (unified diff format)

At minimum, ensure: - every updated file has reversible data - created
files can be removed - deleted files can be restored if snapshot exists

### 2. Snapshot vs Patch Strategy

Acceptable approaches:

Option A (simplest V1): - full file snapshot before change

Option B (preferred scalable): - unified diff patch

Either must be: - deterministic - complete - sufficient for reversal

### 3. Integrate With Undo

Update `plan:undo` to:

-   use snapshot/patch data when available
-   perform full reversal when possible
-   downgrade to partial only when truly irreversible

### 4. Add Integrity Checks

Rollback inputs must include:

-   optional hash of original file
-   validation before applying undo

If mismatch: - warn or refuse depending on severity

### 5. Dry Run Support

Undo must support:

foundry plan:undo `<id>`{=html} --dry-run

and show: - exact rollback operations - confidence level

### 6. Output Contract

JSON must include:

-   rollback_mode (snapshot\|patch)
-   reversible (true\|false)
-   files_recovered
-   files_unrecoverable
-   integrity_warnings

### 7. Determinism

Rollback must: - use only persisted data - produce identical results
given identical state - fail clearly on divergence

### 8. Tests

Add tests for:

-   snapshot-based undo success
-   patch-based undo success
-   deleted file restoration
-   integrity mismatch detection
-   deterministic rollback
-   dry-run correctness

## Non-Goals

-   full Git replacement
-   multi-version branching history
-   cross-plan merging
-   collaborative undo

## Canonical Context

-   docs/generate-engine/generate-engine.spec.md
-   docs/generate-engine/generate-engine.md
-   docs/generate-engine/generate-engine.decisions.md

## Authority Rule

-   Undo must be based on real stored state, not reconstruction.
-   Snapshot/patch data must be the single source of truth for reversal.
-   No guessing.

## Completion Signals

-   persisted plans include rollback data
-   undo uses rollback data deterministically
-   deleted files can be restored when snapshot exists
-   integrity checks work
-   all tests pass

## Post-Execution Expectations

-   Undo becomes reliable instead of best-effort
-   Generate operations become safely reversible
-   Foundation is ready for advanced rollback and collaboration
