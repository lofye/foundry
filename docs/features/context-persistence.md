# Feature: context-persistence

## Purpose
- Introduce feature-level context files for Foundry.
- Support resumable, deterministic feature work.

## Current State
- Feature spec created.
- Feature state document created.
- Decision ledger created.
- 35D1 implementation completed.
- 35D2 implementation completed.
- Context init command implemented.
- Context doctor command implemented.
- Feature context can be initialized and structurally validated.
- Execution spec 003-spec-state-alignment-engine created.
- 35D3 not yet implemented.

## Open Questions
- How strict should alignment heuristics be in early versions?
- What threshold distinguishes warning vs mismatch?
- How should future phases refine alignment without introducing non-determinism?

## Next Steps
- Implement execution spec 003-spec-state-alignment-engine.
- Add alignment checker.
- Add context check-alignment CLI command.
- Validate alignment behavior on real feature context.