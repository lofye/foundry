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
- 35D3 implementation completed.
- 35D4 implementation completed.
- 35D5 implementation completed.
- 35D6 implementation completed.
- 35D7 implementation completed.
- Each feature has one canonical spec, one state document, and one decision ledger.
- Validators can check structure and required sections.
- CLI commands can initialize and validate feature context.
- CLI commands can detect spec-state mismatches using deterministic heuristics.
- Inspect context aggregates doctor and alignment results into a single deterministic view.
- Verify context maps doctor and alignment results to deterministic pass/fail semantics.
- Verify context fails when doctor is repairable or non_compliant.
- Verify context fails when alignment status is mismatch.
- Inspect and verify reuse doctor and alignment services rather than reimplementing either path.
- Divergence backed by decision entries is treated differently from unexplained divergence.
- Implement feature consumes canonical feature context as authoritative execution input.
- Implement feature blocks execution when can_proceed is false unless explicit repair mode succeeds.
- Implement feature updates feature state and decision history after meaningful execution.
- Implement feature revalidates context after execution.
- Implement feature returns deterministic blocked, repaired, completed, or completed_with_issues results.
- Context-persistence is self-hosting and currently passes doctor, alignment, inspect, verify, and implement feature execution checks.
- Alignment results include actionable repair guidance.
- Inspect context returns a deterministic combined context view.
- Verify context returns deterministic pass/fail status for feature context.
- Canonical files exist for the feature.
- Required sections are present in canonical feature files.
- Validation passes.
- CLI can initialize missing context files deterministically.
- CLI can validate context and produce actionable repair guidance.
- CLI can detect spec-state alignment issues deterministically.
- Inspect context returns a deterministic combined context view.
- Verify context returns deterministic pass/fail status for feature context.
- Alignment results include actionable repair guidance.
- Implement feature executes only from canonical context artifacts.
- Implement feature returns deterministic blocked, repaired, completed, or completed_with_issues results.
- Implement feature updates state and decisions when execution changes feature reality.
- Implement feature revalidates context before finishing.

## Open Questions

- How should `implement spec <id>` compose with canonical feature execution without creating a second execution policy path?
- How should planning-generated execution specs be structured so they remain bounded work orders and never override the canonical feature spec?
- How should future repair flows balance usefulness with strict non-speculative behavior?

## Next Steps

- Implement spec-driven execution as the next layer on top of canonical feature execution.
- Keep canonical feature context authoritative and execution specs secondary.
- Preserve deterministic blocked / repaired / completed result semantics in later execution phases.