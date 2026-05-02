# Execution Spec: 008-implementation-plan-files

## Feature

- execution-spec-system

## Purpose

Add first-class implementation plan files for execution specs so the intended implementation strategy is persisted before Codex or another agent modifies code.

Plans make the spec execution loop inspectable, resumable, and auditable without making plans authoritative over execution specs.

## Depends On

- `007-modular-docs-feature-layout.md`
- `007.001-agent-facing-doc-path-contracts.md`
- `007.002-readmes-stubs-and-public-docs-path-contracts.md`
- `007.003-path-fixtures-tests-and-contract-cleanup.md`

If `009-correct-feature-docs-layout.md` has already been implemented, treat its corrected `docs/features/<feature>/...` layout as canonical. Do not move feature folders back to any intermediate layout.

## Canonical Path Contract

Active execution specs:

```text
docs/features/<feature>/specs/<id>-<slug>.md
```

Draft execution specs:

```text
docs/features/<feature>/specs/drafts/<id>-<slug>.md
```

Implementation plans:

```text
docs/features/<feature>/plans/<id>-<slug>.md
```

The plan filename stem must match the corresponding active execution spec filename stem exactly.

Example:

```text
docs/features/execution-spec-system/specs/008-implementation-plan-files.md
docs/features/execution-spec-system/plans/008-implementation-plan-files.md
```

## Goals

1. Introduce canonical implementation plan files.
2. Add deterministic tooling to create plan drafts from active execution specs.
3. Validate plan placement, naming, heading, and spec correspondence.
4. Add an explicit strict validation mode that requires plans for active execution specs.
5. Update agent and human guidance so agents do not treat chat-only plans as sufficient.

## Non-Goals

- Do not replace execution specs.
- Do not make plans authoritative over requirements.
- Do not allow plans to expand, shrink, or alter execution-spec scope.
- Do not introduce hidden metadata.
- Do not require plans for completed historical specs by default.
- Do not create nested per-spec directories.
- Do not create fallback support for old docs paths.
- Do not move feature context files.

## Plan Contract

A plan file must:

- live at `docs/features/<feature>/plans/<id>-<slug>.md`
- use the same `<id>-<slug>` filename stem as the corresponding active execution spec
- have the heading `# Implementation Plan: <id>-<slug>`
- be deterministic and reviewable
- describe implementation strategy only
- not alter requirements from the execution spec
- not contain internal `id`, `parent`, or `status` metadata fields

## Required Plan Template

Create a stub/template for new plan files with this structure:

```md
# Implementation Plan: <id>-<slug>

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
php bin/foundry spec:validate --require-plans --json
php bin/foundry verify context --json
php bin/foundry verify contracts --json
php bin/foundry verify graph --json
php bin/foundry verify pipeline --json
php vendor/bin/phpunit
php -d xdebug.mode=coverage vendor/bin/phpunit --coverage-text
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
4. Preserve the exact execution spec filename stem.
5. Create the feature-local `plans/` directory if it does not exist.
6. Refuse to overwrite an existing plan unless an explicit `--force` option is implemented.
7. If `--force` is implemented, it must be deterministic and must be tested.
8. Return deterministic plain-text output.
9. Return deterministic JSON output when `--json` is passed.
10. Return non-zero deterministic failures for missing feature, missing active spec, ambiguous ID matches, existing plan without force, invalid path, or filesystem write failure.

## `spec:plan --json` Output

Successful creation must include at least:

```json
{
  "status": "created",
  "feature": "<feature>",
  "spec": "docs/features/<feature>/specs/<id>-<slug>.md",
  "plan": "docs/features/<feature>/plans/<id>-<slug>.md"
}
```

If the plan already exists and `--force` is not used, return a deterministic non-zero response including:

```json
{
  "status": "error",
  "error": "plan_already_exists",
  "feature": "<feature>",
  "plan": "docs/features/<feature>/plans/<id>-<slug>.md"
}
```

## Validation Rules

Update `spec:validate` so it validates plan files.

It must detect and report:

- plan filename does not match any active execution spec filename in the same feature
- plan heading does not match `# Implementation Plan: <id>-<slug>`
- plan exists outside `docs/features/<feature>/plans/`
- duplicate plan IDs within a feature
- forbidden internal metadata fields
- active execution spec missing a required plan when strict plan enforcement is enabled
- stale plan paths from old or intermediate layouts

## Enforcement Mode

Add a deterministic strict mode:

```bash
php bin/foundry spec:validate --require-plans --json
```

Default validation must validate existing plan files but must not require every active spec to have a plan. This avoids forcing immediate backfill for completed historical specs.

`--require-plans` must require plans only for active execution specs. It must not require plans for draft execution specs.

## Agent Guidance Updates

Update `AGENTS.md`, `APP-AGENTS.md`, `README.md`, `APP-README.md`, `docs/features/README.md`, and relevant stubs to state:

- execution specs define what must be built
- implementation plans define how an execution spec will be implemented
- plans must be saved before implementation begins for new active execution specs
- chat-only plans are not sufficient
- plans must not expand or alter execution-spec scope
- after implementation, agents must update `docs/features/implementation-log.md` as usual
- plan files live at `docs/features/<feature>/plans/<id>-<slug>.md`

## Tests

Add or update tests proving:

1. `spec:plan <feature> <id>` creates `docs/features/<feature>/plans/<id>-<slug>.md`.
2. The generated plan heading is `# Implementation Plan: <id>-<slug>`.
3. The generated plan uses the required sections.
4. The command creates the `plans/` directory when missing.
5. The command refuses to overwrite existing plans without explicit force.
6. `spec:plan --json` returns deterministic JSON.
7. Missing feature and missing active spec failures are deterministic.
8. Ambiguous ID matches fail deterministically if applicable.
9. `spec:validate` accepts valid plan files.
10. `spec:validate` rejects orphan plans.
11. `spec:validate` rejects plans with mismatched headings.
12. `spec:validate` rejects plans in old or invalid locations.
13. `spec:validate --require-plans --json` fails when an active execution spec lacks a plan.
14. `spec:validate --require-plans --json` does not require plans for draft specs.
15. Default validation without `--require-plans` remains deterministic and does not require historical backfill.

## Acceptance Criteria

- Plans are stored at `docs/features/<feature>/plans/<id>-<slug>.md`.
- Plan filenames match active execution spec filenames exactly.
- Plan headings follow `# Implementation Plan: <id>-<slug>`.
- `spec:plan` creates plans deterministically.
- `spec:plan --json` emits deterministic JSON.
- `spec:validate` validates existing plan files.
- `spec:validate --require-plans` enforces missing-plan failures for active specs.
- Agent and human docs describe the plan-before-implementation rule.
- No docs or tests describe plans under old or intermediate paths.
- Tests cover command behavior, validation behavior, and deterministic output.
- Test coverage for changed and added code meets the project threshold.

## Required Verification

Run and pass:

```bash
php bin/foundry spec:validate --json
php bin/foundry spec:validate --require-plans --json
php bin/foundry verify context --json
php bin/foundry verify contracts --json
php bin/foundry verify graph --json
php bin/foundry verify pipeline --json
php vendor/bin/phpunit
php -d xdebug.mode=coverage vendor/bin/phpunit --coverage-text
```

## Implementation Log

Append the required completion entry to `docs/features/implementation-log.md` only after all verification commands pass.
