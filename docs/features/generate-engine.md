# Feature: generate-engine

## Purpose

- Provide a deterministic explain-driven generation surface for evolving Foundry applications safely.

## Current State

- `foundry generate` already plans work from the current explain-derived model using explicit `new`, `modify`, and `repair` modes with deterministic target resolution.
- The existing non-interactive generate workflow already supports dry runs, confidence reporting, git safety checks, pack requirement handling, architectural snapshots and diffs, and post-apply verification.
- Generate JSON payloads and default human output now include a deterministic `safety_routing` recommendation for the `generate-with-safety-routing` skill contract.
- Generate now optionally loads `.foundry/policies/generate.json`, evaluates deterministic V1 repository-local policy rules against validated plans before execution, and returns policy status, matched rules, warnings, violations, and override metadata in both human and JSON output.
- `foundry generate --policy-check` now evaluates planning, validation, and repository policy without executing feature-file writes, and `--allow-policy-violations` now acts as an explicit visible override for blocking policy results.
- `foundry generate --workflow=<file> [--multi-step]` now loads repository-local JSON workflow definitions, executes steps sequentially through the existing single-step generate engine, resolves `{{shared.*}}` and `{{steps.<id>.*}}` placeholders deterministically, and emits per-step input/output/status plus explicit rollback guidance on fail-fast workflow errors.
- Workflow runs now persist a canonical parent workflow plan record with schema `foundry.generate.workflow_record.v1`, deterministic workflow IDs, ordered step summaries, final shared context, compact result fields, and explicit rollback guidance in addition to the underlying per-step generate plan records.
- Generate plan records produced inside workflow runs now remain normal step-level generate records while persisting explicit `metadata.workflow` linkage (`workflow_id`, `step_id`, `step_index`, `is_workflow_step`) so parent and child records stay inspectable and verifiable together.
- `foundry generate --interactive` and `foundry generate -i` now render a plan summary, per-action detail, and file diffs before mutation.
- Interactive generate now supports approve, reject, and minimal plan modification by excluding actions or files and by toggling risky actions before execution.
- Interactive generate now surfaces policy warnings and violations during review, re-evaluates policy after plan modifications, and requires explicit confirmation before executing a policy-denied plan with an override.
- Interactive generate now surfaces risk classification in the plan summary, requires additional confirmation for risky work, requires stronger confirmations for deletions, schema changes, and contract-affecting work, and records user decisions in the result payload.
- Interactive generate now reuses the existing plan, validator, and verification pipeline, and filtered reviewed plans now execute only the approved file actions.
- Human and JSON generate output now capture the original plan, modified plan when applicable, user decisions, executed actions, and verification results for interactive runs.
- Every terminal generate run now persists a canonical plan record under `.foundry/plans/` with a UUID plan id, timestamp, original/final plan data, context packet, execution outcome, verification data, and explicit storage version metadata.
- `foundry plan:list` now returns a deterministic repository-local listing of persisted generate plan summaries.
- `foundry plan:show <plan_id>` now resolves one canonical persisted plan record by plan id and renders workflow hierarchy explicitly for parent workflow records.
- Persisted plan inspection now fails fast on malformed workflow parents, orphaned workflow step records, mismatched step indexes or record IDs, and inconsistent workflow result/status combinations instead of silently accepting broken grouped history.
- `foundry plan:replay <plan_id>` now replays a persisted plan artifact by selecting the approved final plan when present and otherwise the original executable plan.
- `foundry plan:undo <plan_id>` now previews or applies deterministic snapshot- or patch-backed rollback from persisted plan artifacts and requires `--yes` before deleting generated files.
- Replay now supports adaptive replay by default, strict drift failure through `--strict`, and validation-only dry runs through `--dry-run`.
- Replay now reuses stored plan artifacts, reconstructed replay intent metadata, plan validation, git safety checks, execution ordering, verification, and safety-routing analysis without silently generating a new plan.
- Successful live persisted plan records now store structured rollback inputs including before/after file snapshots, unified diffs, and integrity hashes so undo can restore updated or deleted files from persisted data alone.
- Undo now chooses `snapshot` or `patch` rollback modes deterministically, validates stored integrity hashes before applying rollback, and downgrades to warnings or skips instead of guessing when the current filesystem no longer matches the stored post-generate state.
- Undo now returns deterministic `rollback_mode`, `reversible`, `files_recovered`, `files_unrecoverable`, `integrity_warnings`, `confidence_level`, `status`, `reversible_actions`, `reversed_actions`, `irreversible_actions`, `skipped_actions`, and `warnings` payload fields so rollback fidelity and partial outcomes are explicit.
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
- Should future generate policy work add non-overrideable blocking rules, or keep V1-style explicit overrides as the only enforcement escape hatch?
- Should future undo support grow beyond single-plan snapshot and patch rollback into richer graph-aware or git-assisted restoration without weakening the current deterministic contract?
- Should replay eventually persist its own execution history separately from the original generate plan record, or remain a read-and-apply operation only?
- Should workflow runs eventually gain first-class replay and grouped undo semantics on top of the parent workflow plan record instead of relying on the persisted per-step plan records?
- Should workflow mode eventually support top-level `--explain` and `--git-commit`, or should those remain follow-on work until grouped semantics are specified?

## Next Steps

- Decide whether a future `--no-interactive` CLI override should surface the non-interactive recommendation explicitly once the need is proven.
- Expand interactive preview support for future custom execution strategies that cannot yet provide full file diffs through the current preview builder.
- Refine the interactive inspection surface if richer graph or explain navigation becomes necessary.
- Decide whether future policy iterations need non-overrideable rules, starter policy templates, or broader scoped matching beyond the current repository-local V1 contract.
- Decide how undo commands should layer on top of the new `.foundry/plans/` record contract without introducing divergent history state.
- Decide whether replay should eventually emit its own persisted operational history in addition to reusing the stored plan artifact contract.
- Decide whether workflow runs should add grouped replay and undo semantics beyond the current parent-record inspection plus per-step rollback guidance.
- Decide whether workflow mode should grow explicit top-level explain or git-commit support once grouped output and commit semantics are specified.
