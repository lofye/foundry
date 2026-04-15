# Feature: context-persistence

## Purpose
- Introduce feature-level context files for Foundry.
- Support resumable, deterministic feature work.

## Current State
- Canonical spec, state, and decision-ledger files exist for this feature.
- Validators check canonical feature context structure and required sections.
- `context init` and `context doctor` initialize and validate canonical feature context deterministically.
- `context doctor` detects execution-spec drift when active or draft execution specs exist without complete canonical feature context and reports it through the existing missing-file issue buckets.
- `context check-alignment` detects spec-state mismatches using deterministic heuristics.
- `inspect context` aggregates doctor and alignment results into a single deterministic view.
- `verify context` maps doctor and alignment results to deterministic pass/fail semantics.
- `verify context` surfaces doctor execution-spec drift issues through its existing flattened issue list.
- `verify context` fails when doctor is `repairable` or `non_compliant`.
- `verify context` fails when alignment status is `mismatch`.
- `inspect context` and `verify context` reuse doctor and alignment services rather than reimplementing either path.
- Divergence backed by decision entries is treated differently from unexplained divergence.
- `implement feature` executes only from canonical context artifacts and revalidates context before finishing.
- `implement feature` blocks execution when `can_proceed` is false unless explicit repair mode succeeds.
- `implement feature` updates feature state and decision history after meaningful execution.
- `implement feature` returns deterministic blocked, repaired, completed, or `completed_with_issues` results.
- `implement spec` resolves execution specs from `docs/specs/<feature>/<id>-<slug>.md` and reuses the existing feature execution pipeline.
- `implement spec` now validates filename-only execution-spec headings and accepts padded hierarchical ids.
- `implement spec` records that execution was driven by a specific execution spec without changing canonical authority.
- Execution spec conflicts do not override canonical feature authority.
- `plan feature` uses canonical feature context as authoritative planning input and generates the next bounded execution spec when a concrete gap exists.
- `plan feature` generates non-tautological purpose, scope, requested changes, and slug output for concrete gaps.
- `plan feature` fails clearly when context cannot proceed or when no bounded next step can be derived.
- `plan feature` blocks rather than generating vague or self-referential execution specs when only abstract or non-actionable gaps remain.
- `plan feature` creates an execution spec that is immediately usable by `implement spec`.
- Planner input is normalized into a deterministic structure before planning.
- Planner output is deterministic and reproducible for identical canonical inputs.
- Blocked planning responses are deterministic.
- Generated execution specs are rendered through a canonical stub template.
- Execution-spec headings in `docs/specs/` now mirror the filename only.
- Planner allocation no longer reuses root ids that already appear in active or draft execution-spec filenames.
- Context-persistence is self-hosting and currently passes doctor, alignment, inspect, verify, implement feature, and implement spec checks.

## Open Questions
- How should future multi-step planning remain bounded without becoming roadmap generation?
- How should future repair flows balance usefulness with strict non-speculative behavior?
- How should later execution systems consume canonical feature context without weakening deterministic guarantees?

## Next Steps
- Keep later execution systems safely consumable from canonical feature context files.
