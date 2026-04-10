# Feature: context-persistence

## Purpose

- Introduce feature-level context files for Foundry.
- Support resumable, deterministic feature work.

## Current State

- Canonical spec, state, and decision-ledger files exist for this feature.
- Validators check canonical feature context structure and required sections.
- `context init` and `context doctor` initialize and validate canonical feature context deterministically.
- `context check-alignment` detects spec-state mismatches using deterministic heuristics.
- `inspect context` aggregates doctor and alignment results into a single deterministic view.
- `verify context` maps doctor and alignment results to deterministic pass/fail semantics.
- `inspect context` and `verify context` reuse doctor and alignment services rather than reimplementing either path.
- Divergence backed by decision entries is treated differently from unexplained divergence.
- `implement feature` executes only from canonical context artifacts and revalidates context before finishing.
- `implement feature` blocks execution when `can_proceed` is false unless explicit repair mode succeeds.
- `implement feature` updates feature state and decision history after meaningful execution.
- `implement feature` returns deterministic blocked, repaired, completed, or `completed_with_issues` results.
- `implement spec` resolves execution specs from `docs/specs/<feature>/<NNN-name>.md` and reuses the existing feature execution pipeline.
- `implement spec` records that execution was driven by a specific execution spec without changing canonical authority.
- Execution spec conflicts do not override canonical feature authority.
- `plan feature` uses canonical feature context as authoritative planning input and generates the next bounded execution spec when a concrete gap exists.
- `plan feature` generates non-tautological purpose, scope, requested changes, and slug output for concrete gaps.
- `plan feature` fails clearly when context cannot proceed or no bounded next step can be derived.
- `plan feature` creates an execution spec that is immediately usable by `implement spec`.
- `plan feature` blocks rather than generating vague or self-referential execution specs when only abstract or non-actionable gaps remain.
- CLI commands can initialize and validate feature context.
- CLI commands can detect spec-state mismatches using deterministic heuristics.
- Inspect context aggregates doctor and alignment results into a single deterministic view.
- Verify context maps doctor and alignment results to deterministic pass/fail semantics.

## Open Questions

- How should future multi-step planning remain bounded without becoming roadmap generation?
- How should future repair flows balance usefulness with strict non-speculative behavior?

## Next Steps

- Keep later execution systems safely consumable from canonical feature context files.
