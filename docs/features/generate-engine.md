# Feature: generate-engine

## Purpose

- Provide a deterministic explain-driven generation surface for evolving Foundry applications safely.

## Current State

- `foundry generate` already plans work from the current explain-derived model using explicit `new`, `modify`, and `repair` modes with deterministic target resolution.
- The existing non-interactive generate workflow already supports dry runs, confidence reporting, git safety checks, pack requirement handling, architectural snapshots and diffs, and post-apply verification.
- Generate JSON payloads and default human output now include a deterministic `safety_routing` recommendation for the `generate-with-safety-routing` skill contract.
- `foundry generate --interactive` and `foundry generate -i` now render a plan summary, per-action detail, and file diffs before mutation.
- Interactive generate now supports approve, reject, and minimal plan modification by excluding actions or files and by toggling risky actions before execution.
- Interactive generate now surfaces risk classification in the plan summary, requires additional confirmation for risky work, requires stronger confirmations for deletions, schema changes, and contract-affecting work, and records user decisions in the result payload.
- Interactive generate now reuses the existing plan, validator, and verification pipeline, and filtered reviewed plans now execute only the approved file actions.
- Human and JSON generate output now capture the original plan, modified plan when applicable, user decisions, executed actions, and verification results for interactive runs.
- Every terminal generate run now persists a canonical plan record under `.foundry/plans/` with a UUID plan id, timestamp, original/final plan data, context packet, execution outcome, verification data, and explicit storage version metadata.
- `foundry plan:list` now returns a deterministic repository-local listing of persisted generate plan summaries.
- `foundry plan:show <plan_id>` now resolves one canonical persisted plan record by plan id.
- `foundry plan:replay <plan_id>` now replays a persisted plan artifact by selecting the approved final plan when present and otherwise the original executable plan.
- Replay now supports adaptive replay by default, strict drift failure through `--strict`, and validation-only dry runs through `--dry-run`.
- Replay now reuses stored plan artifacts, reconstructed replay intent metadata, plan validation, git safety checks, execution ordering, verification, and safety-routing analysis without silently generating a new plan.
- The dedicated `.foundry/plans/` persisted plan surface now coexists with the broader shared `history` surface instead of replacing it.
- Generate failures and interactive rejections now persist failed or aborted plan artifacts in addition to successful runs, while the older `history --kind=generate` surface remains available for broader build/observability-style history.
- Persisted plan artifacts now use UUID plan ids, filesystem-safe timestamped storage paths, and truthful terminal status values across success, failure, and abort outcomes.
- The repository now has an explicit non-destructive interactive generate smoke integration path that invokes `foundry generate ... --mode=new --interactive`, reaches review logic, records rejection, and avoids filesystem mutation.
- Interactive generate coverage includes an explicit valid smoke invocation that reaches review behavior without failing early in argument validation.
- Adding interactive review did not regress the default non-interactive workflow.

## Open Questions

- How far should interactive preview support go for future custom generate execution strategies beyond the current file-action-oriented flows?
- Should interactive review gain richer inspection affordances than the current action, graph, and explain commands?
- Should future CLI work add an explicit `--no-interactive` override once a concrete non-interactive forcing use case appears?
- Should future replay and undo commands consume persisted plan records directly or add a second derived index optimized for those workflows?
- Should replay eventually persist its own execution history separately from the original generate plan record, or remain a read-and-apply operation only?

## Next Steps

- Decide whether a future `--no-interactive` CLI override should surface the non-interactive recommendation explicitly once the need is proven.
- Expand interactive preview support for future custom execution strategies that cannot yet provide full file diffs through the current preview builder.
- Refine the interactive inspection surface if richer graph or explain navigation becomes necessary.
- Decide how undo commands should layer on top of the new `.foundry/plans/` record contract without introducing divergent history state.
- Decide whether replay should eventually emit its own persisted operational history in addition to reusing the stored plan artifact contract.
