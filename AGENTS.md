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
- `docs/specs/*` → execution planning (non-authoritative after implementation)
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
- IDs use dot-separated 3-digit segments (e.g. 015.001.002)
- IDs are immutable
- IDs must be unique within a feature (NOT globally)
- Slugs are NOT required to be unique
- Drafts live in docs/specs/<feature-name>/drafts/
- Active executable specs live in docs/specs/<feature-name>/
- The spec heading must mirror the filename only

Agents MUST:
- treat ID as the ONLY identity key
- NOT enforce slug uniqueness
- ensure no duplicate IDs exist within a feature
- infer hierarchy from ID segments only
- NOT rename existing IDs
- NOT add metadata fields like id, parent, or status
- append implementation logs only for completed active specs

Violation of any rule above is considered an incorrect implementation.

---

# Context Anchoring (MANDATORY)

Foundry uses feature-level context anchoring.

Canonical files:

- docs/features/<feature>.spec.md   (intent)
- docs/features/<feature>.md        (state)
- docs/features/<feature>.decisions.md (history)

Execution specs (docs/specs/*) are:
- planning artifacts
- optional
- NON-authoritative after implementation

---

## Source-of-Truth Hierarchy

1. spec (intent)
2. state (reality)
3. decisions (why)
4. code/tests (enforced behavior)

Execution specs NEVER override feature specs.

---

## Read Before Acting

Before ANY non-trivial work:

1. read feature spec
2. read state file
3. read decisions log
4. run context commands

Do NOT rely on:
- chat history
- assumptions
- stale mental models

---

## Execution Gate (CRITICAL)

```bash
php bin/foundry verify context --feature=<feature-name> --json
```

Rules:

- pass → proceed
- fail → HARD STOP

Derived:
- can_proceed=false → STOP
- requires_repair=true → repair only

---

## Refuse-to-Proceed Rule

STOP immediately if:

- context is invalid
- alignment mismatch exists
- required files missing
- spec/state/implementation diverge

---

## Allowed Actions While Blocked

ONLY:
- fix context
- update state
- log decisions
- repair spec

NEVER:
- implement behavior
- modify runtime logic

---

## Repair-First Workflow

1. stop
2. explain issue
3. list fixes
4. repair
5. re-run verification

---

## Spec Rules

Per feature:
- exactly ONE canonical spec file
- no versioned filenames

Spec reflects CURRENT intent only.

---

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

---

## State Document Rules

Must include:
- current state
- open questions
- next steps

Must always reflect reality.

---

## Alignment Rules

You MUST detect:
- spec vs state mismatch
- spec vs code mismatch
- state vs code mismatch

Resolve via:
- implementation change OR
- spec update + decision log

---

## Enforcement

System is NON-COMPLIANT if:
- context invalid
- decisions missing
- state outdated
- mismatches unresolved

---

## Final Rule

If non-compliant:

- STOP
- explain
- repair first

---

## Guiding Principle

Intent must survive.

State must reflect reality.

Decisions must preserve continuity.

Any agent must resume work with ZERO prior context.
