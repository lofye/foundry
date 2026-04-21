# Feature: context-persistence

## Purpose
- Introduce feature-level context files for Foundry.
- Support resumable, deterministic feature work.

## Current State
- Canonical spec, state, and decision-ledger files exist for this feature.
- Validators check canonical feature context structure and required sections.
- `context init` and `context doctor` initialize and validate canonical feature context deterministically.
- Feature-scoped doctor diagnostics now evaluate through a normalized internal rule model that feeds the existing doctor file buckets and verify-context flattened issues.
- `context doctor` detects execution-spec drift when active or draft execution specs exist without complete canonical feature context and reports it through the existing missing-file issue buckets.
- `context doctor` now flags stale completed work that remains listed in `Next Steps`.
- `context doctor` now flags current-state divergence from the canonical spec when no supporting decision entry exists.
- `context check-alignment` detects spec-state mismatches using deterministic heuristics.
- Feature state documents now normalize through a reusable deterministic normalizer before framework-owned state updates are persisted.
- `inspect context` aggregates doctor and alignment results into a single deterministic view.
- `verify context` maps doctor and alignment results to deterministic pass/fail semantics.
- `verify context` now derives a per-feature `consumable` flag from doctor status, alignment status, and required actions without changing doctor or alignment rules.
- `verify context` surfaces doctor execution-spec drift issues through its existing flattened issue list.
- `verify context` now surfaces the new state-staleness and missing-decision doctor diagnostics through the same flattened issue list without changing its output shape.
- `verify context` fails when doctor is `repairable` or `non_compliant`.
- `verify context` fails when alignment status is `mismatch`.
- Repo-wide `verify context` now sets top-level `can_proceed = false` when any feature is not consumable, even if its per-feature status still renders as `pass`.
- Active execution specs now require exact implementation-log coverage through the deterministic execution-spec validation path, while drafts remain exempt.
- Foundry now exposes a deterministic CLI surface that emits the exact canonical implementation-log entry content for one active execution spec.
- `inspect context` and `verify context` reuse doctor and alignment services rather than reimplementing either path.
- Divergence backed by decision entries is treated differently from unexplained divergence.
- `implement feature` executes only from canonical context artifacts and revalidates context before finishing.
- `implement feature` now refuses non-consumable canonical context with deterministic `context_not_consumable` guidance unless explicit repair mode succeeds.
- `implement feature` updates feature state and decision history after meaningful execution.
- `implement feature` returns deterministic blocked, repaired, completed, or `completed_with_issues` results.
- `implement spec` resolves active execution specs deterministically from canonical full refs, exact `<feature> <id>` shorthand within a feature, and unique active filename shorthand.
- `<feature> <id>` resolution matches canonical hierarchical ids exactly, resolves active specs only, rejects malformed ids, unknown features, unknown active ids, and ambiguous duplicates, and blocks draft-only matches until the spec is promoted.
- `implement spec` now validates filename-only execution-spec headings, accepts padded hierarchical ids, and reuses the existing feature execution pipeline.
- `implement spec` canonical conflict detection now compares instruction clauses with polarity awareness, requires opposing polarity plus a substantially similar target action before blocking, and no longer treats shared topic words alone as contradiction evidence.
- Nested execution-spec prohibition bullets preserve full negative context through conflict detection, so aligned prohibitions stay unblocked while true opposing-polarity contradictions still fail.
- `implement spec` records that execution was driven by a specific execution spec without changing canonical authority.
- `implement spec` now reuses the same non-consumable-context refusal gate and deterministic refusal reason as `implement feature`.
- Later context-dependent execution surfaces now expose deterministic `context_not_consumable` refusal guidance when canonical context is not safely consumable.
- Execution spec conflicts do not override canonical feature authority.
- `plan feature` uses canonical feature context as authoritative planning input and generates the next bounded execution spec deterministically under `docs/specs/<feature>/drafts/<id>-<slug>.md` when a concrete gap exists.
- `plan feature` now writes at most one draft execution spec file per successful invocation and verifies that its reported `spec_id` and `spec_path` match the actual file written.
- `plan feature` generates non-tautological purpose, scope, requested changes, and slug output for concrete gaps.
- `plan feature` blocks generic fallback specs and weak slug candidates instead of writing low-information execution specs.
- `plan feature` now uses bounded completion signals and rejects low-information purpose, scope, requested changes, or completion signals before rendering an execution spec.
- `plan feature` fails clearly when context cannot proceed or when no bounded next step can be derived.
- `plan feature` blocks rather than generating vague or self-referential execution specs when only abstract or non-actionable gaps remain.
- `plan feature` creates draft execution specs that must be promoted before `implement spec` can execute them.
- Planner input is normalized into a deterministic structure before planning.
- Planner output is deterministic and reproducible for identical canonical inputs.
- Blocked planning responses are deterministic.
- Generated execution specs are rendered through a canonical stub template.
- Execution-spec headings in `docs/specs/` now mirror the filename only.
- Planner allocation no longer reuses root ids that already appear in active or draft execution-spec filenames.
- State normalization enforces canonical `Current State`, `Open Questions`, and `Next Steps` ordering when those sections are present and removes duplicate or obviously stale bullets conservatively.
- Context-persistence is self-hosting and currently passes doctor, alignment, inspect, verify, implement feature, and implement spec checks.

## Open Questions
- How should future multi-step planning remain bounded without becoming roadmap generation?
- How should future repair flows balance usefulness with strict non-speculative behavior?
- How should future compile-time or runtime consumers reuse the consumability gate without duplicating refusal logic?

## Next Steps
- Reuse the consumability refusal helper for any future context-dependent execution surface beyond implement feature and implement spec.
