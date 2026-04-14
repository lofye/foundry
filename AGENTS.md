# Foundry Framework Contributor Guide

Use this file when working in the Foundry framework repository itself.

When a developer creates an application using Foundry, the `APP-AGENTS.md` file's contents will overwrite `AGENTS.md`, and the `APP-README.md` file's contents will overwrite `README.md`.

---

## Philosophy

Foundry is an LLM-first web framework that competes with human-first frameworks like Laravel.

The philosophy behind the Foundry Framework is in:

docs/philosophy/foundry-philosophy.md

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
- `README.md` → contributor + onboarding guidance
- `APP-AGENTS.md`, `APP-README.md` → scaffold defaults
- `stubs/*` → generator templates only when used

Do NOT:

- edit `app/generated/*` manually
- patch generated output to make tests pass

---

## Workflow Reference

For full context-driven workflow, follow the checklist in `README.md`.

Do not skip:

- context validation
- alignment checking
- refusal handling
- state updates
- decision logging

---

## Safe Edit Loop

1. Inspect relevant code, command, or service.
2. If feature work, read spec, state, decisions.
3. Make the smallest possible change.
4. Recompile if needed.
5. Run focused tests first.
6. Run full verification before finishing.

### Common loop

```bash
php bin/foundry compile graph --json
php bin/foundry inspect graph --json
php bin/foundry verify graph --json
php bin/foundry verify pipeline --json
php bin/foundry verify contracts --json
vendor/bin/phpunit
```

### Feature-focused loop

```bash
php bin/foundry context doctor --feature=<feature-name> --json
php bin/foundry context check-alignment --feature=<feature-name> --json
php bin/foundry inspect context <feature-name> --json
php bin/foundry verify context --feature=<feature-name> --json
php bin/foundry inspect feature <feature-name> --json
php bin/foundry inspect impact --file=<path> --json
```

---

## Change Rules

- Keep changes minimal and explicit
- Preserve deterministic output shapes
- Update tests alongside behavior changes
- Keep scaffold docs (`APP-*`) in sync
- Update all onboarding docs together when workflows change
- Do not introduce duplicate logic paths
- Preserve git history where possible

---

## Scaffold Doc Sync

- `APP-AGENTS.md` and `APP-README.md` define app defaults
- Generated apps must end with `AGENTS.md` and `README.md`
- If scaffold behavior changes, update:
    - templates
    - promotion logic
    - tests

Never update onboarding docs in isolation.

---

## Frozen Contracts

Once implemented and aligned:

- Treat contracts as stable
- Do not casually rewrite behavior or examples

Change order:

1. update spec
2. implement
3. realign examples
4. verify determinism

Rules:

- patch = bugfix only
- minor = additive, backward compatible
- breaking = requires major plan

Outputs must remain deterministic:
- no timestamps
- no randomness
- stable ordering

---

## Testing Discipline

- Every change requires PHPUnit coverage
- Prefer narrow tests → then full suite
- Never weaken assertions
- Never hide regressions
- Add regression tests for bugs
- Above 90% test coverage MUST be maintained at all times

---

## Ask First

Stop before:

- changing public commands
- altering scaffold structure
- changing verification semantics
- resolving unclear conflicts between docs/tests/code

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

docs/specs/README.md

Key rules:
- Filenames are the spec identity.
- Format: <id>-<slug>.md
- IDs use dot-separated 3-digit segments (for example: 015.001.002)
- IDs are immutable
- Drafts live in docs/specs/<feature-name>/drafts/
- Active executable specs live in docs/specs/<feature-name>/
- The spec heading must mirror the filename only, not the feature path

Agents MUST:
- place each spec in the correct feature directory
- treat filename and path as the only required identity/state metadata
- infer hierarchy from the numeric ID
- append a correctly formatted entry to docs/specs/implementation-log.md immediately after completing an active execution spec implementation
- NOT rename existing spec IDs
- use only padded numeric segments (3 digits per segment)
- NOT add metadata fields like id, parent, or status inside spec files
- NOT include <feature-name>/ in the spec heading
- NOT append implementation-log entries for any specs that have not been implemented (especially draft specs)

Violation of any rule above is considered an incorrect implementation.

---

# Context Anchoring

Foundry uses feature-level context anchoring.

Canonical files:

- `docs/features/<feature-name>.spec.md`
- `docs/features/<feature-name>.md`
- `docs/features/<feature-name>.decisions.md`

Execution specs under `docs/specs/*` are:

- optional
- non-authoritative after implementation

---

## Source-of-Truth Boundaries

- spec → intent
- state → current reality
- decisions → reasoning history
- code/tests → behavior

---

## Read Before Acting

Before meaningful work:

1. read spec
2. read state
3. read decisions
4. run context commands

Do NOT rely on chat history.

---

## Execution Gate (CRITICAL)

Primary gate:

```bash
php bin/foundry verify context --feature=<feature-name> --json
```

Rules:

- `status=pass` → work may proceed
- `status=fail` → work is blocked

Derived signals:

- `can_proceed=true` → proceed allowed
- `can_proceed=false` → HARD STOP
- `requires_repair=true` → repair only

Equivalent condition:

- doctor = ok|warning
- alignment = ok|warning

---

## Refuse-to-Proceed Rule

You MUST stop when:

- doctor = repairable | non_compliant
- alignment = mismatch
- required files missing
- required sections missing
- mismatch without decision support

---

## Allowed Actions While Blocked

Only:

- create missing files
- repair malformed files
- update state
- append decisions
- update spec (with decision log)

No implementation allowed.

---

## Repair-First Workflow

When blocked:

1. stop
2. explain issue
2. list required actions
3. repair

After implementation:

- update state
- log decisions
- re-run verification

---

## Spec Rules

Each feature:

- MUST have exactly one spec file
- MUST NOT have versioned filenames

### Optional Spec Version Section

Specs MAY include:

## Spec Version

But it is optional and must NOT be enforced by tooling.

---

## Spec Evolution

When intent changes:

1. update spec
2. log decision
3. update state

Spec always reflects current intent.

---

## Decision Ledger Rules

- append-only
- never edit
- never summarize
- never delete

Each entry must include:

- context
- decision
- reasoning
- alternatives
- impact

---

## State Document Rules

Must include:

- current state
- open questions
- next steps

Must always reflect latest reality.

---

## Alignment Rules

You MUST detect and resolve:

- spec vs state mismatch
- spec vs implementation mismatch
- state vs implementation mismatch

Resolve via:

- implementation change
- spec update + decision
- decision logging

---

## Enforcement

System is NON-COMPLIANT if:

- required files missing
- decisions not logged
- state outdated
- mismatch unresolved
- work proceeds without validation

---

## Final Rule

If non-compliant:

- STOP
- explain why
- list required actions
- repair first

Never proceed in a broken state.

---

## Guiding Principle

Intent must survive.

Every decision must be recorded.

Any agent must be able to resume work with zero prior conversation.
