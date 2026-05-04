# Execution Spec: 017-conflict-detection-prohibition-awareness

## Feature
- context-persistence

## Purpose
- Fix conflict detection so prohibition language is interpreted correctly.
- Ensure the system distinguishes between:
  - forbidding an action
  - requiring that the action occur
  - requiring that the action not occur
- Prevent false conflicts caused by treating shared action words as contradiction evidence without considering polarity.

## Scope
- Harden the conflict-detection logic used in context and execution-spec workflows.
- Make the detector prohibition-aware and polarity-aware.
- Keep this focused on contradiction detection behavior, not on broader planning, normalization, or auto-repair behavior.

## Constraints
- Keep conflict detection deterministic and testable.
- Do not introduce LLM-based contradiction detection.
- Do not weaken real conflict detection.
- Do not redesign the full context system in this spec.
- Reuse the current conflict-detection pipeline where practical.
- Prefer a minimal, explicit fix over a broad semantic engine.

## Inputs

Expect inputs such as:
- canonical feature spec requirements and non-goals
- execution-spec instructions
- state or alignment claims that may include prohibition language
- conflict checks that currently rely on lexical overlap or simplified action matching

If any critical input is missing:
- fail clearly and deterministically
- do not guess semantic intent
- do not silently suppress conflict checks

## Requested Changes

### 1. Make Conflict Detection Polarity-Aware

Update conflict detection so it distinguishes between at least these cases:

- positive action requirement
  - example: `Append entries to docs/features/implementation-log.md.`
- negative action requirement / prohibition
  - example: `Do not append log entries for draft specs.`
- canonical prohibition
  - example: `Automatic implementation logging must not log draft specs.`

The detector must no longer treat shared topic words alone as contradiction evidence.

### 2. Enforce the Core Rule

Implement the following rule correctly:

- `Do not do X` does **not** conflict with `must not do X`
- `Do X` **does** conflict with `must not do X`
- `Do not do X` **does** conflict with `must do X`

Equivalent negative formulations should be treated as aligned, not conflicting.

### 3. Preserve Negative Parent Context

If the system is working with parsed execution-spec instructions that originate from nested bullets or negative lead-ins, the final instruction items supplied to conflict detection must preserve prohibition context.

Examples of acceptable normalized items:
- `Do not append log entries for draft specs.`
- `Do not append log entries before implementation succeeds.`

The detector must not operate on orphan fragments such as:
- `for draft specs`
- `before implementation succeeds`

### 4. Require Contradictory Polarity, Not Shared Nouns

A conflict must require:
- the same or substantially similar target action
- and opposing polarity

A conflict must not be raised merely because two items share nouns such as:
- `log`
- `entries`
- `draft specs`
- `implementation`

This fix should prevent false positives where one statement narrows or prohibits a subset of behavior and the other statement describes the general feature area.

### 5. Preserve Real Conflict Detection

The detector must still block true contradictions such as:

- canonical prohibition:
  - `Do not rename existing spec IDs.`
- execution-spec instruction:
  - `Rename existing spec IDs to match the new hierarchy.`

and:

- canonical requirement:
  - `Draft specs remain non-executable planning artifacts.`
- execution-spec instruction:
  - `Execute draft specs during implementation.`

### 6. Keep Existing External Contracts Stable

If a true conflict exists, keep the existing blocked-result behavior intact.

This spec changes conflict-detection accuracy, not the surrounding command contracts.

### 7. Tests

Add focused coverage proving:

- `Do not do X` and `must not do X` are treated as aligned
- `Do X` and `must not do X` are treated as conflicting
- `Do not do X` and `must do X` are treated as conflicting
- equivalent prohibition wording is not treated as contradiction
- nested-bullet prohibition cases do not degrade into orphan fragments
- the known false-positive cases remain fixed
- real conflict cases still block
- repeated runs remain deterministic
- all relevant context and execution-spec tests still pass

## Non-Goals
- Do not introduce AI-based semantic contradiction detection.
- Do not redesign planning or doctor output contracts.
- Do not broaden this into general natural-language understanding.
- Do not normalize all documents in this spec.
- Do not weaken canonical feature authority.

## Canonical Context
- Canonical feature spec: `docs/context-persistence/context-persistence.spec.md`
- Canonical feature state: `docs/context-persistence/context-persistence.md`
- Canonical decision ledger: `docs/context-persistence/context-persistence.decisions.md`

## Authority Rule
- Conflict detection must account for polarity, not just shared topic words.
- Equivalent prohibitions must be treated as aligned, not conflicting.
- Real contradictions must still be blocked deterministically.

## Completion Signals
- Conflict detection distinguishes prohibition from positive instruction.
- `Do not do X` no longer conflicts with `must not do X`.
- True contradictions with opposing polarity still block.
- Known false-positive prohibition cases remain fixed.
- Output contracts remain stable.
- All tests pass.

## Post-Execution Expectations
- Conflict detection becomes more accurate and less noisy.
- The system stops treating equivalent prohibitions as contradictions.
- Execution-spec and context workflows become more trustworthy when negative requirements are involved.
