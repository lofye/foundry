# Execution Spec: canonical-identifiers/001-accept-normalized-input-and-canonicalize-visibly

## Feature
- canonical-identifiers

## Purpose
- Establish a framework-wide rule for accepting non-canonical user input while preserving a single canonical identifier form internally and in all outputs.
- Improve CLI ergonomics without weakening determinism or canonical truth.

## Scope
- Define canonical identifier behavior for feature names and spec-related identifiers in Foundry CLI flows.
- Accept normalized input forms where safe.
- Canonicalize immediately to the framework’s canonical form.
- Surface canonicalization visibly in command output when normalization occurs.

## Constraints
- Keep canonical identifiers authoritative.
- Keep internal resolution deterministic.
- Preserve kebab-case as the canonical external identifier form where that is already the established rule.
- Reuse existing naming and normalization helpers where possible.
- Fail clearly when input cannot be normalized into a valid canonical identifier.
- Avoid silent ambiguity.

## Requested Changes
- Introduce or standardize a framework-wide identifier normalization policy.
- Accept normalized input variants such as:
    - snake_case
    - surrounding whitespace
    - other safe, deterministic forms already compatible with the identifier rules
- Immediately canonicalize accepted input to the canonical kebab-case identifier.
- Ensure all downstream resolution uses only the canonicalized identifier.
- Ensure JSON and text output always report the canonical identifier, not the raw input.
- When normalization occurs, include a visible notice or metadata field indicating:
    - raw input
    - canonicalized identifier
- Keep invalid inputs invalid:
    - uppercase and other disallowed forms must still fail if they cannot be safely normalized under the chosen rule set
- Apply this behavior consistently to the relevant CLI entry points that consume canonical feature/spec identifiers.
- Add PHPUnit coverage for:
    - accepted normalized input
    - canonicalized output
    - invalid input rejection
    - deterministic behavior

## Non-Goals
- Do not introduce multiple canonical forms.
- Do not allow ambiguous identifier resolution.
- Do not weaken existing validation rules beyond explicit safe normalization.
- Do not silently preserve non-canonical identifiers internally.
- Do not bundle unrelated cleanup work into this feature.

## Canonical Context
- Canonical feature spec: `docs/features/canonical-identifiers.spec.md`
- Canonical feature state: `docs/features/canonical-identifiers.md`
- Canonical decision ledger: `docs/features/canonical-identifiers.decisions.md`

## Authority Rule
- Canonical identifiers remain the source of truth.
- Normalized input is a convenience layer only.
- If normalization and canonical truth disagree, canonical truth wins.

## Completion Signals
- Relevant Foundry CLI commands accept safe normalized input forms.
- Internal resolution uses only canonical identifiers.
- Outputs always show canonical identifiers.
- Normalization is visible rather than silent.
- Invalid identifiers still fail clearly.
- All tests pass.

## Post-Execution Expectations
- Developers can type convenient inputs such as `context_persistence` where supported.
- Foundry immediately resolves and reports `context-persistence` as the canonical identifier.
- The system remains deterministic and auditable.