Implement Foundry Master Spec 35D4A — Context-Persistence Reconciliation

Objective

Reconcile the context-persistence feature’s canonical context files with the actual behavior implemented through 35D4, and refine alignment handling only if necessary to make context-persistence a trustworthy self-hosting example.

Implement:
- minimal updates to canonical feature docs if needed
- minimal alignment refinement only if required
- no new commands
- no new context artifact types
- no AGENTS or scaffold changes yet

Use:
- context doctor
- context check-alignment
- inspect context
- verify context

Goal:
- context-persistence should pass verify context
- or fail only for a clearly justified and documented reason

Scope

- Reconcile docs/features/context-persistence.spec.md
- Reconcile docs/features/context-persistence.md
- Reconcile docs/features/context-persistence.decisions.md
- Optionally refine alignment grounding heuristics only if necessary to resolve obvious self-hosting false mismatches

Constraints

Do NOT:
- add new commands
- broaden alignment semantics significantly
- update AGENTS.md or APP-AGENTS.md
- implement scaffold changes

Acceptance Criteria

- context doctor --feature=context-persistence --json returns ok
- context check-alignment --feature=context-persistence --json returns ok or warning
- verify context --feature=context-persistence --json returns pass
- all tests pass