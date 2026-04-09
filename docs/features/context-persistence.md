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
- Canonical feature context can be initialized deterministically.
- Canonical feature context can be validated deterministically.
- Context doctor returns actionable repair guidance.
- 35D3 implementation completed.
- Context check-alignment command implemented.
- Spec-state alignment checking is implemented for feature context artifacts.
- Alignment checking compares spec Expected Behavior against Current State, Open Questions, and Next Steps.
- Alignment checking compares spec Acceptance Criteria against Current State, Open Questions, and Next Steps.
- Untracked spec requirements are reported deterministically.
- Unsupported Current State claims are reported deterministically.
- Decision-backed divergence is treated differently from unexplained divergence.
- Alignment results return actionable repair guidance.

## Open Questions
- How should inspect context compose doctor and alignment results in 35D4?
- How should verify context map doctor and alignment results to pass/fail semantics?
- How should later phases refine alignment heuristics without introducing non-determinism?

## Next Steps
- Create execution spec 004-inspect-context-integration-and-verification-wiring.
- Implement inspect context.
- Implement verify context.
- Reuse doctor and alignment results consistently in inspect and verify flows.