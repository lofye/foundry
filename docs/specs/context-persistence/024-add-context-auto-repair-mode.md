# Execution Spec: 024-add-context-auto-repair-mode

## Feature
- context-persistence

## Purpose
- Introduce a safe, deterministic auto-repair mode for selected context problems.
- Reduce repetitive manual repair work for low-risk, structurally obvious context issues.
- Build on the normalization and diagnostic-rule systems already implemented in `context-persistence`.

## Scope
- Add an explicit context auto-repair command surface.
- Restrict repairs to a narrow, explicitly defined set of low-risk, meaning-preserving fixes.
- Reuse existing diagnostics and normalization infrastructure where practical.
- Keep this focused on canonical feature context files under `docs/features/`.

## Constraints
- Repairs must be deterministic and explicit.
- Auto-repair must be limited to structurally safe, meaning-preserving changes.
- Do not auto-invent semantic content.
- Do not auto-resolve ambiguous alignment issues.
- Do not auto-write decision rationales.
- Do not silently modify files without an explicit repair command.
- Prefer a small safe repair set over broad automation.
- Preserve existing `context doctor` and `verify context` behavior when repair mode is not invoked.

## Inputs

Expect inputs such as:
- `foundry context repair --feature=<feature> --json`
- canonical feature context files under `docs/features/`
- doctor-rule outputs
- normalization infrastructure for state/spec documents

If any critical input is missing:
- fail clearly and deterministically
- do not guess missing semantic content
- do not perform partial hidden repairs

## Requested Changes

### 1. Add an Explicit Repair Command

Introduce an explicit repair surface:

```bash
foundry context repair --feature=<feature> --json
```

Behavior:
- analyze canonical context for the specified feature
- apply only safe repairable fixes
- return a deterministic machine-readable result
- do nothing unless the repair command is explicitly invoked

If `--json` is omitted, plain-text output may also be supported, but JSON behavior must be canonical and testable.

### 2. Restrict Repair Scope to Explicit Safe Cases

Only auto-repair issues that are low-risk and meaning-preserving.

Safe repair categories in this spec are limited to:
- canonical normalization of targeted context artifacts already supported by existing normalizers
- deterministic whitespace cleanup
- deterministic blank-line cleanup
- exact duplicate bullet removal when safe
- restoration of canonical section ordering when all required content already exists

These repairs must apply only to canonical feature context files under:
- `docs/features/<feature>.spec.md`
- `docs/features/<feature>.md`

Do not auto-repair:
- missing semantic requirements
- unsupported state claims that require human judgment
- missing decision rationales
- ambiguous spec/state divergence
- decision-ledger history content
- execution specs
- unrelated repository markdown files

### 3. Reuse Existing Infrastructure

Consume existing infrastructure where practical, including:
- doctor/verify-context analysis
- state/spec normalizers already implemented
- existing context file parsing and validation paths

Do not create a second separate context-analysis stack just for repair mode.

### 4. Define Result Contract Explicitly

`foundry context repair --feature=<feature> --json` must return a deterministic JSON structure including at least:

```json
{
  "status": "repaired|no_changes|blocked|failed",
  "feature": "<feature>",
  "files_changed": ["..."],
  "issues_repaired": ["..."],
  "issues_remaining": ["..."],
  "can_proceed": true,
  "requires_manual_action": false
}
```

Field expectations:
- `status`
  - `repaired` when one or more safe fixes were applied
  - `no_changes` when nothing repairable was needed
  - `blocked` when unresolved issues remain that require human judgment
  - `failed` when repair could not run successfully
- `files_changed`
  - deterministic ordered list of modified canonical context files
- `issues_repaired`
  - deterministic ordered list of repaired issue codes or repair descriptions
- `issues_remaining`
  - deterministic ordered list of unresolved issue codes or repair descriptions
- `can_proceed`
  - true only if the resulting feature context is consumable after repair
- `requires_manual_action`
  - true if unresolved issues remain after safe repairs are applied

You may include additional fields if needed, but do not weaken or omit the fields above.

### 5. Preserve Safety on Ambiguity

If a requested repair would require:
- semantic invention
- ambiguity resolution
- undocumented intent inference
- judgment about historical decisions

then:
- do not change the file
- report the issue as remaining
- set `requires_manual_action = true`

### 6. Make Post-Repair Verification Explicit

After applying safe repairs:
- re-run the relevant verification/readiness checks for the repaired feature
- compute `can_proceed` from the resulting state, not the pre-repair state

Do not report `repaired` in a way that implies the feature is fully healthy if unresolved manual work still remains.

### 7. Keep Existing Flows Unchanged Outside Repair Mode

This spec must not change the behavior of:
- `context doctor --json`
- `verify context --json`
- normal execution flows when repair is not invoked

It adds an explicit repair surface only.

### 8. Tests

Add focused coverage proving:

- safe targeted issues are repaired deterministically
- state/spec normalizers are reused correctly
- unsafe or ambiguous issues are refused cleanly
- repaired files become cleaner without semantic invention
- result JSON is stable and machine-readable
- `can_proceed` and `requires_manual_action` are computed correctly
- existing doctor/verify behavior remains unchanged when repair mode is not invoked
- all relevant tests still pass

## Non-Goals
- Do not auto-resolve ambiguous alignment mismatches.
- Do not auto-write decision rationales.
- Do not auto-generate missing semantic content.
- Do not run repair implicitly during ordinary verification.
- Do not normalize decision ledgers in this spec.
- Do not repair execution specs in this spec.
- Do not broaden this into a general repository fixer.

## Canonical Context
- Canonical feature spec: `docs/features/context-persistence.spec.md`
- Canonical feature state: `docs/features/context-persistence.md`
- Canonical decision ledger: `docs/features/context-persistence.decisions.md`

## Authority Rule
- Context auto-repair must be explicit, conservative, and deterministic.
- Auto-repair must only handle low-risk, meaning-preserving canonical context fixes.
- Human judgment must remain required for ambiguous semantic divergence.

## Completion Signals
- A deliberate `context repair` command exists.
- Safe normalization-style context issues can be repaired automatically.
- Ambiguous semantic issues are refused rather than guessed.
- Output is stable and machine-readable.
- Existing verify behavior remains unchanged outside repair mode.
- All tests pass.

## Post-Execution Expectations
- Repetitive low-risk canonical context repairs become faster and less manual.
- Foundry remains conservative about semantic changes.
- The context system becomes more maintainable without sacrificing trust.
