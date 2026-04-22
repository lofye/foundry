# Feature Spec: generate-engine

## Purpose

- Provide a deterministic explain-driven generation surface for evolving Foundry applications safely.
- Keep generation explicit, reviewable, and verifiable whether the workflow is automatic or interactive.

## Goals

- Plan generation work from the current explain-derived system state.
- Preserve the existing non-interactive generate workflow for fast deterministic changes.
- Add an interactive review-and-approval layer for riskier generate flows without duplicating core planning or verification logic.
- Keep machine-readable and human-readable outputs aligned so agents and developers can inspect the same plan.

## Non-Goals

- Do not introduce a web UI or terminal UI panel system for generate review.
- Do not replace the existing `GenerateEngine`, `GenerationPlan`, `PlanValidator`, or `VerificationRunner` with a second planning pipeline.
- Do not weaken non-interactive generate for users who do not opt into interactive review.

## Constraints

- Generate behavior must remain deterministic for the same input and project state.
- Interactive review must not mutate files before explicit approval.
- Interactive review must reuse the existing plan, validation, and verification primitives instead of reimplementing them.
- Risky mutations must surface explicit warnings and stronger confirmation requirements.
- JSON output must remain trustworthy for automation consumers.

## Expected Behavior

- `foundry generate` plans work from the current explain-derived model using explicit `new`, `modify`, and `repair` modes plus deterministic target resolution.
- The existing non-interactive generate workflow continues to support dry runs, confidence reporting, git safety checks, pack requirement handling, architectural snapshots and diffs, and post-apply verification.
- Interactive generate mode renders a plan summary, per-action detail, and file diffs before any file mutation occurs.
- Interactive generate mode supports approve, reject, and minimal plan modification flows by excluding actions or files, then revalidates the modified plan before execution.
- Interactive generate mode classifies risk and requires stronger confirmations for deletions, schema changes, and contract-affecting work.
- Interactive generate output includes the original plan, modified plan when applicable, recorded user decisions, executed actions, and verification results in both human and JSON-friendly forms.
- Repository-owned integration coverage includes a valid non-destructive interactive smoke path that uses the required `--mode`, reaches review logic, and can reject without filesystem mutation.

## Acceptance Criteria

- `foundry generate` remains deterministic and continues to support the existing non-interactive workflow.
- Non-interactive generate continues to expose dry-run planning, confidence data, git safeguards, pack resolution, snapshots and diffs, and verification results.
- `foundry generate --interactive` and `foundry generate -i` present full plan visibility before execution, including summary, detail, and diff output for file changes.
- Interactive generate supports approve, reject, and minimal plan modification without mutating files before approval.
- Interactive generate surfaces risk classification in the plan summary and enforces additional confirmation for risky work.
- Interactive generate reuses the existing plan, validator, and verification pipeline instead of duplicating core logic.
- Interactive generate emits stable human and JSON output that records plan state, decisions, execution, and verification.
- Interactive generate coverage includes an explicit valid smoke invocation that reaches review behavior without failing early in argument validation.
- Adding interactive review does not regress the default non-interactive workflow.

## Assumptions

- The current explain-derived planning pipeline remains the canonical foundation for both non-interactive and interactive generate flows.
- Interactive review is the next major bounded improvement for generate-engine, with higher-level skill routing considered separately afterward.
