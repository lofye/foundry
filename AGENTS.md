# Foundry Framework Contributor Guide

Use this file when working in the Foundry framework repository itself.

When a developer creates an application using Foundry, delete `AGENTS.md` and rename `APP-AGENTS.md` to `AGENTS.md`, then delete `README.md` and rename `APP-README.md` to `README.md`.

---

## Execution Policies

### Reasoning Policy

Before any non-trivial work, load:

`docs/policies/codex-reasoning-policy.md`

Requirements:

- follow the Codex reasoning policy for the current task
- use the lowest reasoning level likely to succeed reliably
- start at medium for bounded deterministic work
- use high for multi-file, core-workflow, or non-trivial debugging work
- use extra high only for architecture, hard root-cause analysis, invariant discovery, or repeated-failure investigation
- if a different reasoning level is needed, stop and start a new run at that new level

### Execution Requirements

You must load `docs/policies/codex-reasoning-policy.md` before:

- meaningful implementation
- spec revision
- context repair
- stabilization
- root-cause debugging
- architecture work

Execution rules:

- keep reasoning proportional to authority, risk, and determinism
- prefer iterative escalation over defaulting to maximum reasoning
- when specs, commands, or validation rules tightly constrain the task, follow them rather than improvising
- do not treat the reasoning policy as optional guidance

### Command Execution Permission

Agents MAY run non-destructive shell commands without asking for confirmation when the command is needed to inspect, test, validate, or measure the repository.

Allowed without confirmation:
- reading files
- listing directories
- searching the repository
- running tests
- running coverage
- running Foundry CLI validation/inspection commands
- generating local reports under ignored/temp paths
- modifying files inside the repository as part of the active implementation task
- deleting or recreating temporary/build/cache artifacts inside the repository

Agents MUST NOT pause for confirmation before running commands that are:
- repository-local
- non-destructive outside the repository
- necessary to complete the requested task

Agents MUST ask for confirmation before:
- deleting files outside the repository
- modifying global/system/user configuration
- installing system packages
- accessing credentials or secrets
- making network calls unless explicitly required by the task
- publishing, deploying, tagging, releasing, or pushing changes
- running destructive commands outside the repository

If unsure, prefer the safest repository-local command that gathers more information rather than pausing.

---

## Philosophy

Foundry is an LLM-first web framework that competes with human-first frameworks like Laravel.

The philosophy behind the Foundry Framework is in:

`docs/philosophy/foundry-philosophy.md`

If you have not read it during this session, read it before proceeding.

---

## Scope

This repository owns framework internals:

- runtime and compiler code in `src/*`
- CLI commands in `src/CLI/*`
- documentation in `README.md`, `docs/*`, and `examples/*`
- app scaffolding in `src/CLI/Commands/InitAppCommand.php`
- stub templates in `stubs/*`

The root `app/*` tree is a framework-owned demo and smoke app.

- `app/features/*` = source of truth
- `app/generated/*` = generated output

---

## Command Rule

- In this repository, use: `php bin/foundry ...`
- In generated apps, use: `foundry ...`
- Prefer `--json` when output is consumed by agents

---

## Source Of Truth

- `src/*` → framework behavior
- `tests/*` → expected behavior
- `docs/features/*` → feature intent, state, reasoning
- `docs/specs/*` → execution planning (non-authoritative after implementation)
- `docs/policies/*` → repository execution and reasoning policies
- `README.md` → contributor + onboarding guidance
- `APP-AGENTS.md`, `APP-README.md` → scaffold defaults
- `src/CLI/Commands/InitAppCommand.php` → scaffold promotion behavior
- `stubs/*` → generator templates only when used

Do NOT:

- edit `app/generated/*` manually
- patch generated output to make tests pass

---

## Workflow Reference

For the full contributor workflow, follow the checklist in `README.md`.

Do not skip:

- context validation
- alignment checking
- refusal handling
- state updates
- decision logging
- quality-gate verification before claiming implementation completion

---

## Safe Edit Loop

Do not block implementation progress by asking for permission to run safe repository-local inspection, test, validation, or coverage commands. Run them, capture the result, and continue.

1. Inspect the relevant code, command, or service.
2. If the task is meaningful feature work, read the feature spec, state document, and decision ledger, then verify context before changing behavior.
3. Make the smallest possible change in source-of-truth files.
4. Recompile if needed.
5. Run focused tests first while iterating.
6. Run broader verification before finishing.
7. If implementation completion is being claimed, run the full quality gate before reporting success.

Common command loop:

```bash
php bin/foundry compile graph --json
php bin/foundry inspect graph --json
php bin/foundry inspect pipeline --json
php bin/foundry verify graph --json
php bin/foundry verify pipeline --json
php bin/foundry verify contracts --json
php vendor/bin/phpunit
```

Feature-focused loop:

```bash
php bin/foundry context doctor --feature=<feature-name> --json
php bin/foundry context check-alignment --feature=<feature-name> --json
php bin/foundry inspect context <feature-name> --json
php bin/foundry verify context --feature=<feature-name> --json
php bin/foundry inspect feature <feature-name> --json
php bin/foundry inspect impact --file=<path> --json
```

Completion quality gate:

```bash
php vendor/bin/phpunit
php -d xdebug.mode=coverage vendor/bin/phpunit --coverage-text
```

---

## Framework Change Rules

- Keep framework changes minimal and explicit
- Preserve deterministic CLI and JSON output shapes unless the task explicitly changes them
- Update tests alongside behavior changes
- Keep scaffold docs, promotion logic, and init-app tests aligned
- Update framework and scaffold onboarding docs together when workflows change
- Do not introduce duplicate logic paths
- Do not add app-specific policy to framework internals unless it is meant to be scaffolded into every app
- Preserve git history where possible
- Renderers must consume assembled plan data rather than reaching into raw graph, compiler, or runtime state

---

## Scaffold Doc Sync

- `APP-AGENTS.md` and `APP-README.md` are the canonical app-facing templates
- Generated apps must end with `AGENTS.md` and `README.md`
- If scaffold behavior changes, update template files, promotion logic, and init-app tests together
- Do not update one onboarding surface in isolation when matching guidance elsewhere becomes stale

---

## Frozen Contracts

Once a documented contract has been implemented and aligned:

- treat it as stable
- do not casually rewrite behavior or examples
- update the contract docs before implementation when behavior must change
- realign examples after implementation changes
- keep user-facing output deterministic

Release expectations:

- patch = bug fixes only
- minor = additive and backward compatible
- breaking changes = major-version planning

Stable outputs must not depend on timestamps, randomness, or unstable ordering.

---

## Testing Discipline

- Every framework behavior change needs PHPUnit coverage
- Prefer focused test runs while iterating, then finish with the broader relevant suite
- The full PHPUnit suite must run before implementation completion is reported as final
- Coverage must run before implementation completion is reported as final
- The canonical completion quality gate is:

```bash
php vendor/bin/phpunit
php -d xdebug.mode=coverage vendor/bin/phpunit --coverage-text
```

- Implementations are not complete unless the quality gate passes
- Global test coverage of lines must be at or above 90% for final implementation completion
- If the full suite fails, the coverage run fails, coverage output is missing or unparseable, or coverage is below threshold, do not report final success
- Do not weaken assertions, delete failing coverage, or edit generated output to hide regressions
- When changing CLI scaffolding or textual contracts, assert generated files and key content in integration tests
- When a bug is encountered, create a failing test first, then change the non-test code so that the test passes

---

## Ask First

Stop and ask before:

- changing package names, Composer constraints, or public command names without explicit direction
- making breaking changes to scaffolded app structure or generated file conventions
- changing verification semantics in ways that could invalidate existing apps without a migration path
- making a behavior choice when the existing docs, tests, and code disagree

---

## SPEC DISCIPLINE RULE

Specs are contracts.

If behavior changes:

1. update spec
2. implement
3. realign examples
4. verify

Never allow:

- docs drift
- implementation drift

---

## Spec Naming (MANDATORY)

All specs must follow the canonical naming convention defined in:

`docs/specs/README.md`

Key rules:

- filenames are the spec identity
- format: `<id>-<slug>.md`
- IDs use dot-separated 3-digit segments (e.g. `015.001.002`)
- IDs are immutable
- IDs must be unique within a feature, not globally
- slugs are not required to be unique
- drafts live in `docs/specs/<feature-name>/drafts/`
- Draft specs are non-executable planning artifacts
- Agents MUST NOT implement specs from `docs/specs/<feature>/drafts/`
- If asked to implement a draft spec, refuse and require promotion to the active spec path first
- active executable specs live in `docs/specs/<feature-name>/`
- the spec heading must mirror the filename only

Agents MUST:

- treat the ID as the only identity key
- not enforce slug uniqueness
- ensure no duplicate IDs exist within a feature
- infer hierarchy from ID segments only
- not rename existing IDs
- not add metadata fields like `id`, `parent`, or `status`
- append implementation logs only for completed active specs

Violation of any rule above is considered an incorrect implementation.

---

## Repo Skills

Use repository-local skills from `.skills/` before falling back to installed skills.

Example:
- `.skills/implement-spec-and-stabilize-strict.skill.md`

---

## Docs Surfaces

- Treat framework `docs/*` as authored canonical framework documentation unless a path is explicitly marked generated or imported
- Do not manually edit imported or generated docs when the source of truth lives elsewhere
- Before moving or deleting docs, audit the build or publishing path that consumes them

---

# Context Anchoring (MANDATORY)

Foundry uses feature-level context anchoring.

Canonical files:

- `docs/features/<feature>.spec.md` = intent
- `docs/features/<feature>.md` = state
- `docs/features/<feature>.decisions.md` = history

Execution specs under `docs/specs/*` are:

- planning artifacts
- optional
- non-authoritative after implementation

## Source-of-Truth Hierarchy

1. spec (intent)
2. state (reality)
3. decisions (why)
4. code/tests (enforced behavior)

Execution specs never override feature specs.

## Read Before Acting

Before any non-trivial feature work:

1. read the feature spec
2. read the state file
3. read the decisions log
4. run context commands

Do not rely on:

- chat history
- assumptions
- stale mental models

## Execution Gate (CRITICAL)

```bash
php bin/foundry verify context --feature=<feature-name> --json
```

Rules:

- `pass` → proceed
- `fail` → hard stop

Derived signals:

- `can_proceed=true` → meaningful work may proceed
- `can_proceed=false` → stop
- `requires_repair=true` → repair only

Equivalent conclusion when `verify context` is not run directly:

- doctor status is `ok` or `warning`
- alignment status is `ok` or `warning`

## Refuse-to-Proceed Rule

Stop immediately if:

- `verify context` fails
- doctor status is `repairable` or `non_compliant`
- alignment status is `mismatch`
- required files are missing
- spec/state/code divergence is unresolved
- the execution spec being implemented is in a draft execution spec path
- draft specs must be promoted to the active spec directory before implementation

## Allowed Actions While Blocked

Only:

- run `php bin/foundry context init <feature> --json`
- run `php bin/foundry context repair --feature=<feature> --json`
- fix or normalize context files
- update state
- log decisions
- update spec with corresponding decision logging

Never:

- implement behavior
- modify runtime logic

## Repair-First Workflow

1. stop
2. explain the issue
3. list the required fixes
4. repair
5. re-run verification

## Spec Rules

Per feature:

- exactly one canonical spec file
- no versioned filenames

Spec reflects current intent only.

## Decision Ledger Rules

- append-only
- never edit
- never delete
- never summarize

Each entry must include:

- context
- decision
- reasoning
- alternatives
- impact

## State Document Rules

Must include:

- current state
- open questions
- next steps

Must always reflect reality.

## Alignment Rules

You must detect:

- spec vs state mismatch
- spec vs code mismatch
- state vs code mismatch

Resolve via:

- implementation change
- spec update + decision log

## Enforcement

The system is non-compliant if:

- context is invalid
- decisions are missing
- state is outdated
- mismatches are unresolved

## Final Rule

If non-compliant:

- stop
- explain
- repair first

## Guiding Principle

Intent must survive.

State must reflect reality.

Decisions must preserve continuity.

Any agent must be able to resume work with zero prior context.
