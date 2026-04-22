# Feature: generate-engine

## Purpose

- Provide a deterministic explain-driven generation surface for evolving Foundry applications safely.

## Current State

- `foundry generate` already plans work from the current explain-derived model using explicit `new`, `modify`, and `repair` modes with deterministic target resolution.
- The existing non-interactive generate workflow already supports dry runs, confidence reporting, git safety checks, pack requirement handling, architectural snapshots and diffs, and post-apply verification.
- `foundry generate --interactive` and `foundry generate -i` now render a plan summary, per-action detail, and file diffs before mutation.
- Interactive generate now supports approve, reject, and minimal plan modification by excluding actions or files and by toggling risky actions before execution.
- Interactive generate now surfaces risk classification in the plan summary, requires additional confirmation for risky work, requires stronger confirmations for deletions, schema changes, and contract-affecting work, and records user decisions in the result payload.
- Interactive generate now reuses the existing plan, validator, and verification pipeline, and filtered reviewed plans now execute only the approved file actions.
- Human and JSON generate output now capture the original plan, modified plan when applicable, user decisions, executed actions, and verification results for interactive runs.
- The repository now has an explicit non-destructive interactive generate smoke integration path that invokes `foundry generate ... --mode=new --interactive`, reaches review logic, records rejection, and avoids filesystem mutation.
- Interactive generate coverage includes an explicit valid smoke invocation that reaches review behavior without failing early in argument validation.
- Adding interactive review did not regress the default non-interactive workflow.

## Open Questions

- How far should interactive preview support go for future custom generate execution strategies beyond the current file-action-oriented flows?
- Should interactive review gain richer inspection affordances than the current action, graph, and explain commands?
- When skill routing is added, which risk thresholds should automatically prefer interactive mode over the fast non-interactive path?

## Next Steps

- Implement `002-generate-skill-integration` so agents can route automatically between the fast and interactive generate paths.
- Expand interactive preview support for future custom execution strategies that cannot yet provide full file diffs through the current preview builder.
- Refine the interactive inspection surface if richer graph or explain navigation becomes necessary.
