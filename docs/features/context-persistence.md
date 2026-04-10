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
- 35D7B implementation completed.
- 35D8 implementation completed.
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
- Implement spec resolves execution specs under docs/specs/<feature>/<NNN-name>.md deterministically.
- Implement spec reuses the existing feature execution pipeline and keeps canonical feature context authoritative.
- Implement spec blocks conflicting execution specs clearly and preserves existing repair semantics.
- Implement spec records the driving execution spec in deterministic actions_taken output without changing canonical feature authority.
- Plan feature generates the next bounded execution spec deterministically under docs/specs/<feature>/<NNN-name>.md.
- Plan feature uses canonical feature context as authoritative planning input and fails clearly when context cannot proceed.
- Plan feature derives concrete planning gaps from Expected Behavior versus Current State.
- Plan feature generates non-tautological purpose, scope, requested changes, and slug output for concrete gaps.
- Plan feature blocks when only abstract or non-actionable gaps remain.
- Plan feature creates execution specs that are immediately usable by implement spec.
- Context-persistence is self-hosting and currently passes doctor, alignment, inspect, verify, implement feature, and implement spec checks.
- Context-persistence blocks plan feature cleanly when no meaningful concrete step remains.
- Alignment results include actionable repair guidance.
- Inspect context returns a deterministic combined context view.
- Verify context returns deterministic pass/fail status for feature context.
- Canonical files exist for the feature.
- Required sections are present in canonical feature files.
- Validation passes.
- CLI can initialize missing context files deterministically.
- CLI can validate context and produce actionable repair guidance.
- CLI can detect spec-state alignment issues deterministically.
- Implement feature executes only from canonical context artifacts.
- Implement feature updates state and decisions when execution changes feature reality.
- Implement feature revalidates context before finishing.
- Implemented Each feature has one canonical spec, one state document, and one decision ledger.
- Planner input is normalized into a deterministic structure before execution.
- Planner output is fully deterministic and reproducible for identical inputs.
- Blocked planning responses are deterministic.

## Open Questions

- How should future multi-step planning remain bounded without becoming roadmap generation?
- How should future repair flows balance usefulness with strict non-speculative behavior?

## Next Steps

- Keep canonical feature context authoritative and execution specs secondary.
- Keep spec-driven execution layered on top of the existing feature execution pipeline rather than introducing a second policy path.
- Keep planning bounded to one coherent execution spec at a time.
- Keep planner output concrete enough to block rather than generate abstract work orders.
- Preserve deterministic blocked / repaired / completed / planned result semantics in later execution phases.
- Keep later execution systems safely consumable from canonical feature context files.
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
- Implement spec resolves execution specs deterministically from docs/specs/<feature>/<NNN-name>.md.
- Implement spec reuses the existing feature execution pipeline rather than creating a second execution policy path.
- Implement spec blocks when execution-spec instructions conflict with canonical feature truth.
- Implement spec records that execution was driven by a specific execution spec without changing canonical authority.
- Plan feature generates the next bounded execution spec deterministically under docs/specs/<feature>/<NNN-name>.md.
- Plan feature uses canonical feature context as authoritative planning input.
- Plan feature fails clearly when context cannot proceed or no bounded next step can be derived.
- Later execution systems can consume canonical feature context files safely.
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
- Implement spec executes a discrete implementation spec without bypassing canonical context validation.
- Implement spec returns deterministic blocked, repaired, completed, or completed_with_issues results aligned with implement feature.
- Execution spec conflicts do not override canonical feature authority.
- Plan feature returns deterministic planned or blocked results.
- Plan feature creates an execution spec that is immediately usable by implement spec.
