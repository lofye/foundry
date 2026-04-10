# Feature: canonical-identifiers

## Purpose
- Define and enforce canonical identifier behavior across Foundry.
- Improve CLI ergonomics while preserving a single source of truth for identifiers.

## Current State
- Feature spec created.
- Feature state document created.
- Decision ledger created.
- Canonical identifiers are intended to remain authoritative.
- Some CLI flows already normalize certain inputs opportunistically.
- The framework does not yet have a clearly documented, feature-owned canonical identifier policy.

## Open Questions
- Which CLI entry points should support safe normalization in the first implementation pass?
- Which normalized forms should be accepted beyond snake_case and surrounding whitespace?
- What is the best visible output shape for reporting normalization in text and JSON responses?

## Next Steps
- Implement safe normalized input acceptance for the initial target CLI flows.
- Canonicalize accepted input immediately and use only canonical identifiers downstream.
- Make normalization visible in command output.
- Add automated coverage for accepted normalization, canonicalized output, and invalid input rejection.
