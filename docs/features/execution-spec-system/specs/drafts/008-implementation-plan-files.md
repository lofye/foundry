# Execution Spec: 008-implementation-plan-files

## Purpose

Add first-class implementation plan files for execution specs so the intended implementation strategy is persisted before Codex or another agent modifies code.

Plans make the spec execution loop inspectable, resumable, and auditable.

## Save Location

If saved before `004-modular-docs-feature-layout` is implemented, save this as:

```text
docs/execution-spec-system/specs/drafts/005-implementation-plan-files.md
```

If saved after `004-modular-docs-feature-layout` is implemented, save this as:

```text
docs/execution-spec-system/specs/drafts/005-implementation-plan-files.md
```

Execute this spec only after `004-modular-docs-feature-layout` is complete.

## Target Plan Location

Each execution spec may have one corresponding implementation plan at:

```text
docs/features/<feature>/plans/<id>-<slug>.md
```

The plan filename must match the execution spec filename exactly.

Example:

```text
docs/execution-spec-system/specs/005-implementation-plan-files.md
docs/execution-spec-system/plans/005-implementation-plan-files.md
```

## Goals

1. Introduce canonical implementation plan files.
2. Add deterministic tooling to create plan drafts from execution specs.
3. Validate plan placement, naming, heading, and spec correspondence.
4. Require an implementation plan before an active execution spec is implemented.
5. Update agent guidance so agents do not treat chat-only plans as sufficient.

## Non-Goals

- Do not replace execution specs.
- Do not make plans the source of truth for requirements.
- Do not allow plans to change spec scope.
- Do not introduce hidden metadata.
- Do not require plans for already-completed historical specs unless explicitly backfilled.
- Do not create nested per-spec directories.

## Plan Contract

A plan file must:

- live at `docs/features/<feature>/plans/<id>-<slug>.md`
- use the same `<id>-<slug>` as the corresponding execution spec
- have a filename-only heading
- be deterministic and reviewable
- describe implementation strategy only
- not alter requirements from the execution spec
- not contain internal `id`, `parent`, or `status` metadata fields

## Required Plan Template

Create a stub/template for new plan files with this structure:

```md
# <id>-<slug>

## Scope

- 

## Entry Points

- 

## Implementation Steps

1. 

## Contracts

- 

## Tests

- 

## Risks and Edge Cases

- 

## Verification

```bash
php bin/foundry spec:validate --json
php bin/foundry verify contracts --json
php bin/foundry verify graph --json
php bin/foundry verify pipeline --json
```
```

Adjust escaping as needed so the stub renders correctly as a Markdown file.

## Tooling

Add a command:

```bash
php bin/foundry spec:plan <feature> <id>
```

The command must:

1. Find the active execution spec at:

```text
docs/features/<feature>/specs/<id>-<slug>.md
```

2. Create the corresponding plan at:

```text
docs/features/<feature>/plans/<id>-<slug>.md
```

3. Use the required plan template.
4. Preserve the exact spec filename stem.
5. Refuse to overwrite an existing plan unless an explicit force option already exists in project command conventions.
6. Return deterministic plain-text output.
7. Return deterministic JSON output when `--json` is passed.

## `spec:plan --json` Output

The JSON output must include at least:

```json
{
  "status": "created",
  "feature": "<feature>",
  "spec": "docs/features/<feature>/specs/<id>-<slug>.md",
  "plan": "docs/features/<feature>/plans/<id>-<slug>.md"
}
```

If the plan already exists, the command must return a deterministic non-zero failure response unless project conventions require idempotent success.

## Validation Rules

Update `spec:validate` so it validates plan files.

It must detect and report:

- plan filename does not match any active execution spec filename
- plan heading does not match filename stem
- plan is outside `docs/features/<feature>/plans/`
- duplicate plan IDs within a feature
- forbidden internal metadata fields
- active execution spec missing a required plan when strict plan enforcement is enabled

## Enforcement Mode

Add a deterministic strict mode to require plans for active execution specs.

Recommended CLI shape:

```bash
php bin/foundry spec:validate --require-plans --json
```

Default behavior may validate existing plans without requiring every active spec to have one, so historical specs do not need immediate backfill.

Agent guidance must require a plan before implementing any new active execution spec.

## Agent Guidance Updates

Update `AGENTS.md` to state:

- execution specs define what must be built
- implementation plans define how a spec will be implemented
- plans must be saved before implementation begins
- chat-only plans are not sufficient
- agents must not use a plan to expand or alter spec scope
- after implementation, agents must update the implementation log as usual

Update any related docs or stubs that describe the spec execution workflow.

## Tests

Add or update tests proving:

1. `spec:plan <feature> <id>` creates `docs/features/<feature>/plans/<id>-<slug>.md`.
2. The generated plan heading matches the filename stem.
3. The generated plan uses the required sections.
4. The command refuses to overwrite existing plans.
5. `spec:plan --json` returns deterministic JSON.
6. `spec:validate` accepts valid plan files.
7. `spec:validate` rejects orphan plans.
8. `spec:validate` rejects plans with mismatched headings.
9. `spec:validate` rejects plans in old or invalid locations.
10. `spec:validate --require-plans --json` fails when an active execution spec lacks a plan.
11. Historical validation without `--require-plans` remains deterministic.

## Acceptance Criteria

- Plans are stored at `docs/features/<feature>/plans/<id>-<slug>.md`.
- Plan filenames match execution spec filenames exactly.
- Plan headings follow the filename-only heading rule.
- `spec:plan` creates plans deterministically.
- `spec:validate` validates plan files.
- `spec:validate --require-plans` enforces missing-plan failures.
- `AGENTS.md` documents the plan-before-implementation rule.
- Tests cover command behavior, validation behavior, and deterministic output.
- Test coverage for changed and added code meets the project threshold.

## Required Verification

Run and pass:

```bash
php bin/foundry spec:validate --json
php bin/foundry spec:validate --require-plans --json
php bin/foundry verify contracts --json
php bin/foundry verify graph --json
php bin/foundry verify pipeline --json
```

Run the project test suite and confirm coverage remains at or above the required threshold.

## Implementation Log

After implementation, append the required entry to the canonical implementation log path.
