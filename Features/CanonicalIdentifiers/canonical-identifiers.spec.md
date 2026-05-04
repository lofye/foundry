# Feature Spec: canonical-identifiers

## Purpose
- Establish a framework-wide rule for accepting safe normalized identifier input while preserving one canonical identifier form internally and in all outputs.
- Improve CLI ergonomics without weakening determinism or canonical truth.

## Goals
- Define canonical identifier behavior for feature and spec-related CLI inputs.
- Accept safe normalized input forms where appropriate.
- Canonicalize accepted input immediately to the canonical identifier.
- Ensure outputs always report canonical identifiers.
- Make normalization visible rather than silent.
- Preserve deterministic identifier resolution.

## Non-Goals
- Do not introduce multiple canonical identifier forms.
- Do not allow ambiguous identifier resolution.
- Do not silently preserve non-canonical identifiers internally.
- Do not weaken identifier validation beyond explicit safe normalization rules.
- Do not bundle unrelated cleanup work into this feature.

## Constraints
- Canonical identifiers remain authoritative.
- Existing established canonical forms must remain stable.
- Kebab-case remains the canonical external identifier form where that rule already exists.
- Safe normalization must be deterministic and bounded.
- Invalid identifiers must still fail clearly when they cannot be safely normalized.
- Output and internal resolution must remain deterministic.

## Expected Behavior
- Relevant Foundry CLI commands may accept safe normalized input forms such as snake_case where supported.
- Accepted input is canonicalized immediately to the canonical kebab-case identifier.
- Internal resolution uses only the canonicalized identifier.
- JSON and text output always show the canonical identifier, not the raw input.
- When normalization occurs, Foundry reports that normalization visibly.
- Ambiguous or invalid identifiers fail clearly and deterministically.

## Acceptance Criteria
- Relevant commands accept safe normalized input forms where intended.
- Canonical identifiers are used internally after normalization.
- Outputs always show canonical identifiers.
- Normalization is visible in output rather than silent.
- Invalid identifiers still fail clearly.
- Behavior is covered by automated tests.
- Deterministic behavior is preserved.

## Assumptions
- Foundry already has or can add small reusable naming helpers.
- Initial scope will focus on feature and execution-spec related CLI flows before any broader expansion.
