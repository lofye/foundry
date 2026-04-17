# Foundry Framework

Foundry is a production-minded, explicit, deterministic, LLM-first PHP framework for building feature-local web apps.
Visit [FoundryFramework.org](https://foundryframework.org) for extensive documentation.

Core Foundry remains MIT-licensed and fully usable without restriction.
Explain, generate, diagnostics, trace analysis, and graph diffing remain available without a license.
The monetization system is opt-in, local-first, and isolated from core compile, inspect, verify, scaffold, and runtime flows.

It is optimized for:
- explicit contracts
- deterministic generation
- machine-readable inspection
- small safe edit surfaces
- strong verification and testing

## Getting Started

Run:

```bash
foundry
```

Foundry behaves deterministically:

- in an empty directory, it offers curated onboarding examples
- in an existing Foundry project, it inspects the current project
- `foundry explain` with no target explains the first feature or route deterministically

For meaningful feature work, canonical context lives in:

- `docs/features/<feature>.spec.md`
- `docs/features/<feature>.md`
- `docs/features/<feature>.decisions.md`

Execution specs under `docs/specs/*` are optional planning artifacts only and are never authoritative once canonical feature context exists.

Use `foundry verify context --feature=<feature> --json` as the primary machine-readable proceed/fail gate. If canonical context is missing, create it first with `foundry context init <feature> --json`. If context verification fails, repair context before implementation.

## Shell Completion

Foundry can emit deterministic completion scripts for bash and zsh:

```bash
foundry completion bash
foundry completion zsh
```

Static completion comes from the registered CLI surface, so command and subcommand suggestions stay aligned with `help --json` and CLI surface verification.

When completing `foundry implement spec <feature> <id>`, feature names come from `docs/specs/` and execution-spec ids come from active specs under `docs/specs/<feature>/`. Draft specs are excluded by default.

## Install And First Run (Packagist)

```bash
composer require lofye/foundry-framework
foundry

# or, for automation:
foundry init --example=blog-api
foundry new my-foundry-app --starter=standard --json
cd my-foundry-app
composer install

foundry
foundry explain --json
foundry compile graph --json
foundry inspect graph --json
foundry inspect pipeline --json
foundry doctor --json
foundry verify graph --json
foundry verify pipeline --json
foundry verify contracts --json
php -S 127.0.0.1:8000 public/index.php
```

## Core Workflow for LLMs

1. Read the canonical feature spec, state document, and decision ledger.
2. Run `foundry verify context --feature=<feature> --json`.
3. Repair context first if verification fails.
4. Make the smallest necessary source-of-truth changes.
5. Re-run verification and tests.

## Reference Pointers

For deeper architecture walkthroughs, use `foundry explain <target> --deep --markdown --json`.
The explain system composes `ExplainContribution` sections through the contributor registry and related docs.

Browse runnable examples in `docs/example-applications.md` and `examples/README.md`.

Use `AGENTS.md` for the full contributor and agent workflow.
