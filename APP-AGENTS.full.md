# Foundry App Agent Guide

Use this file when working inside a Foundry application repository.

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

## Command Rule

-   In Foundry app repos, prefer `foundry ...`
-   If your shell does not resolve current-directory executables, use
    `./foundry ...`
-   Prefer `--json` for inspect, verify, doctor, prompt, export, and
    generation commands when an agent is consuming the output

------------------------------------------------------------------------

## Source Of Truth

-   Treat `app/features/*` as source-of-truth application behavior
-   Treat `docs/features/*` as feature intent/state/decisions
-   Execution specs are planning artifacts only

------------------------------------------------------------------------

## Safe Edit Loop

1.  Read spec, state, decisions
2.  Inspect reality
3.  Make smallest change
4.  Compile + verify
5.  Run PHPUnit

------------------------------------------------------------------------

## Guard Rails

-   Never fake test passes
-   Keep coverage ≥ 90%
