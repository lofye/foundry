# Foundry Framework Contributor Guide

Use this file when working in the Foundry framework repository itself.

------------------------------------------------------------------------

## Execution Policies

### Reasoning Policy

Agents MUST load and follow:

-   docs/policies/codex-reasoning-policy.md

This policy governs: - reasoning level selection - phase-based reasoning
adjustments - performance vs depth tradeoffs

This policy is mandatory for all implementation workflows.

### Execution Requirements

Before executing any spec or feature implementation, agents MUST:

1.  Load docs/policies/codex-reasoning-policy.md
2.  Apply the reasoning level defined for the current phase
3.  Adjust reasoning dynamically as phases change

Failure to follow this policy invalidates the implementation.

------------------------------------------------------------------------

## Philosophy

Foundry is an LLM-first web framework that competes with human-first
frameworks like Laravel.

The philosophy behind the Foundry Framework is in:

docs/philosophy/foundry-philosophy.md

If you have not read it during this session, read it before proceeding.

------------------------------------------------------------------------

## Scope

This repository owns framework internals:

-   runtime and compiler code in `src/*`
-   CLI commands in `src/CLI/*`
-   documentation in `README.md`, `docs/*`, and `examples/*`
-   app scaffolding in `src/CLI/Commands/InitAppCommand.php`
-   stub templates in `stubs/*`

The root `app/*` tree is a framework-owned demo and smoke app.

-   `app/features/*` = source of truth
-   `app/generated/*` = generated output

------------------------------------------------------------------------

## Command Rule

-   In this repository, use: `php bin/foundry ...`
-   In generated apps, use: `foundry ...`
-   Prefer `--json` when output is consumed by agents

------------------------------------------------------------------------

## Source Of Truth

-   `src/*` → framework behavior
-   `tests/*` → expected behavior
-   `docs/features/*` → feature intent, state, reasoning
-   `docs/specs/*` → execution planning (non-authoritative after
    implementation)
-   `README.md` → contributor + onboarding guidance
-   `APP-AGENTS.md`, `APP-README.md` → scaffold defaults
-   `stubs/*` → generator templates only when used

Do NOT:

-   edit `app/generated/*` manually
-   patch generated output to make tests pass

------------------------------------------------------------------------

## Safe Edit Loop

1.  Inspect relevant code, command, or service.
2.  If feature work, read spec, state, decisions.
3.  Make the smallest possible change.
4.  Recompile if needed.
5.  Run focused tests first.
6.  Run full verification before finishing.

------------------------------------------------------------------------

## SPEC DISCIPLINE RULE

Specs are contracts.

If behavior changes:

1.  update spec
2.  implement
3.  realign examples
4.  verify

Never allow: - docs drift - implementation drift

------------------------------------------------------------------------

# Context Anchoring (MANDATORY)

Foundry uses feature-level context anchoring.

Canonical files:

-   docs/features/`<feature>`{=html}.spec.md (intent)
-   docs/features/`<feature>`{=html}.md (state)
-   docs/features/`<feature>`{=html}.decisions.md (history)

Execution specs (docs/specs/\*) are: - planning artifacts - optional -
NON-authoritative after implementation
