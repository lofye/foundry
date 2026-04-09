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
- Validators can check context file structure and required sections deterministically.
- Required sections are present in canonical feature files.
- Context init command implemented.
- Context doctor command implemented.
- Canonical feature context can be initialized deterministically.
- Canonical feature context can be validated deterministically.
- Context doctor returns actionable repair guidance.
- 35D3 implementation completed.
- Context check-alignment command implemented.
- Deterministic spec-state alignment checking is implemented for feature context artifacts.
- Alignment checking compares spec Expected Behavior against Current State, Open Questions, and Next Steps.
- Alignment checking compares spec Acceptance Criteria against Current State, Open Questions, and Next Steps.
- Untracked spec requirements are reported deterministically.
- Unsupported Current State claims are reported deterministically.
- Decision-backed divergence is treated differently from unexplained divergence.
- Alignment results return actionable repair guidance.
- 35D4 implementation completed.
- Inspect context command implemented.
- Verify context command implemented.
- Inspect context aggregates doctor and alignment results into a single deterministic view.
- Verify context maps doctor and alignment results to deterministic pass/fail semantics.
- Verify context fails when doctor is repairable or non_compliant.
- Verify context fails when alignment status is mismatch.
- Inspect and verify reuse doctor and alignment services rather than reimplementing either path.
- Later execution systems can consume canonical feature context files safely.

## Open Questions
- How should refusal-to-proceed rules be expressed in AGENTS.md and APP-AGENTS.md in 35D5?
- How should scaffolded apps receive the finalized context workflow guidance?
- How should future phases expose repair-first workflow guidance without duplicating CLI behavior?

## Next Steps
- Create execution spec 005-agents-app-agents-scaffold-and-onboarding-integration.
- Implement 35D5 using verify context as the primary proceed/fail gate.
